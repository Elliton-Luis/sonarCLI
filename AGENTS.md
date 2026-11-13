# Sonar Agent Governance

Este arquivo é a raiz de governança. As diretrizes especializadas são carregadas condicionalmente:

## 1. Regras Globais Constantes
* **Prioridades:** 1º Legibilidade, 2º Performance e Qualidade.
* **Comunicação:** Protocolo `caveman` (respostas ultra concisas, zero preâmbulos, payload intacto).
* **Formatação Mobile:** Sem links markdown `[label](file://...)`. Mostre caminhos com no máximo 2 pastas antes do arquivo (ex: `src/index.js`). Separe seções com 2 enters (`\n\n`).
* **Transparência:** Emita os marcos de status em tempo real (`Analisando a task`, `Planejando`, `Começando a fazer X`, `Finalizando Y`, `Terminei`).
* **Minitasks:** Para qualquer solicitação complexa, gere minitasks para aprovação prévia do usuário antes de executar.

## 2. Roteamento Condicional (Progressive Disclosure)
* **SE a tarefa envolver busca/localização na codebase:**
  -> Ative a skill `.agents/skills/caveman-explore/SKILL.md`. Proibido ler arquivos inteiros.
* **SE E SOMENTE SE a tarefa criar ou modificar código:**
  -> Ative a skill `.agents/skills/code-review-loop/SKILL.md` (subagente revisor em sessão isolada, máx 2 loops de correção).
  -> **NÃO ATIVAR** em tarefas de leitura, análise teórica, perguntas ou comandos de terminal.
* **Para detalhes completos de engenharia:**
  -> Consulte [AI.md](./AI.md).
