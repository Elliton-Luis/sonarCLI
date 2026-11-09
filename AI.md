# 🧠 AI & Engineering Guidelines (Sonar Protocol)

> **Diretrizes estritas de desenvolvimento, engenharia de software e comunicação para agentes de IA que operam neste repositório.**

---

## 🎯 1. Princípios de Engenharia de Software

### 1.1 KISS (Keep It Simple, Stupid)
* Escreva o código mais simples e legível possível para resolver o problema.
* Evite arquiteturas desnecessariamente complexas, micro-otimizações prematuras ou padrões de projeto forçados.

### 1.2 YAGNI (You Aren't Gonna Need It)
* Implemente **apenas** o que foi explicitamente solicitado.
* Não adicione métodos utilitários "para o caso de precisarmos no futuro", parâmetros opcionais não utilizados ou abstrações hipotéticas.

### 1.3 Clean Code
* **Nomenclatura Clara:** Variáveis, funções e classes devem revelar sua intenção sem necessidade de comentários óbvios.
* **Funções Pequenas e Focadas:** Cada função deve fazer uma única coisa e fazê-la bem.
* **Tipagem Estrita:** Sempre utilize tipagem forte (ex: `declare(strict_types=1);` no PHP, TypeScript estrito, etc.).
* **Tratamento de Erros:** Não silencie exceções com blocos vazios; trate erros de forma explícita e elegante.

### 1.4 SOLID
* **S (Single Responsibility):** Uma classe deve ter um e apenas um motivo para mudar.
* **O (Open/Closed):** Aberto para extensão, fechado para modificação.
* **L (Liskov Substitution):** Subtipos devem ser substituíveis pelos seus tipos base.
* **I (Interface Segregation):** Interfaces granulares e específicas; não force implementação de métodos desnecessários.
* **D (Dependency Inversion):** Dependa de abstrações e interfaces, não de implementações concretas.

---

## ⚡ 2. Regras de Comunicação e Formatação de Resposta (Output Policy)

> **Atenção:** Como o usuário consome as respostas diretamente na tela do celular pelo Telegram, o ruído visual deve ser **ZERO**.

1. **Zero Preâmbulos:**
   * ❌ NUNCA diga: *"Com certeza!"*, *"Certamente!"*, *"Claro, vou fazer isso para você agora mesmo!"*, *"Aqui está o código solicitado:"*.
   * ✅ Vá **direto** ao resultado ou ao bloco de código.

2. **Ultra Conciso:**
   * Se a resposta for código, entregue o código ou o diff limpo.
   * Se precisar explicar algo, faça-o em **no máximo 2 ou 3 frases curtas e objetivas**.

3. **Sem Metadados Internos:**
   * Não emita tags de pensamento (`<thought>`, `<thinking>`).
   * Não repita logs de ferramentas ou saídas de terminal que não foram pedidas.

4. **KISS & Refatorações Cirúrgicas:**
   * Ao editar arquivos, modifique apenas as linhas necessárias. Não reescreva arquivos inteiros sem necessidade.
