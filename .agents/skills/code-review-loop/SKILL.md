---
name: code-review-loop
description: >-
  Protocolo rigoroso de revisão de código via subagente independente. Executa inspeção
  estrita (1º Legibilidade, 2º Performance/Qualidade), estabelece loop de correção pai-filho
  limitado a 2 iterações e escalona ao usuário caso persistam falhas.
---

# Code Review Loop (Verificação por Subagente)

Este protocolo é obrigatório sempre que código novo for gerado, refatorado ou modificado.

## Hierarquia de Prioridades
1. **1º Lugar: Legibilidade** (código limpo, autoexplicativo, nomenclatura clara, funções enxutas, KISS).
2. **2º Lugar: Performance e Qualidade** (sem vazamento de recursos, complexidade algorítmica ótima, tratamento correto de erros, tipagem estrita).

## Fluxo Operacional

```mermaid
flowchart TD
    A[Agente Pai cria/modifica código] --> B[Lança Subagente de Review em sessão isolada]
    B --> C{Encontrou falhas?}
    C -->|Não| D[Aprovado: Segue fluxo]
    C -->|Sim: Iteração 1| E[Subagente avisa Pai com lista de falhas]
    E --> F[Agente Pai aplica correções cirúrgicas]
    F --> G[Subagente reavalia o código]
    G --> H{Ainda tem falhas? Iteração 2}
    H -->|Não| D
    H -->|Sim| I[Notifica o usuário com relatório detalhado]
    I --> J[Usuário decide: Aceitar ou Rodar de novo]
```

## Papel do Subagente de Review
- Opera em sessão isolada e com foco estrito em inspeção.
- Não reescreve o projeto; audita cirurgicamente o diff gerado contra:
  - Quebra de sintaxe ou contratos.
  - Violações de legibilidade (nomes crípticos, acoplamento desnecessário, funções gigantes).
  - Problemas de performance, segurança ou regressões.
- Retorna relatório estruturado:
  - `STATUS: APPROVED` se estiver perfeito.
  - `STATUS: REVISION_NEEDED` com lista numerada: `[Arquivo:Linha] - [Falha encontrada] -> [Sugestão de correção]`.

## Regra do Limite de 2 Iterações
1. **Loop 1:** Subagente aponta as falhas -> Agente pai corrige.
2. **Loop 2:** Subagente reavalia -> Se restarem falhas, o agente pai tenta a 2ª correção.
3. **Escalonamento Mandatório:** Se após a 2ª correção ainda restarem falhas não resolvidas, o agente pai **interrompe o loop automaticamente** e envia uma mensagem clara ao usuário descrevendo o problema exato, aguardando a decisão do usuário (aceitar como está ou mandar rodar de novo).
