<?php

/**
 * ============================================================================
 * FAR-CONTROL: Secure Multi-Agent & Orchestrated CLI Telegram Bridge
 * ============================================================================
 * Ponte de comunicação bidirecional de nível empresarial para controle remoto
 * de múltiplos agentes de IA (Antigravity CLI, OpenCode) via Telegram.
 *
 * Recursos Avançados:
 * - Orquestração de Dificuldade (NVIDIA Build / Fast LLM Router)
 * - Cadeia de Fallbacks para Transcrição de Áudio (Groq Turbo, Groq v3, OpenRouter)
 * - Pré-processamento e normalização de áudio via FFmpeg
 * - Execução direta de comandos nativos Linux (Zero Tokens)
 * - Autenticação 2FA por PIN de Sessão com Auto-Lock por inatividade
 * - Chroot Lógico de Workspace (Jail anti-Path Traversal)
 * - Data Loss Prevention (DLP): Redação automática de segredos nos logs
 *
 * @author   Engenharia de Software & Automação
 * @license  MIT
 * ============================================================================
 */

declare(strict_types=1);

set_time_limit(0);
ob_implicit_flush(true);

// ============================================================================
// CARREGAMENTO SEGURO DE VARIÁVEIS DE AMBIENTE (.env)
// ============================================================================

$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $envLines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            putenv(trim($k) . '=' . trim($v, " \t\n\r\0\x0B\"'"));
        }
    }
}

// ============================================================================
// CONTRATOS E DRIVERS DE AGENTES (ARQUITETURA PLUGGABLE)
// ============================================================================

interface AgentDriverInterface
{
    public function getName(): string;
    public function buildCommand(string $prompt, bool $continue, ?string $model): string;
    public function getRecommendedModels(): array;
}

class AgyDriver implements AgentDriverInterface
{
    public function getName(): string
    {
        return "Antigravity CLI (agy)";
    }

    public function buildCommand(string $prompt, bool $continue, ?string $model): string
    {
        // Anexa diretriz estrita do AI.md para garantir concisão, KISS e Clean Code
        $directive = " [DIRETRIZ: Siga estritamente o AI.md. Seja ultra conciso, KISS, YAGNI, Clean Code e SOLID. Vá direto ao ponto, sem preâmbulos.]";
        $escapedPrompt = escapeshellarg($prompt . $directive);
        $continueFlag = $continue ? '-c ' : '';
        $modelFlag = (!empty($model) && $model !== 'default') ? '--model ' . escapeshellarg($model) . ' ' : '';

        return "agy -p {$escapedPrompt} {$continueFlag}{$modelFlag}--dangerously-skip-permissions 2>&1";
    }

    public function getRecommendedModels(): array
    {
        return [
            '🔄 Padrão do Sistema (Gemini)' => 'default',
        ];
    }
}

class OpenCodeDriver implements AgentDriverInterface
{
    private string $binaryPath;

    public function __construct()
    {
        $home = getenv('HOME') ?: '/home/' . (getenv('USER') ?: '');
        $candidates = [
            'opencode',
            $home . '/.opencode/bin/opencode',
            '/usr/local/bin/opencode',
        ];

        $this->binaryPath = 'opencode';
        foreach ($candidates as $cand) {
            if ($cand === 'opencode' || file_exists($cand)) {
                $this->binaryPath = $cand;
                break;
            }
        }
    }

    public function getName(): string
    {
        return "OpenCode CLI";
    }

    public function buildCommand(string $prompt, bool $continue, ?string $model): string
    {
        // Anexa diretriz estrita do AI.md
        $directive = " [DIRETRIZ: Siga o AI.md. Seja conciso, KISS, Clean Code, SOLID. Entregue direto a solução.]";
        $escapedPrompt = escapeshellarg($prompt . $directive);
        $continueFlag = $continue ? '-c ' : '';
        $modelFlag = (!empty($model) && $model !== 'default') ? '-m ' . escapeshellarg($model) . ' ' : '';

        return "{$this->binaryPath} run {$escapedPrompt} {$continueFlag}{$modelFlag}--auto 2>&1";
    }

    public function getRecommendedModels(): array
    {
        return [
            '⚡ Gemini 2.5 Flash'     => 'google/gemini-2.5-flash',
            '⚡ Gemini 3 Flash'       => 'google/gemini-3-flash-preview',
            '🧠 GPT-6 Luna'           => 'openai/gpt-6-luna',
            '🧠 GPT-5.4'              => 'openai/gpt-5.4',
            '🆓 DeepSeek Flash Free'   => 'opencode/deepseek-v4.1-flash-free',
            '🆓 Crouton Alpha Free'    => 'opencode/crouton-alpha-free',
            '🔄 Padrão do OpenCode'   => 'default',
        ];
    }
}

class NvidiaDriver implements AgentDriverInterface
{
    public function getName(): string
    {
        return "NVIDIA Build NIM (Llama 3.2 90B Vision)";
    }

    public function buildCommand(string $prompt, bool $continue, ?string $model): string
    {
        // Executado diretamente via API no FarControlDaemon
        return "NVIDIA_DIRECT_CALL";
    }

    public function getRecommendedModels(): array
    {
        return [
            '🧠 Llama 3.2 90B Vision' => 'meta/llama-3.2-90b-vision-instruct',
            '⚡ DeepSeek Flash'       => 'deepseek-ai/deepseek-v4.1-flash',
            '🔄 Padrão NVIDIA'        => 'default',
        ];
    }
}

// ============================================================================
// DAEMON PRINCIPAL DE CONTROLE REMOTO & ORQUESTRAÇÃO
// ============================================================================

class FarControlDaemon
{
    private string $botToken;
    private int $allowedChatId;
    private string $groqApiKey;
    private string $nvidiaApiKey;
    private string $openrouterApiKey;
    private ?string $sessionPin;
    private int $sessionTimeoutSeconds;
    private bool $orchestratorEnabled;
    private string $orchestratorModel;
    private string $currentAgentKey;
    private ?string $currentModel;
    private string $currentWorkingDir;
    private string $projectsBaseDir;
    private string $storageDir;
    private string $logFile;

    /** @var array<string, AgentDriverInterface> */
    private array $drivers = [];

    private int $lastUpdateId = 0;
    private bool $isRunning = true;
    private bool $isFirstTurnInDir = false;
    private bool $isSessionUnlocked = false;
    private float $lastActivityTimestamp = 0.0;

    public function __construct()
    {
        $this->botToken              = trim((string)getenv('TELEGRAM_BOT_TOKEN'));
        $this->allowedChatId         = (int)getenv('ALLOWED_CHAT_ID');
        $this->groqApiKey            = trim((string)getenv('GROQ_API_KEY'));
        $this->nvidiaApiKey          = trim((string)getenv('NVIDIA_API_KEY'));
        $this->openrouterApiKey      = trim((string)getenv('OPENROUTER_API_KEY'));

        $this->sessionPin            = getenv('SESSION_PIN') !== false && getenv('SESSION_PIN') !== '' ? trim((string)getenv('SESSION_PIN')) : null;
        $timeoutMinutes              = (int)(getenv('SESSION_TIMEOUT_MINUTES') ?: 60);
        $this->sessionTimeoutSeconds = max(5, $timeoutMinutes) * 60;

        $this->orchestratorEnabled   = filter_var(getenv('ENABLE_ORCHESTRATOR') ?: 'true', FILTER_VALIDATE_BOOLEAN);
        $this->orchestratorModel     = (string)(getenv('ORCHESTRATOR_MODEL') ?: 'deepseek/deepseek-v4.1-flash');

        $defaultAgent                = (string)(getenv('DEFAULT_AGENT') ?: (getenv('STRONG_AGENT') ?: 'agy'));
        $this->currentModel          = getenv('DEFAULT_MODEL') !== false && getenv('DEFAULT_MODEL') !== '' ? trim((string)getenv('DEFAULT_MODEL')) : null;

        $configuredDir               = (string)getenv('DEFAULT_WORKSPACE_DIR');
        $resolvedDir                 = !empty($configuredDir) && is_dir($configuredDir) ? realpath($configuredDir) : getcwd();
        $this->currentWorkingDir     = $resolvedDir ?: getcwd();

        $configuredBase              = (string)getenv('PROJECTS_BASE_DIR');
        $resolvedBase                = !empty($configuredBase) && is_dir($configuredBase) ? realpath($configuredBase) : $this->currentWorkingDir;
        $this->projectsBaseDir       = $resolvedBase ?: $this->currentWorkingDir;

        $this->storageDir = __DIR__ . '/storage/tmp';
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0700, true);
        }
        @chmod($this->storageDir, 0700);

        $this->logFile = __DIR__ . '/daemon.log';

        $this->drivers['agy']      = new AgyDriver();
        $this->drivers['opencode'] = new OpenCodeDriver();
        $this->drivers['nvidia']   = new NvidiaDriver();
        $this->currentAgentKey     = isset($this->drivers[$defaultAgent]) ? $defaultAgent : 'agy';

        $this->isSessionUnlocked     = ($this->sessionPin === null);
        $this->lastActivityTimestamp = microtime(true);

        $this->setupSignals();
    }

    private function setupSignals(): void
    {
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, [$this, 'shutdown']);
            pcntl_signal(SIGTERM, [$this, 'shutdown']);
        }
    }

    public function run(): void
    {
        $this->log("=======================================================", "INFO");
        $this->log("Iniciando Far-Control: Multi-Agent & Orchestrated Bridge", "INFO");
        $this->log("Agente Forte: {$this->currentAgentKey} (" . $this->getActiveDriver()->getName() . ")", "INFO");
        $this->log("Orquestrador de Dificuldade: " . ($this->orchestratorEnabled ? "ATIVO ({$this->orchestratorModel})" : "DESATIVADO"), "INFO");
        $this->log("Workspace: {$this->currentWorkingDir}", "INFO");
        $this->log("Jail Base: {$this->projectsBaseDir}", "INFO");
        $this->log("2FA PIN: " . ($this->sessionPin ? 'ATIVO (Protegido por senha)' : 'DESATIVADO'), "INFO");
        $this->log("=======================================================", "INFO");

        if (empty($this->botToken) || $this->botToken === 'SEU_TELEGRAM_BOT_TOKEN_AQUI') {
            $this->log("ERRO FATAL: TELEGRAM_BOT_TOKEN não foi configurado no arquivo .env!", "ERROR");
            exit(1);
        }

        $me = $this->getMe();
        if (!$me) {
            $this->log("ERRO FATAL: Falha ao autenticar token no Telegram. Verifique credenciais e conexão.", "ERROR");
            exit(1);
        }
        $this->log("Bot conectado: @{$me['username']} (ID: {$me['id']})", "INFO");

        $this->flushPendingUpdates();

        if ($this->allowedChatId === 0) {
            $this->log("[ATENÇÃO] ALLOWED_CHAT_ID está 0. Envie mensagem ao bot para registrar seu ID!", "WARNING");
        } else {
            $orchStatus = $this->orchestratorEnabled ? "🟢 Ativo (`{$this->orchestratorModel}`)" : "⚪ Desativado";
            $msg = "🛡️ *Far-Control Conectado e Operacional!*\n\n"
                . "• *Orquestrador:* {$orchStatus}\n"
                . "• *Agente de Projeto:* `{$this->currentAgentKey}`\n"
                . "• *Workspace:* `{$this->currentWorkingDir}`\n\n"
                . "Envie comandos Linux, prompts livres, áudios ou `/help`.";
            $this->sendTelegramMessage($this->allowedChatId, $msg);
        }

        while ($this->isRunning) {
            $this->checkSessionTimeout();

            $updates = $this->pollUpdates(3);
            if (!empty($updates)) {
                foreach ($updates as $update) {
                    $this->handleUpdate($update);
                }
            }

            usleep(150000); // 150ms
        }

        $this->shutdown();
    }

    private function getActiveDriver(): AgentDriverInterface
    {
        return $this->drivers[$this->currentAgentKey] ?? $this->drivers['agy'];
    }

    private function checkSessionTimeout(): void
    {
        if ($this->sessionPin !== null && $this->isSessionUnlocked) {
            if ((microtime(true) - $this->lastActivityTimestamp) > $this->sessionTimeoutSeconds) {
                $this->isSessionUnlocked = false;
                $this->log("Sessão bloqueada por inatividade.", "INFO");
                if ($this->allowedChatId !== 0) {
                    $this->sendTelegramMessage(
                        $this->allowedChatId,
                        "🔒 *Sessão Bloqueada por Inatividade!*\nDigite `/unlock <PIN>` para retomar o controle do terminal."
                    );
                }
            }
        }
    }

    private function handleUpdate(array $update): void
    {
        $this->lastUpdateId = $update['update_id'];

        if (!isset($update['message'])) {
            return;
        }

        $message = $update['message'];
        $chatId = $message['chat']['id'] ?? null;
        $username = $message['from']['username'] ?? ($message['from']['first_name'] ?? 'Desconhecido');

        if ($chatId === null) {
            return;
        }

        $this->log("Mensagem recebida de Chat ID: {$chatId} (@{$username})", "DEBUG");

        if ($this->allowedChatId === 0) {
            $this->log("SEU ALLOWED_CHAT_ID É: {$chatId} (De: @{$username})", "INFO");
            $reply = "👋 Olá! Seu Chat ID é: `{$chatId}`\n\nAdicione no seu `.env`:\n`ALLOWED_CHAT_ID={$chatId}`\ne reinicie o daemon.";
            $this->sendTelegramMessage($chatId, $reply);
            return;
        }

        if ((int)$chatId !== $this->allowedChatId) {
            $this->log("ACESSO RECUSADO: Chat ID {$chatId} (@{$username}) não autorizado.", "WARNING");
            return;
        }

        $text = isset($message['text']) ? trim($message['text']) : '';

        if (str_starts_with($text, '/unlock')) {
            $this->handleUnlockCommand($text);
            return;
        }

        if ($this->sessionPin !== null && !$this->isSessionUnlocked) {
            $this->sendTelegramMessage(
                $this->allowedChatId,
                "🔒 *Acesso Bloqueado!*\nSua sessão está trancada por segurança.\nDigite `/unlock <SEU_PIN>` para desbloquear."
            );
            return;
        }

        $this->lastActivityTimestamp = microtime(true);

        if ($text === '/lock') {
            if ($this->sessionPin === null) {
                $this->sendTelegramMessage($this->allowedChatId, "ℹ️ PIN de sessão não configurado no `.env` (defina `SESSION_PIN=1234`).");
                return;
            }
            $this->isSessionUnlocked = false;
            $this->sendTelegramMessage($this->allowedChatId, "🔒 *Sessão trancada com sucesso!*\nUse `/unlock <PIN>` para reabrir.");
            $this->log("Sessão trancada manualmente pelo usuário.", "INFO");
            return;
        }

        // 1. Áudio de voz
        if (isset($message['voice'])) {
            $this->handleVoiceMessage($message['voice']);
            return;
        }

        // 2. Mensagem de texto
        if ($text !== '') {
            $this->handleTextMessage($text);
            return;
        }

        // 3. Documento de áudio
        if (isset($message['audio'])) {
            $this->handleVoiceMessage($message['audio']);
            return;
        }
    }

    private function handleUnlockCommand(string $text): void
    {
        if ($this->sessionPin === null) {
            $this->sendTelegramMessage($this->allowedChatId, "ℹ️ A autenticação por PIN está desativada no seu `.env`.");
            return;
        }

        $parts = preg_split('/\s+/', trim($text), 2);
        $providedPin = $parts[1] ?? '';

        if ($providedPin === $this->sessionPin) {
            $this->isSessionUnlocked = true;
            $this->lastActivityTimestamp = microtime(true);
            $this->sendTelegramMessage(
                $this->allowedChatId,
                "🔓 *Acesso Autorizado!*\nSessão liberada. Você pode enviar prompts de texto ou áudio normalmente."
            );
            $this->log("Sessão desbloqueada com sucesso via PIN.", "INFO");
        } else {
            $this->log("Tentativa de desbloqueio com PIN incorreto!", "WARNING");
            $this->sendTelegramMessage($this->allowedChatId, "❌ *PIN incorreto!* Verifique sua senha no arquivo `.env`.");
        }
    }

    private function handleTextMessage(string $text): void
    {
        if ($text === '/start' || $text === '/help') {
            $orchTxt = $this->orchestratorEnabled ? "🟢 Ativo (`{$this->orchestratorModel}`)" : "⚪ Desativado";
            $msg = "🤖 *Far-Control: Comandos do Sistema*\n\n"
                . "• *Comandos Linux Nativos (Zero Tokens):*\n"
                . "  Envie no chat: `ls -la`, `git status`, `git pull`, `mkdir pasta`, `cat .env.example`, `npm test`.\n"
                . "  Ou com prefixo: `/sh <comando>` ou `$ <comando>`.\n\n"
                . "• *Orquestrador de Dificuldade:*\n"
                . "  Status atual: {$orchTxt}\n"
                . "  `/orchestrator on` - Ativa triagem inteligente.\n"
                . "  `/orchestrator off` - Desativa (tudo vai para o agente forte).\n\n"
                . "• *Agente & Modelo de Projeto:*\n"
                . "  `/agent` - Alterna entre `agy`, `opencode` ou `nvidia`.\n"
                . "  `/agent nvidia` - Usa NVIDIA Build NIM (Llama 3.2 90B).\n"
                . "  `/models` - Lista modelos recomendados para o agente ativo.\n"
                . "  `/model <nome>` - Define o modelo de IA.\n\n"
                . "• *Workspace & Pastas (Jail Seguro):*\n"
                . "  `/pwd` - Exibe pasta atual de trabalho.\n"
                . "  `/projects` - Lista repositórios autorizados.\n"
                . "  `/cd <pasta>` - Troca de pasta dentro da base.\n\n"
                . "• *Segurança & Diagnóstico:*\n"
                . "  `/info` - Guia completo da arquitetura, fluxos e segurança.\n"
                . "  `/lock` - Bloqueia a sessão imediatamente.\n"
                . "  `/status` - Exibe diagnóstico completo do sistema.\n"
                . "  `/logs` - Exibe os últimos logs (DLP ativo).\n"
                . "  `/new` - Reseta o histórico de conversa do agente.\n\n"
                . "• *Prompts de IA:*\n"
                . "  Envie normalmente por texto ou áudio de voz.";
            $this->sendTelegramMessage($this->allowedChatId, $msg);
            return;
        }

        if ($text === '/info' || $text === '/sobre') {
            $this->sendInfoOverview();
            return;
        }

        // Comandos de Orquestração (/orchestrator on|off)
        if (str_starts_with($text, '/orchestrator') || str_starts_with($text, '/router')) {
            $parts = preg_split('/\s+/', trim($text));
            $arg = strtolower($parts[1] ?? '');

            if ($arg === 'on') {
                $this->orchestratorEnabled = true;
                $this->sendTelegramMessage($this->allowedChatId, "⚡ *Orquestrador Ativado!* Tarefas simples serão respondidas instantaneamente; tarefas de projeto serão roteadas para o agente forte.");
                return;
            }

            if ($arg === 'off') {
                $this->orchestratorEnabled = false;
                $this->sendTelegramMessage($this->allowedChatId, "⚪ *Orquestrador Desativado!* Todas as instruções serão enviadas diretamente ao agente de projeto (`{$this->currentAgentKey}`).");
                return;
            }

            $st = $this->orchestratorEnabled ? "🟢 Ativo (`{$this->orchestratorModel}`)" : "⚪ Desativado";
            $this->sendTelegramMessage($this->allowedChatId, "⚡ *Orquestrador de Dificuldade:*\nStatus: {$st}\n\nPara alternar:\n👉 `/orchestrator on`\n👉 `/orchestrator off`");
            return;
        }

        if (str_starts_with($text, '/agent') || str_starts_with($text, '/engine')) {
            $parts = preg_split('/\s+/', trim($text));
            $target = strtolower($parts[1] ?? '');

            if ($target === '') {
                $available = implode(", ", array_map(fn($k) => "`{$k}`", array_keys($this->drivers)));
                $msg = "🤖 *Agente Ativo:* `{$this->currentAgentKey}` (" . $this->getActiveDriver()->getName() . ")\n"
                    . "Disponíveis: {$available}\n\n"
                    . "Para alternar:\n"
                    . "👉 `/agent agy`\n"
                    . "👉 `/agent opencode`";
                $this->sendTelegramMessage($this->allowedChatId, $msg);
                return;
            }

            if (!isset($this->drivers[$target])) {
                $this->sendTelegramMessage($this->allowedChatId, "❌ Agente desconhecido: `{$target}`. Opções: `agy`, `opencode`.");
                return;
            }

            $this->currentAgentKey = $target;
            $this->currentModel = null;
            $this->isFirstTurnInDir = true;

            $this->log("Agente alterado para: {$this->currentAgentKey}", "INFO");
            $this->sendTelegramMessage(
                $this->allowedChatId,
                "✅ *Agente Ativo:* `{$this->currentAgentKey}` (" . $this->getActiveDriver()->getName() . ")\nModelo: _Padrão do agente_"
            );
            return;
        }

        if ($text === '/models') {
            $driver = $this->getActiveDriver();
            $recommended = $driver->getRecommendedModels();

            $lines = ["🧠 *Modelos sugeridos para* `{$this->currentAgentKey}`:\n"];
            foreach ($recommended as $label => $modelCode) {
                $isCurrent = ($this->currentModel === $modelCode || (empty($this->currentModel) && $modelCode === 'default')) ? ' 👈 (atual)' : '';
                $lines[] = "• *{$label}*{$isCurrent}\n  👉 `/model {$modelCode}`";
            }
            $lines[] = "\n_Você também pode digitar `/model <provedor/modelo>` livremente._";

            $this->sendTelegramMessage($this->allowedChatId, implode("\n", $lines));
            return;
        }

        if (str_starts_with($text, '/model')) {
            $parts = preg_split('/\s+/', trim($text), 2);
            $targetModel = trim($parts[1] ?? '');

            if ($targetModel === '') {
                $cur = $this->currentModel ?: '_Padrão do agente_';
                $this->sendTelegramMessage(
                    $this->allowedChatId,
                    "🧠 *Modelo Atual:* `{$cur}`\n\nUse `/models` para sugestões ou `/model <nome>` para definir."
                );
                return;
            }

            if ($targetModel === 'default' || $targetModel === 'reset') {
                $this->currentModel = null;
                $this->sendTelegramMessage($this->allowedChatId, "✅ Modelo resetado para o padrão do `{$this->currentAgentKey}`.");
                return;
            }

            $this->currentModel = $targetModel;
            $this->log("Modelo configurado para: {$this->currentModel} no agente {$this->currentAgentKey}", "INFO");
            $this->sendTelegramMessage($this->allowedChatId, "✅ *Modelo Configurado:*\n`{$this->currentModel}`");
            return;
        }

        if ($text === '/pwd') {
            $this->sendTelegramMessage($this->allowedChatId, "📂 *Workspace Atual:*\n`{$this->currentWorkingDir}`");
            return;
        }

        if ($text === '/projects' || $text === '/list') {
            $this->listProjects();
            return;
        }

        if (str_starts_with($text, '/cd')) {
            $target = trim(substr($text, 3));
            if ($target === '') {
                $this->sendTelegramMessage($this->allowedChatId, "⚠️ Uso: `/cd <pasta>`\nExemplo: `/cd meu-projeto`");
                return;
            }
            $this->changeDirectory($target);
            return;
        }

        if ($text === '/new' || $text === '/reset') {
            $this->isFirstTurnInDir = true;
            $this->sendTelegramMessage($this->allowedChatId, "🆕 *Nova sessão iniciada!* Memória anterior resetada.");
            $this->log("Sessão resetada via /new", "INFO");
            return;
        }

        if ($text === '/logs') {
            $this->sendRecentLogs();
            return;
        }

        if ($text === '/status') {
            $curMod = $this->currentModel ?: 'Padrão do agente';
            $pinStat = $this->sessionPin ? '🟢 Autenticado (PIN Ativo)' : '⚪ Sem PIN';
            $orchStat = $this->orchestratorEnabled ? "🟢 Ativo (`{$this->orchestratorModel}`)" : "⚪ Desativado";
            $msg = "📊 *Status do Far-Control:*\n\n"
                . "• *Orquestrador:* {$orchStat}\n"
                . "• *Agente de Projeto:* `{$this->currentAgentKey}` (" . $this->getActiveDriver()->getName() . ")\n"
                . "• *Modelo de Projeto:* `{$curMod}`\n"
                . "• *Workspace:* `{$this->currentWorkingDir}`\n"
                . "• *Segurança:* {$pinStat}\n"
                . "• *Status:* 🟢 Pronto para comandos";
            $this->sendTelegramMessage($this->allowedChatId, $msg);
            return;
        }

        // 1. Comando Shell Explícito (/sh <cmd>, /run <cmd>, $ <cmd>)
        if (str_starts_with($text, '/sh ') || str_starts_with($text, '/run ') || str_starts_with($text, '$ ')) {
            $cmd = trim(substr($text, strpos($text, ' ') + 1));
            if ($cmd !== '') {
                $this->executeShellCommand($cmd);
                return;
            }
        }

        // 2. Detecção Automática de Comandos Linux (Zero Tokens)
        if ($this->isNativeLinuxCommand($text)) {
            $this->executeShellCommand($text);
            return;
        }

        // 3. Orquestração de Dificuldade vs Agente Forte
        if ($this->orchestratorEnabled) {
            $this->routeViaOrchestrator($text);
            return;
        }

        // 4. Execução direta no Agente Forte
        $this->executePromptOnAgent($text);
    }

    /**
     * Orquestrador de Dificuldade (NVIDIA Build / Fast LLM Router):
     * Classifica o prompt. Se for simples, responde diretamente em segundos.
     * Se for complexo ou intrínseco ao projeto, despacha para o agente forte.
     */
    private function routeViaOrchestrator(string $prompt): void
    {
        $this->log("Orquestrador avaliando dificuldade do prompt: \"{$prompt}\"...", "INFO");

        $systemPrompt = "Você é um classificador e orquestrador de IA sênior para desenvolvedores. "
            . "Analise a solicitação do usuário:\n"
            . "1. Se for uma dúvida teórica, pergunta conceitual, sintaxe de linguagem, tradução, explicação de termo ou snippet isolado que NÃO exige inspecionar, criar ou editar arquivos na codebase do projeto, classifique como SIMPLE e responda diretamente a dúvida com alta qualidade de forma concisa.\n"
            . "2. Se for uma tarefa de desenvolvimento que envolve arquivos do projeto, features, refatoração, bugs na codebase, git ou inspeção do workspace atual, classifique como PROJECT_INTRINSIC.\n\n"
            . "Responda ESTRITAMENTE em formato JSON com as chaves:\n"
            . "{\"type\": \"SIMPLE\"|\"PROJECT_INTRINSIC\", \"explanation\": \"motivo\", \"answer\": \"sua resposta caso SIMPLE\"}";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $prompt],
        ];

        $classification = $this->callFastLLM($messages);

        if ($classification && isset($classification['type']) && $classification['type'] === 'SIMPLE' && !empty($classification['answer'])) {
            $this->log("Orquestrador: Tarefa classificada como SIMPLE. Respondendo diretamente.", "INFO");
            $responseHeader = "⚡ *[Orquestrador - Resposta Rápida]*\n\n";
            $this->sendChunkedTelegramMessage($this->allowedChatId, $responseHeader . $classification['answer']);
            return;
        }

        $reason = $classification['explanation'] ?? 'tarefa com dependência de codebase ou arquivos locais';
        $this->log("Orquestrador: Tarefa classificada como PROJECT_INTRINSIC ({$reason}). Despachando para {$this->currentAgentKey}...", "INFO");

        $this->sendTelegramMessage(
            $this->allowedChatId,
            "🧠 *[Orquestrador]* Tarefa de projeto detectada ({$reason}).\nAcionando agente forte (`{$this->currentAgentKey}`)..."
        );

        $this->executePromptOnAgent($prompt);
    }

    /**
     * Chama a LLM rápida para triagem (NVIDIA NIM com fallback para OpenRouter)
     */
    private function callFastLLM(array $messages): ?array
    {
        // 1ª Tentativa: OpenRouter com modelo rápido/free
        if (!empty($this->openrouterApiKey)) {
            $url = 'https://openrouter.ai/api/v1/chat/completions';
            $payload = [
                'model'          => $this->orchestratorModel,
                'messages'       => $messages,
                'response_format' => ['type' => 'json_object'],
                'temperature'    => 0.1,
            ];

            $res = $this->httpJsonPost($url, $payload, ['Authorization: Bearer ' . $this->openrouterApiKey], 15);
            if ($res && isset($res['choices'][0]['message']['content'])) {
                $rawContent = trim($res['choices'][0]['message']['content']);
                $parsed = json_decode($rawContent, true);
                if (is_array($parsed)) {
                    return $parsed;
                }
            }
        }

        // 2ª Tentativa: NVIDIA NIM API direta
        if (!empty($this->nvidiaApiKey)) {
            $url = 'https://integrate.api.nvidia.com/v1/chat/completions';
            $payload = [
                'model'       => 'deepseek-ai/deepseek-v4.1-flash',
                'messages'    => $messages,
                'temperature' => 0.1,
            ];

            $res = $this->httpJsonPost($url, $payload, ['Authorization: Bearer ' . $this->nvidiaApiKey], 15);
            if ($res && isset($res['choices'][0]['message']['content'])) {
                $rawContent = trim($res['choices'][0]['message']['content']);
                $parsed = json_decode($rawContent, true);
                if (is_array($parsed)) {
                    return $parsed;
                }
            }
        }

        return null;
    }

    private function httpJsonPost(string $url, array $payload, array $headers, int $timeout = 15): ?array
    {
        $headers[] = 'Content-Type: application/json';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err || $httpCode < 200 || $httpCode >= 300) {
            $this->log("Aviso HTTP {$httpCode} em {$url}: {$err} - {$response}", "DEBUG");
            return null;
        }

        return json_decode((string)$response, true);
    }

    // ========================================================================
    // PROCESSAMENTO DE ÁUDIO COM CADEIA DE FALLBACKS (STT)
    // ========================================================================

    private function handleVoiceMessage(array $voiceData): void
    {
        $fileId = $voiceData['file_id'] ?? null;
        if (!$fileId) {
            $this->sendTelegramMessage($this->allowedChatId, "❌ Falha ao identificar o áudio recebido.");
            return;
        }

        $this->sendTelegramMessage($this->allowedChatId, "🎙️ Áudio recebido! Transcrevendo com Whisper...");
        $this->log("Processando áudio (File ID: {$fileId})...", "INFO");

        $filePath = $this->getTelegramFilePath($fileId);
        if (!$filePath) {
            $this->sendTelegramMessage($this->allowedChatId, "❌ Erro ao localizar áudio nos servidores do Telegram.");
            return;
        }

        $rawOggPath = $this->storageDir . '/voice_' . bin2hex(random_bytes(6)) . '.ogg';
        $convertedMp3Path = $this->storageDir . '/voice_' . bin2hex(random_bytes(6)) . '.mp3';

        try {
            $downloaded = $this->downloadTelegramFile($filePath, $rawOggPath);

            if (!$downloaded || !file_exists($rawOggPath)) {
                $this->sendTelegramMessage($this->allowedChatId, "❌ Falha no download do áudio.");
                return;
            }

            // Normalização com FFmpeg (16kHz mono MP3 universal)
            $ffmpegCmd = "ffmpeg -y -i " . escapeshellarg($rawOggPath) . " -vn -ar 16000 -ac 1 -b:a 64k " . escapeshellarg($convertedMp3Path) . " 2>&1";
            shell_exec($ffmpegCmd);

            $targetAudioPath = file_exists($convertedMp3Path) && filesize($convertedMp3Path) > 0
                ? $convertedMp3Path
                : $rawOggPath;

            // Executa cadeia de fallbacks para Whisper STT
            $transcription = $this->transcribeWithFallbacks($targetAudioPath);

            if (empty($transcription)) {
                $this->sendTelegramMessage($this->allowedChatId, "❌ Não foi possível transcrever a fala do áudio em nenhum provedor.");
                return;
            }

            $this->sendTelegramMessage($this->allowedChatId, "🗣️ *Transcrição:* \"{$transcription}\"");
            $this->log("Transcrição final: \"{$transcription}\"", "INFO");

            // Roteia a transcrição pelo fluxo normal
            if ($this->orchestratorEnabled) {
                $this->routeViaOrchestrator($transcription);
            } else {
                $this->executePromptOnAgent($transcription);
            }
        } finally {
            if (file_exists($rawOggPath)) {
                @unlink($rawOggPath);
            }
            if (file_exists($convertedMp3Path)) {
                @unlink($convertedMp3Path);
            }
        }
    }

    /**
     * Cadeia de Fallback para Transcrição Whisper:
     * 1. Groq whisper-large-v3-turbo (ultrarrápido, menor latência)
     * 2. Groq whisper-large-v3
     * 3. OpenRouter / OpenAI Whisper
     */
    private function transcribeWithFallbacks(string $audioFilePath): ?string
    {
        // 1. Groq Turbo
        if (!empty($this->groqApiKey)) {
            $this->log("Tentando STT com Groq whisper-large-v3-turbo...", "DEBUG");
            $text = $this->callGroqWhisper($audioFilePath, 'whisper-large-v3-turbo');
            if (!empty($text)) {
                return $text;
            }

            // 2. Groq Large-v3 padrão
            $this->log("Fallback STT para Groq whisper-large-v3...", "DEBUG");
            $text = $this->callGroqWhisper($audioFilePath, 'whisper-large-v3');
            if (!empty($text)) {
                return $text;
            }
        }

        // 3. Fallback OpenRouter Whisper (se Groq der b.o.)
        if (!empty($this->openrouterApiKey)) {
            $this->log("Fallback STT para OpenRouter/OpenAI Whisper...", "DEBUG");
            $text = $this->callOpenRouterWhisper($audioFilePath);
            if (!empty($text)) {
                return $text;
            }
        }

        return null;
    }

    private function callGroqWhisper(string $audioFilePath, string $model): ?string
    {
        $url = 'https://api.groq.com/openai/v1/audio/transcriptions';
        $mime = str_ends_with($audioFilePath, '.mp3') ? 'audio/mpeg' : 'audio/ogg';
        $filename = basename($audioFilePath);

        $cfile = new CURLFile($audioFilePath, $mime, $filename);
        $postFields = [
            'file'            => $cfile,
            'model'           => $model,
            'response_format' => 'json',
            'temperature'     => '0.0',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->groqApiKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 35,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err || $httpCode !== 200) {
            $this->log("Falha Groq Whisper ({$model}) HTTP {$httpCode}: {$response}", "WARNING");
            return null;
        }

        $json = json_decode((string)$response, true);
        return isset($json['text']) ? trim($json['text']) : null;
    }

    private function callOpenRouterWhisper(string $audioFilePath): ?string
    {
        $url = 'https://openrouter.ai/api/v1/audio/transcriptions';
        $mime = str_ends_with($audioFilePath, '.mp3') ? 'audio/mpeg' : 'audio/ogg';
        $filename = basename($audioFilePath);

        $cfile = new CURLFile($audioFilePath, $mime, $filename);
        $postFields = [
            'file'  => $cfile,
            'model' => 'openai/whisper-large-v3',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->openrouterApiKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $json = json_decode((string)$response, true);
            return isset($json['text']) ? trim($json['text']) : null;
        }

        return null;
    }

    // ========================================================================
    // EXECUÇÃO DE AGENTE E COMANDOS SHELL
    // ========================================================================

    private function executePromptOnAgent(string $prompt): void
    {
        // Se o usuário selecionou o agente NVIDIA diretamente (/agent nvidia)
        if ($this->currentAgentKey === 'nvidia') {
            $this->sendTelegramMessage(
                $this->allowedChatId,
                "⏳ [NVIDIA Build] Consultando Llama 3.2 90B..."
            );
            $ans = $this->callNvidiaBuildDirect($prompt, $this->currentModel);
            if ($ans) {
                $clean = $this->sanitizeAgentOutput($ans);
                $this->sendChunkedTelegramMessage($this->allowedChatId, $clean);
                return;
            }
            $this->sendTelegramMessage($this->allowedChatId, "❌ Falha ao obter resposta da NVIDIA Build.");
            return;
        }

        $driver = $this->getActiveDriver();
        $isContinue = !$this->isFirstTurnInDir;
        $command = $driver->buildCommand($prompt, $isContinue, $this->currentModel);

        $this->sendTelegramMessage(
            $this->allowedChatId,
            "⏳ [{$this->currentAgentKey}] Executando instrução no workspace..."
        );
        $this->log("[{$this->currentAgentKey}] Executando: {$command}", "INFO");

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->currentWorkingDir);

        if (!is_resource($process)) {
            $this->log("FALHA CRÍTICA: proc_open retornou false!", "ERROR");
            $this->sendTelegramMessage($this->allowedChatId, "❌ Falha ao inicializar subprocesso da CLI.");
            return;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            stream_set_blocking($pipes[2], false);
        }

        $output = '';
        $lastProgressNotice = time();
        $startTime = time();

        while (true) {
            $status = proc_get_status($process);

            while (($chunk = fread($pipes[1], 8192)) !== false && $chunk !== '') {
                $output .= $chunk;
            }

            if (!$status['running']) {
                break;
            }

            if (time() - $lastProgressNotice >= 45) {
                $elapsed = time() - $startTime;
                $this->sendTelegramMessage(
                    $this->allowedChatId,
                    "⏳ [{$this->currentAgentKey}] Processando ({$elapsed}s decorridos)..."
                );
                $lastProgressNotice = time();
            }

            usleep(200000); // 200ms
        }

        while (($chunk = fread($pipes[1], 8192)) !== false && $chunk !== '') {
            $output .= $chunk;
        }

        fclose($pipes[1]);
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            fclose($pipes[2]);
        }
        $exitCode = proc_close($process);

        $this->isFirstTurnInDir = false;

        $this->log("[{$this->currentAgentKey}] Concluído com Exit Code {$exitCode}. Tamanho da saída: " . strlen($output) . " bytes.", "INFO");

        // Detecção de esgotamento de tokens / rate limit / cota ou falha do processo
        $isQuotaError = preg_match('/(quota|429|resource_exhausted|rate limit|too many requests|insufficient|exceeded|out of tokens)/i', $output);
        $isEmptyFailure = ($exitCode !== 0 && trim($output) === '');

        if ($isQuotaError || $isEmptyFailure) {
            $this->log("FALLBACK DE SOBREVIVÊNCIA ATIVADO: {$this->currentAgentKey} esgotou cota ou falhou. Roteando para NVIDIA Build...", "WARNING");
            $this->sendTelegramMessage(
                $this->allowedChatId,
                "🔄 *[Fallback de Sobrevivência - NVIDIA Build]*\nO agente `{$this->currentAgentKey}` atingiu limite de cota ou falhou. Gerando resposta via NVIDIA Llama 3.2 90B..."
            );

            $fallbackAnswer = $this->callNvidiaBuildDirect($prompt);
            if ($fallbackAnswer) {
                $clean = $this->sanitizeAgentOutput($fallbackAnswer);
                $this->sendChunkedTelegramMessage($this->allowedChatId, $clean);
                return;
            }
        }

        $cleanOutput = $this->sanitizeAgentOutput($output);

        if (trim($cleanOutput) === '') {
            $this->sendTelegramMessage($this->allowedChatId, "ℹ️ Tarefa finalizada sem mensagens de saída.");
            return;
        }

        $this->sendChunkedTelegramMessage($this->allowedChatId, $cleanOutput);
    }

    /**
     * Chamada direta à API da NVIDIA Build (Llama 3.2 90B Vision) com fallback OpenRouter
     */
    private function callNvidiaBuildDirect(string $prompt, ?string $model = null): ?string
    {
        $targetModel = (!empty($model) && $model !== 'default') ? $model : 'meta/llama-3.2-90b-vision-instruct';

        $messages = [
            [
                'role' => 'system',
                'content' => "Você é um assistente sênior de desenvolvimento. Siga estritamente o AI.md: seja ultra conciso, KISS, Clean Code, SOLID. Entregue diretamente a resposta ou código sem enrolação."
            ],
            ['role' => 'user', 'content' => $prompt]
        ];

        // 1ª Tentativa: NVIDIA Build NIM oficial
        if (!empty($this->nvidiaApiKey)) {
            $url = 'https://integrate.api.nvidia.com/v1/chat/completions';
            $payload = [
                'model'       => $targetModel,
                'messages'    => $messages,
                'max_tokens'  => 2048,
                'temperature' => 0.2,
            ];

            $res = $this->httpJsonPost($url, $payload, ['Authorization: Bearer ' . $this->nvidiaApiKey], 30);
            if ($res && isset($res['choices'][0]['message']['content'])) {
                return trim($res['choices'][0]['message']['content']);
            }
        }

        // 2ª Tentativa: OpenRouter Fallback
        if (!empty($this->openrouterApiKey)) {
            $url = 'https://openrouter.ai/api/v1/chat/completions';
            $payload = [
                'model'      => 'openrouter/auto',
                'messages'   => $messages,
                'max_tokens' => 2048,
            ];

            $res = $this->httpJsonPost($url, $payload, ['Authorization: Bearer ' . $this->openrouterApiKey], 30);
            if ($res && isset($res['choices'][0]['message']['content'])) {
                return trim($res['choices'][0]['message']['content']);
            }
        }

        return null;
    }

    /**
     * Algoritmo de Sanitização e Limpeza Fina do Output de IA
     * Remove metadados internos, tags thought/thinking, logs do agy/gemini e preâmbulos prolixos.
     */
    private function sanitizeAgentOutput(string $raw): string
    {
        $text = $this->stripAnsi($raw);

        // 1. Remove tags de pensamento e raciocínio interno
        $text = preg_replace('/<thought>[\s\S]*?<\/thought>/i', '', $text);
        $text = preg_replace('/<thinking>[\s\S]*?<\/thinking>/i', '', $text);
        $text = preg_replace('/Thinking Process:[\s\S]*?(?=\n\n|\Z)/i', '', $text);
        $text = preg_replace('/<system_message>[\s\S]*?<\/system_message>/i', '', $text);

        // 2. Remove logs técnicos de execução de tarefas do Antigravity CLI e Background Tasks
        $taskPatterns = [
            '/Task id "[^"]*" finished with result:\s*/i',
            '/Tool is running as a background task with task id:[^\n]*\n?/i',
            '/Task logs are available at:[^\n]*\n?/i',
            '/YOU MUST TAKE ONE OF THE FOLLOWING TWO ACTIONS:[^\n]*\n?/i',
            '/DO NOTHING ELSE\.[^\n]*\n?/i',
            '/Created At:\s*[^\n]*\n?/i',
            '/Completed At:\s*[^\n]*\n?/i',
            '/Command exited with code \d+\.?\n?/i',
            '/Log:\s*file:\/\/\/[^\n]*\n?/i',
            '/Last progress:\s*[^\n]*\n?/i',
            '/Command output:\s*\n?/i',
        ];
        $text = preg_replace($taskPatterns, '', $text);

        // 3. Remove artefatos e banners do OpenCode
        $text = preg_replace('/> build · [^\n]*\n?/i', '', $text);
        $text = preg_replace('/Loaded plugins:[^\n]*\n?/i', '', $text);

        // 4. Remove preâmbulos prolixos comuns de LLMs
        $preambles = [
            '/^(Com certeza|Certamente|Claro|Com prazer|Olá)[\s!.,]+(Aqui está|vou te ajudar|segue|aqui temos)[^\n]*\n+/i',
            '/^(Aqui está o que você pediu|Aqui está o código|Espero que ajude)[\s!.,:]*\n+/i',
        ];
        $text = preg_replace($preambles, '', $text);

        // 5. Normaliza quebras de linha múltiplas
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return $this->redactSensitiveData(trim($text));
    }

    private function isNativeLinuxCommand(string $text): bool
    {
        $parts = preg_split('/\s+/', trim($text));
        $firstWord = strtolower($parts[0] ?? '');

        $nativeCommands = [
            'ls', 'll', 'la', 'pwd', 'cat', 'head', 'tail', 'grep', 'find', 'tree',
            'wc', 'diff', 'touch', 'mkdir', 'rm', 'cp', 'mv', 'echo', 'stat',
            'file', 'chmod', 'chown', 'ln',
            'git',
            'npm', 'npx', 'yarn', 'pnpm', 'composer', 'php', 'node', 'python',
            'python3', 'pip', 'pip3', 'cargo', 'go', 'deno', 'bun',
            'docker', 'docker-compose', 'ps', 'kill', 'pkill',
            'df', 'free', 'uptime', 'curl', 'wget', 'ping', 'which', 'whoami',
            'uname', 'du', 'tar', 'zip', 'unzip', 'history', 'env',
        ];

        return in_array($firstWord, $nativeCommands, true);
    }

    private function executeShellCommand(string $cmd): void
    {
        $this->log("Executando Shell nativo: {$cmd}", "INFO");

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open("exec bash -c " . escapeshellarg($cmd) . " 2>&1", $descriptors, $pipes, $this->currentWorkingDir);

        if (!is_resource($process)) {
            $this->sendTelegramMessage($this->allowedChatId, "❌ Falha ao inicializar o bash para o comando.");
            return;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);

        $output = '';
        $startTime = microtime(true);
        $timeout = 30.0;

        while (true) {
            $status = proc_get_status($process);

            while (($chunk = fread($pipes[1], 8192)) !== false && $chunk !== '') {
                $output .= $chunk;
            }

            if (!$status['running']) {
                break;
            }

            if ((microtime(true) - $startTime) > $timeout) {
                proc_terminate($process, 9);
                $output .= "\n[!] Processo interrompido por timeout ({$timeout}s).";
                break;
            }

            usleep(50000);
        }

        while (($chunk = fread($pipes[1], 8192)) !== false && $chunk !== '') {
            $output .= $chunk;
        }

        fclose($pipes[1]);
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            fclose($pipes[2]);
        }
        $exitCode = proc_close($process);

        $cleanOutput = $this->redactSensitiveData($this->stripAnsi($output));

        $header = "💻 `\$ {$cmd}` (Zero Tokens)\n";
        if (trim($cleanOutput) === '') {
            $statusMsg = ($exitCode === 0)
                ? "✅ Executado com sucesso (sem retorno de texto)."
                : "⚠️ Executado com código de saída {$exitCode}.";
            $this->sendTelegramMessage($this->allowedChatId, "{$header}{$statusMsg}");
            return;
        }

        $formatted = "{$header}```text\n{$cleanOutput}\n```";
        if (mb_strlen($formatted, 'UTF-8') <= 3800) {
            $this->sendTelegramMessage($this->allowedChatId, $formatted);
        } else {
            $this->sendChunkedTelegramMessage($this->allowedChatId, $cleanOutput);
        }
    }

    private function changeDirectory(string $target): void
    {
        $realBase = realpath($this->projectsBaseDir);
        if (!$realBase) {
            $this->sendTelegramMessage($this->allowedChatId, "❌ Pasta base configurada não existe no sistema.");
            return;
        }

        $homeDir = getenv('HOME') ?: '/home/' . (getenv('USER') ?: '');
        if ($target === '~' || str_starts_with($target, '~/')) {
            $target = $homeDir . substr($target, 1);
        }

        $resolved = null;
        if (str_starts_with($target, '/')) {
            $resolved = realpath($target);
        } else {
            $baseCandidate = $realBase . '/' . $target;
            if (is_dir($baseCandidate)) {
                $resolved = realpath($baseCandidate);
            } else {
                $currCandidate = $this->currentWorkingDir . '/' . $target;
                if (is_dir($currCandidate)) {
                    $resolved = realpath($currCandidate);
                }
            }
        }

        if (!$resolved || !is_dir($resolved)) {
            $this->sendTelegramMessage(
                $this->allowedChatId,
                "❌ *Diretório não encontrado:* `{$target}`\nUse `/projects` para ver as pastas disponíveis."
            );
            return;
        }

        if (!str_starts_with($resolved, $realBase . '/') && $resolved !== $realBase) {
            $this->log("BLOQUEIO DE SEGURANÇA: Tentativa de Path Traversal para '{$resolved}' fora da base '{$realBase}'!", "WARNING");
            $this->sendTelegramMessage(
                $this->allowedChatId,
                "🛡️ *Acesso Negado (Chroot Jail):*\nPor segurança, você só pode navegar dentro de:\n`{$realBase}`"
            );
            return;
        }

        $this->currentWorkingDir = $resolved;
        $this->isFirstTurnInDir = true;

        $this->log("Workspace alterado com sucesso para: {$this->currentWorkingDir}", "INFO");
        $this->sendTelegramMessage(
            $this->allowedChatId,
            "✅ *Workspace Atualizado!*\nOperando em:\n`{$this->currentWorkingDir}`"
        );
    }

    private function listProjects(): void
    {
        $realBase = realpath($this->projectsBaseDir);
        if (!$realBase || !is_dir($realBase)) {
            $this->sendTelegramMessage($this->allowedChatId, "❌ Pasta base não encontrada: `{$this->projectsBaseDir}`");
            return;
        }

        $items = scandir($realBase);
        $projects = [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || str_starts_with($item, '.')) {
                continue;
            }
            $fullPath = $realBase . '/' . $item;
            if (is_dir($fullPath)) {
                $isCurrent = ($fullPath === $this->currentWorkingDir) ? ' 👈 (atual)' : '';
                $projects[] = "• `{$item}`{$isCurrent}  👉 `/cd {$item}`";
            }
        }

        if (empty($projects)) {
            $msg = "📁 *Base Autorizada:* `{$realBase}`\nNenhuma subpasta encontrada.";
        } else {
            $msg = "📁 *Projetos Autorizados* (`{$realBase}`):\n\n" . implode("\n", $projects);
        }

        $this->sendTelegramMessage($this->allowedChatId, $msg);
    }

    /**
     * Envia visão geral da arquitetura, fluxos e segurança (/info)
     */
    private function sendInfoOverview(): void
    {
        $info = "🛰️ *SONAR: ARQUITETURA & GUIA DO SISTEMA*\n\n"
            . "1️⃣ *TERMINAL LINUX (Zero Tokens)*\n"
            . "• Comandos cotidianos rodam direto no Bash local sem gastar IA.\n"
            . "• Detecção automática: `ls`, `git`, `mkdir`, `cat`, `npm`, `composer`, `docker`...\n"
            . "• Comandos arbitrários com pipes: `/sh <comando>` ou `$ <comando>`.\n\n"
            . "2️⃣ *ORQUESTRADOR DE DIFICULDADE (NVIDIA Build / Fast LLM)*\n"
            . "• *Triagem Automática:* Avalia cada solicitação em linguagem natural.\n"
            . "• *Simples:* Dúvidas conceituais/sintaxe são respondidas em 1-2s pelo modelo rápido.\n"
            . "• *Projeto:* Features, refatoração e bugs locais são despachados para o Agente Forte.\n"
            . "• *Controle:* `/orchestrator on` ou `/orchestrator off`.\n\n"
            . "3️⃣ *AGENTES & ALTA DISPONIBILIDADE*\n"
            . "• `/agent agy` - Antigravity CLI (Gemini, raciocínio profundo e automação).\n"
            . "• `/agent opencode` - OpenCode CLI (respostas ultrarrápidas).\n"
            . "• `/agent nvidia` - NVIDIA Build NIM (Llama 3.2 90B Vision direto).\n"
            . "• *Sobrevivência Automática:* Se o Gemini ou OpenCode esgotarem tokens (429/cota), o sistema comuta instantaneamente para o NVIDIA Build. Você nunca fica sem resposta!\n"
            . "• `/models` - Atalhos de modelos recomendados.\n"
            . "• `/model <nome>` - Troca dinâmica de modelo.\n\n"
            . "4️⃣ *ÁUDIO COM FALLBACK WHISPER (STT)*\n"
            . "• Normalização com FFmpeg (16kHz mono MP3).\n"
            . "• *Cascata:* 1º Groq Turbo ➔ 2º Groq v3 ➔ 3º OpenRouter Whisper.\n\n"
            . "5️⃣ *SEGURANÇA DEFENSIVA (Hardening)*\n"
            . "• *2FA PIN:* Sessão tranca por inatividade. `/unlock <PIN>` e `/lock`.\n"
            . "• *Chroot Jail:* Navegação com `/cd` bloqueada fora da pasta base de projetos.\n"
            . "• *DLP:* Mascaramento automático de chaves em logs e respostas.\n"
            . "• *Zero Segredos:* Credenciais isoladas no `.env` com permissão Unix `0600`.\n\n"
            . "6️⃣ *DIRETRIZES DO AI.MD & SANITIZAÇÃO*\n"
            . "• Princípios: KISS, YAGNI, Clean Code e SOLID.\n"
            . "• Algoritmo de limpeza que remove `<thought>`, ruídos de tasks e preâmbulos.";

        $this->sendTelegramMessage($this->allowedChatId, $info);
    }

    private function sendRecentLogs(): void
    {
        if (!file_exists($this->logFile)) {
            $this->sendTelegramMessage($this->allowedChatId, "ℹ️ Nenhum log gravado até o momento.");
            return;
        }

        $lines = file($this->logFile);
        $lastLines = array_slice($lines ?: [], -20);
        $logText = implode("", $lastLines);

        $redactedText = $this->redactSensitiveData($logText);

        $this->sendTelegramMessage($this->allowedChatId, "📋 *Últimos Logs (DLP Ativo):*\n```\n{$redactedText}\n```", 'Markdown');
    }

    private function stripAnsi(string $text): string
    {
        $pattern = '/\x1b(\[[0-9;]*[a-zA-Z]|\([a-zA-Z]|\].*?(\x07|\x1b\\\))/';
        $clean = preg_replace($pattern, '', $text);
        return str_replace(["\r\n", "\r"], "\n", $clean ?? $text);
    }

    private function redactSensitiveData(string $text): string
    {
        $patterns = [
            '/\b\d{8,12}:[A-Za-z0-9_-]{35}\b/' => '[REDACTED_TELEGRAM_TOKEN]',
            '/\bgsk_[A-Za-z0-9]{30,}\b/'         => '[REDACTED_GROQ_KEY]',
            '/\bnvapi-[A-Za-z0-9_-]{30,}\b/'     => '[REDACTED_NVIDIA_KEY]',
            '/\bsk-or-v1-[A-Za-z0-9]{30,}\b/'    => '[REDACTED_OPENROUTER_KEY]',
            '/\bsk-[A-Za-z0-9]{30,}\b/'          => '[REDACTED_API_KEY]',
            '/Bearer\s+[A-Za-z0-9._-]+/i'       => 'Bearer [REDACTED_TOKEN]',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $text) ?? $text;
    }

    // ========================================================================
    // TELEGRAM API CLIENT
    // ========================================================================

    private function getMe(): ?array
    {
        $url = "https://api.telegram.org/bot{$this->botToken}/getMe";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            return null;
        }

        $json = json_decode((string)$response, true);
        return ($json['ok'] ?? false) ? $json['result'] : null;
    }

    private function pollUpdates(int $timeoutSeconds = 3): array
    {
        $offset = $this->lastUpdateId > 0 ? $this->lastUpdateId + 1 : 0;
        $url = "https://api.telegram.org/bot{$this->botToken}/getUpdates?offset={$offset}&timeout={$timeoutSeconds}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeoutSeconds + 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err || !$response) {
            if ($err) {
                $this->log("Erro cURL getUpdates: {$err}", "ERROR");
            }
            return [];
        }

        $json = json_decode((string)$response, true);
        if (isset($json['ok']) && $json['ok'] === true && !empty($json['result'])) {
            return $json['result'];
        }

        return [];
    }

    private function flushPendingUpdates(): void
    {
        $url = "https://api.telegram.org/bot{$this->botToken}/getUpdates?offset=-1";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $json = json_decode((string)$response, true);
            if (!empty($json['result'][0]['update_id'])) {
                $this->lastUpdateId = (int)$json['result'][0]['update_id'];
            }
        }
    }

    private function getTelegramFilePath(string $fileId): ?string
    {
        $url = "https://api.telegram.org/bot{$this->botToken}/getFile?file_id=" . urlencode($fileId);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            return null;
        }

        $json = json_decode((string)$response, true);
        return $json['result']['file_path'] ?? null;
    }

    private function downloadTelegramFile(string $filePath, string $destPath): bool
    {
        $url = "https://api.telegram.org/file/bot{$this->botToken}/{$filePath}";
        $fp = fopen($destPath, 'w+');
        if (!$fp) {
            return false;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $success = curl_exec($ch);
        curl_close($ch);
        fclose($fp);

        return $success !== false;
    }

    public function sendTelegramMessage(int $chatId, string $text, ?string $parseMode = 'Markdown'): bool
    {
        $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";
        $payload = [
            'chat_id' => $chatId,
            'text'    => $text,
        ];

        if ($parseMode !== null) {
            $payload['parse_mode'] = $parseMode;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $json = json_decode((string)$response, true);

        if (isset($json['ok']) && $json['ok'] === false && $parseMode !== null) {
            unset($payload['parse_mode']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
            $response = curl_exec($ch);
        }

        curl_close($ch);
        return true;
    }

    public function sendChunkedTelegramMessage(int $chatId, string $text): void
    {
        $maxLength = 3800;
        $length = mb_strlen($text, 'UTF-8');

        if ($length <= $maxLength) {
            $this->sendTelegramMessage($chatId, $text, null);
            return;
        }

        $offset = 0;
        $chunkIndex = 1;
        $totalChunks = (int)ceil($length / $maxLength);

        while ($offset < $length) {
            $sub = mb_substr($text, $offset, $maxLength, 'UTF-8');
            $header = "📦 *Parte {$chunkIndex}/{$totalChunks}:*\n";
            $this->sendTelegramMessage($chatId, $header . $sub, null);
            $offset += $maxLength;
            $chunkIndex++;
            usleep(250000);
        }
    }

    // ========================================================================
    // LOG & SHUTDOWN
    // ========================================================================

    public function shutdown(): void
    {
        $this->log("Desligando Far-Control Daemon...", "INFO");
        $this->isRunning = false;
        exit(0);
    }

    private function log(string $msg, string $level = "INFO"): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $sanitizedMsg = $this->redactSensitiveData($msg);
        $line = "[{$timestamp}] [{$level}] {$sanitizedMsg}\n";
        echo $line;
        @file_put_contents($this->logFile, $line, FILE_APPEND);
    }
}

// ============================================================================
// BOOTSTRAP DO DAEMON
// ============================================================================

$daemon = new FarControlDaemon();
$daemon->run();
