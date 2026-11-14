# 🧠 AI & Engineering Guidelines (Sonar Protocol)

> **Diretrizes de engenharia e roteamento condicional para agentes no Sonar.**

4. **Execução Autônoma de Agentes (Zero Bloqueio Interativo):**
   - O `agy` (Antigravity CLI) **SEMPRE** deve ser chamado com a flag `--dangerously-skip-permissions` para não travar aguardando confirmação de terminal.
   - O `opencode` (OpenCode CLI) **SEMPRE** deve ser chamado com a flag `--auto` para execução autônoma ininterrupta.

---

## 🏛️ 1. Hierarquia de Decisão (Progressive Disclosure)

O agente deve carregar habilidades e protocolos apenas sob demanda estrita:

1. **Tarefas de Leitura / Análise / Perguntas:**
   - Responda diretamente no estilo `caveman`.
   - **PROIBIDO** acionar subagentes de code review ou mutações.
2. **Tarefas de Busca e Exploração:**
   - Dispare a skill `caveman-explore`.
   - Utilize comandos Linux cirúrgicos (`grep`, `find`, `sed`, `awk`).
   - Jamais leia arquivos inteiros sem necessidade.
3. **Tarefas de Implementação / Refatoração de Código:**
   - **1º Lugar: Legibilidade** (KISS, Clean Code, funções enxutas, nomenclatura autoexplicativa).
   - **2º Lugar: Performance e Qualidade** (SOLID, tipagem forte, tratamento explícito de exceções).
   - **Acionamento do Subagente:** Ao concluir o código, dispare a skill `code-review-loop` em sessão separada.
   - **Loop de Correção:** Máximo de 2 tentativas de correção entre pai e revisor. Se persistir falha, notifique o usuário para decisão.

---

## 📢 2. Fluxo Operacional & Comunicação

1. **Status em Linha Única:**
   - `Analisando a task`
   - `Planejando`
   - `Começando a fazer X`
   - `Finalizando Y`
   - `Terminei`
2. **Aprovação Prévia de Minitasks:**
   - Apresente as minitasks antes da execução.
   - Aguarde o "sim" / aprovação do usuário.
3. **Concisão Caveman:**
   - Sem preâmbulos, saudações ou floreios.
   - Payload de código e comandos 100% literais.

---

## 📱 3. Output Limpo & Formatação para Telegram (Zero Poluição)

Como as respostas são lidas no celular via Telegram, siga rigorosamente:

1. **Sem Links Markdown:**
   - NUNCA use sintaxe de link markdown `[texto](file://...)`. O Telegram mobile não abre links locais e poluí a tela.
   - Cite o nome do arquivo diretamente como texto simples.
2. **Caminhos Curtos (Máximo 2 Pastas):**
   - NUNCA exiba caminhos absolutos longos como `/home/usuario/projetos/repo/sub/src/arquivo.js`.
   - Mostre no máximo 2 pastas antes do arquivo: `src/arquivo.js` ou `sub/src/arquivo.js`.
3. **Hierarquia Limpa com 2 Enters (\n\n):**
   - Separe tópicos, listas e seções com duas quebras de linha (`\n\n`) para leitura agradável.
   - Evite poluição de asteriscos (`**`) ou crases repetitivas no chat.
