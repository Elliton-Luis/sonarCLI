<?php

/**
 * ============================================================================
 * SONAR BRIDGE: Micro-serviço e Runner CLI para Integração com ATLAS
 * ============================================================================
 * Permite que o ATLAS comande remotamente ou localmente o Sonar para:
 * - Executar comandos nativos Linux no terminal (Zero Tokens)
 * - Controlar repositórios Git (status, diff, pull, commit, push)
 * - Invocar agentes de IA de código (Antigravity 'agy', 'opencode')
 *
 * Suporta dois modos:
 * 1. CLI: php sonar_bridge.php --cmd "git status"
 * 2. HTTP: php -S 127.0.0.1:8765 sonar_bridge.php
 *
 * @license MIT
 * ============================================================================
 */

declare(strict_types=1);

// Carregamento de variáveis de ambiente do Sonar
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
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

class SonarBridge
{
    private string $projectsBaseDir;
    private string $secretToken;

    public function __construct()
    {
        $base = getenv('PROJECTS_BASE_DIR');
        $this->projectsBaseDir = ($base && is_dir($base)) ? realpath($base) : realpath(__DIR__ . '/..') ?: __DIR__;
        $this->secretToken = trim((string)getenv('SONAR_BRIDGE_TOKEN'));
    }

    public function sanitizePath(?string $requestedDir): string
    {
        if (empty($requestedDir) || !is_dir($requestedDir)) {
            return $this->projectsBaseDir;
        }
        $real = realpath($requestedDir);
        if ($real && str_starts_with($real, $this->projectsBaseDir)) {
            return $real;
        }
        return $this->projectsBaseDir;
    }

    public function redactSensitiveData(string $text): string
    {
        $patterns = [
            '/\b\d{8,12}:[A-Za-z0-9_-]{35}\b/' => '[REDACTED_TELEGRAM_TOKEN]',
            '/\bgsk_[A-Za-z0-9]{30,}\b/'         => '[REDACTED_GROQ_KEY]',
            '/\bnvapi-[A-Za-z0-9_-]{30,}\b/'     => '[REDACTED_NVIDIA_KEY]',
            '/\bsk-or-v1-[A-Za-z0-9]{30,}\b/'    => '[REDACTED_OPENROUTER_KEY]',
            '/\bsk-[A-Za-z0-9]{30,}\b/'          => '[REDACTED_API_KEY]',
            '/Bearer\s+[A-Za-z0-9._-]+/i'        => 'Bearer [REDACTED_TOKEN]',
        ];
        return preg_replace(array_keys($patterns), array_values($patterns), $text) ?? $text;
    }

    public function executeShell(string $cmd, ?string $dir = null, int $timeoutSeconds = 30): array
    {
        $workDir = $this->sanitizePath($dir);

        // Normalização de aspas tipográficas mobile
        $cmd = str_replace(["\xe2\x80\x9c", "\xe2\x80\x9d", '“', '”'], '"', $cmd);
        $cmd = str_replace(["\xe2\x80\x98", "\xe2\x80\x99", '‘', '’'], "'", $cmd);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open("exec bash -c " . escapeshellarg($cmd) . " 2>&1", $descriptors, $pipes, $workDir);
        if (!is_resource($process)) {
            return [
                'ok'        => false,
                'exit_code' => -1,
                'output'    => 'Falha ao inicializar o processo bash.',
                'workspace' => $workDir,
                'mode'      => 'native_bash',
            ];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);

        $output = '';
        $startTime = microtime(true);

        while (true) {
            $status = proc_get_status($process);
            while (($chunk = fread($pipes[1], 8192)) !== false && $chunk !== '') {
                $output .= $chunk;
            }
            if (!$status['running']) {
                break;
            }
            if ((microtime(true) - $startTime) > $timeoutSeconds) {
                proc_terminate($process, 9);
                $output .= "\n[!] Processo cancelado por timeout ({$timeoutSeconds}s).";
                break;
            }
            usleep(25000);
        }

        while (($chunk = fread($pipes[1], 8192)) !== false && $chunk !== '') {
            $output .= $chunk;
        }

        fclose($pipes[1]);
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            fclose($pipes[2]);
        }
        $exitCode = proc_close($process);

        $cleanOutput = $this->redactSensitiveData(trim($output));

        return [
            'ok'        => ($exitCode === 0),
            'exit_code' => $exitCode,
            'output'    => $cleanOutput,
            'workspace' => $workDir,
            'mode'      => 'native_bash',
        ];
    }

    public function checkToken(?string $provided): bool
    {
        if (empty($this->secretToken)) {
            return true;
        }
        return hash_equals($this->secretToken, (string)$provided);
    }
}

// ============================================================================
// EXECUÇÃO HTTP OU CLI
// ============================================================================

$bridge = new SonarBridge();

if (php_sapi_name() === 'cli' && (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] === '')) {
    // Modo CLI
    $options = getopt('', ['cmd:', 'dir:', 'agent:', 'prompt:', 'help']);

    if (isset($options['help'])) {
        echo "Uso:\n";
        echo "  php sonar_bridge.php --cmd \"git status\" [--dir /caminho]\n";
        exit(0);
    }

    $cmd = $options['cmd'] ?? '';
    $dir = $options['dir'] ?? null;

    if (empty($cmd)) {
        echo json_encode(['ok' => false, 'error' => 'Nenhum comando fornecido (--cmd).'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit(1);
    }

    $res = $bridge->executeShell($cmd, $dir);
    echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit($res['ok'] ? 0 : 1);
}

// Modo HTTP (Servidor embutido do PHP)
header('Content-Type: application/json; charset=utf-8');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/api/status' || $path === '/' || $path === '/status') {
    echo json_encode([
        'ok'      => true,
        'service' => 'sonar_bridge',
        'status'  => 'online',
        'version' => '1.0.0',
        'time'    => date('Y-m-d H:i:s'),
    ]);
    exit(0);
}

if ($path === '/api/execute' || $path === '/execute' || $path === '/run') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Método não permitido. Utilize POST.']);
        exit(0);
    }

    // Validação de token
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = '';
    if (str_starts_with($authHeader, 'Bearer ')) {
        $token = substr($authHeader, 7);
    } elseif (isset($_GET['token'])) {
        $token = (string)$_GET['token'];
    }

    if (!$bridge->checkToken($token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Token de autorização inválido ou ausente.']);
        exit(0);
    }

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true) ?? [];

    $cmd = trim((string)($payload['command'] ?? $payload['cmd'] ?? ''));
    $dir = isset($payload['dir']) ? (string)$payload['dir'] : null;

    if ($cmd === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'O campo "command" é obrigatório.']);
        exit(0);
    }

    $res = $bridge->executeShell($cmd, $dir);
    echo json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit(0);
}

http_response_code(404);
echo json_encode(['ok' => false, 'error' => 'Rota não encontrada.']);
