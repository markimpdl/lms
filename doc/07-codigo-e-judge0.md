# 07 — Código e Judge0

## Objetivo

Permitir que atividades do tipo `codigo` ofereçam ao aluno um editor de código com execução online, sem depender do aluno ter ambiente local configurado. A correção continua **manual** pelo professor nesta fase.

## Linguagens-alvo

- Python 3
- C#
- JavaScript (Node.js)
- HTML (renderizado em iframe sandbox, sem execução server-side)

## Provedor: Judge0

**Judge0** é um serviço de execução de código open-source com API HTTP. Suporta >40 linguagens e é usado em plataformas como CodinGame e LeetCode-like.

Duas opções de deploy:
1. **Judge0 CE hospedado por terceiro** (ex.: RapidAPI). Mais rápido de começar.
2. **Self-host** em um servidor separado (não no cPanel da Hostinger, pois exige Docker).

Decisão inicial: opção 1 (RapidAPI) pela simplicidade. Documentado em `14-decisoes-e-pendencias.md`.

## Fluxo técnico

```
Aluno escreve código no editor
        │
        ▼
Clica "Executar"
        │
        ▼
Frontend chama endpoint PHP /api/code/run
        │
        ▼
Backend chama Judge0 (server-side, chave guardada no servidor)
        │
        ▼
Recebe: stdout, stderr, tempo, status
        │
        ▼
Retorna ao frontend e exibe para o aluno
```

**Importante:** a chave da API do Judge0 **nunca** é exposta ao navegador. Todas as chamadas saem do PHP.

## Editor de código

- Escolha: **CodeMirror 6**. Motivo: bundle muito mais leve que Monaco (Monaco pesa ~5 MB e não rende bem em mobile); CodeMirror 6 é modular (carrega só o suporte das linguagens usadas), com boa experiência em celular.
- Linguagens carregadas: Python, C#, JavaScript, HTML/CSS.
- Requisitos: destaque de sintaxe, autoindent, tema claro/escuro, responsivo.

## Submissão do código

A atividade de código aceita dois modos, à escolha do professor:
1. **Arquivo** — aluno anexa arquivo (zip/txt).
2. **Código inline** — aluno submete o texto do código, que é salvo como submissão e executado via Judge0 se ele clicar "Executar" antes de enviar.

Em ambos os modos a correção é manual.

## Limites e segurança

- Rate limit por aluno: **30 execuções por minuto**, no máximo **3 execuções simultâneas**.
- Cap diário por aluno: **200 execuções/dia**.
- Tamanho máximo do código submetido: 64 KB.
- Timeout de execução no Judge0: 5 segundos (valor padrão do Judge0).
- Sem acesso à rede dentro da execução.
- O professor pode desativar a execução online na atividade (se quiser forçar entrega por arquivo apenas).

Valores dimensionados para <10 alunos simultâneos e <30 alunos totais; revisar se a base crescer.

## HTML como "linguagem"

Para HTML/CSS/JS client-side, a execução é **local no navegador**, dentro de um iframe `sandbox="allow-scripts"`. Não passa pelo Judge0.
