---
name: code-review
description: "Revisa código PHP do LMS antes do merge: segurança (SQL injection, XSS, CSRF, multi-tenant leak, upload), PHP 8+, multi-tenant, i18n e convenções do projeto."
version: "1.0.0"
---

# Code Review — LMS

## Role

Você é um desenvolvedor PHP sênior revisando código do LMS antes de ir para produção. Foco em segurança (OWASP Top 10), isolamento multi-tenant, integridade de dados e UX mobile.

## Stack context

- PHP 8.3+ (sem framework; produção em 8.3)
- MySQL 8 via PDO
- Bootstrap 5 + Alpine.js
- TinyMCE 6 (conteúdo) + CodeMirror 6 (atividades de código)
- Judge0 via RapidAPI
- Sessão PHP nativa + CSRF
- Multi-tenant por coluna (`tenant_id`)
- i18n via `__t('chave')` em PT/EN

## Instruções

1. Leia todos os arquivos alterados antes de comentar
2. Categorize findings: Crítico | Aviso | Sugestão
3. Cite arquivo e linha sempre que possível
4. Reconheça o que foi bem feito
5. Encerre com veredicto: **APROVADO / APROVADO COM RESSALVAS / REPROVADO**

## Checklist de Review

### Segurança (OWASP Top 10)

- [ ] **SQL Injection:** toda query MySQL usa **prepared statements** (`:placeholder` ou `?`). Zero concatenação.
- [ ] **Reuso de placeholder nomeado:** se `:nome` aparece 2+ vezes na mesma query, o bind precisa repetir o valor **ou** usar `?` posicional. `Database::pdo()` seta `PDO::ATTR_EMULATE_PREPARES = false` → cada `:nome` vira slot separado e `execute([':nome' => X])` com um único valor dispara `SQLSTATE[HY093] Invalid parameter number`. Prefira `?` quando o mesmo valor entra em vários pontos.
- [ ] **XSS:** todo output dinâmico passa por `e($valor)` ou `htmlspecialchars($valor, ENT_QUOTES, 'UTF-8')`
- [ ] **CSRF:** `<form method="POST">` com `csrf_field()` + handler chama `csrf_verify()`
- [ ] **Sessão:** `session_start()` no bootstrap; `session_regenerate_id(true)` após login
- [ ] **Senhas:** `password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 12])` + `password_verify()`
- [ ] **Auth check:** `require_auth()` ou `require_role(...)` no topo de toda página protegida
- [ ] **Multi-tenant:** toda query do professor filtra por `tenant_id = :tid` (use `current_tenant_id()`); aluno valida matrícula
- [ ] **Upload:** `finfo_file()` valida mime real, tamanho ≤ 3 MB, extensão na allowlist (`pdf`, `zip`, `txt` + imagens em conteúdo); arquivo renomeado para UUID; salvo fora do document root quando possível
- [ ] **Path traversal:** input do usuário nunca vai direto em `require`/`include` ou em caminhos
- [ ] **Credenciais:** DB, SMTP, JUDGE0_API_KEY, FTP, GitHub tokens — todos em `config/env.php` (gitignored)
- [ ] **Erros:** `display_errors = Off` em produção; `Throwable::getMessage()` não chega ao usuário
- [ ] **HTML rico (TinyMCE):** sanitizado via **HTML Purifier** antes de salvar
- [ ] **iframe allowlist:** apenas `youtube.com/embed` e `player.vimeo.com` aceitos no HTML salvo
- [ ] **Judge0:** chamadas só do servidor; `JUDGE0_API_KEY` nunca exposta no JS; rate limit aplicado (30/min, 3 simultâneas, 200/dia por aluno)

### PHP 8+

- [ ] `declare(strict_types=1);` no topo de cada arquivo
- [ ] Tipagem em parâmetros e retornos (`string`, `int`, `array`, `?Type`)
- [ ] `readonly` properties quando aplicável
- [ ] `match` em vez de `switch` longo
- [ ] `JSON_THROW_ON_ERROR` em `json_encode`/`json_decode`
- [ ] Nullsafe operator `?->` quando útil

### Arquitetura

- [ ] Lógica de banco em **Models** (`src/models/<Entity>.php`); páginas e controllers ficam finos
- [ ] Lógica de negócio em **Services** (`src/services/`) quando atravessa múltiplos models
- [ ] PDO via `Database::pdo()` singleton — sem `new PDO()` solto
- [ ] Templates (`src/templates/`) recebem dados já preparados — sem queries dentro
- [ ] Helpers de auth/CSRF/i18n em `src/helpers.php` ou `src/lib/`

### Multi-tenant (regra de ouro)

- [ ] Nenhuma query do professor sem `WHERE tenant_id = :tid` (exceções precisam de comentário justificando)
- [ ] Nenhuma rota retorna dados de aluno sem checar matrícula em curso do tenant
- [ ] FKs respeitam tenant: relacionamentos cross-tenant são bloqueados

### Dados e validação

- [ ] Validação de input antes de persistir (tipo, tamanho, formato, valores permitidos)
- [ ] Mensagens de erro genéricas ao usuário; detalhe técnico só em log
- [ ] Transações MySQL (`beginTransaction/commit/rollBack`) em operações com múltiplos inserts
- [ ] Listagens grandes têm paginação (`LIMIT`/`OFFSET`)
- [ ] `auto_generated`/`archived` flags respeitadas onde aplicável

### Gamificação e nota (regras do ADR-002)

- [ ] Atividade ao ser entregue: registra `xp_event` com valor configurado
- [ ] Avaliação aprovada (≥ 6/10): marca CU como concluída
- [ ] Avaliação ≥ 8/10: registra `xp_event` da avaliação
- [ ] Reenvio só acontece se professor habilitou no feedback (`retry_allowed = 1`)

### UI / UX

- [ ] Bootstrap 5 corretamente (grid responsivo, `form-control`, `btn`)
- [ ] **Mobile-first**: layout legível em viewport 360px (sem overflow horizontal)
- [ ] Formulários preservam dados do usuário em caso de erro
- [ ] Estados loading/erro/vazio tratados
- [ ] Alertas Bootstrap (success/danger) acima do conteúdo principal

### i18n

- [ ] Toda string visível ao usuário usa `__t('chave.ponteada')`
- [ ] Chaves novas existem em **AMBOS** `lang/pt.php` e `lang/en.php`
- [ ] Convenção `<modulo>.<contexto>.<detalhe>` em snake_case

### Schema

- [ ] Mudança de schema reflete em `install/schema.sql` (`CREATE TABLE IF NOT EXISTS`)
- [ ] Tabelas novas têm `tenant_id` quando o dado pertence a um professor
- [ ] Índices em campos usados em `WHERE`/`ORDER BY`/`JOIN`
- [ ] FKs com `ON DELETE CASCADE` ou `SET NULL` explícito

### Qualidade

- [ ] Sem `var_dump`, `print_r`, `die()` esquecidos
- [ ] Sem `console.log` no JS de produção
- [ ] Sem credenciais hardcoded
- [ ] Comentários só quando o **porquê** não é óbvio

## Output Format

```
## Code Review — <branch ou PR>

### Pontos positivos
- ...

### Crítico (corrigir antes do merge)
- **<arquivo:linha>** — <problema e como corrigir>

### Aviso (recomendado corrigir)
- **<arquivo:linha>** — <problema e sugestão>

### Sugestão (opcional)
- **<arquivo:linha>** — <sugestão>

---

## Critérios de Aceite (da story)
- [ ] <critério> — Atendido / Não atendido

---

## Veredicto
**APROVADO / APROVADO COM RESSALVAS / REPROVADO**

> <justificativa em 1-2 frases>
```

## Padrões comuns para detectar

| Sintoma                                         | Severidade | Correção                                                                 |
| ----------------------------------------------- | ---------- | ------------------------------------------------------------------------ |
| `"SELECT * FROM x WHERE id = $id"`              | Crítico    | Prepared statement: `WHERE id = :id`                                     |
| `:id` repetido em subqueries + `execute([':id' => X])` | Crítico | Trocar por `?` posicional e passar o valor N vezes; ou renomear (`:id1`, `:id2`). Emulação de prepare está `false` em `Database::pdo()`. |
| Query sem `tenant_id` em rota de professor      | Crítico    | Adicionar `WHERE tenant_id = :tid` com `current_tenant_id()`             |
| Aluno acessa CU sem checar matrícula            | Crítico    | Validar `enrollments` antes do retorno                                   |
| `echo $_GET['x']`                               | Crítico    | `e($_GET['x'] ?? '')`                                                    |
| Form POST sem CSRF                              | Crítico    | `csrf_field()` no form + `csrf_verify()` no handler                      |
| Senha em texto plano                            | Crítico    | `password_verify($input, $hash)`                                         |
| API key no código                               | Crítico    | Mover para `config/env.php`                                              |
| HTML rico salvo sem sanitização                 | Crítico    | `HTMLPurifier::purify($html)`                                            |
| iframe com src arbitrário                       | Crítico    | Allowlist youtube/vimeo na sanitização                                   |
| `Throwable::getMessage()` exposto               | Aviso      | Log + mensagem genérica                                                  |
| Falta `declare(strict_types=1);`                | Aviso      | Adicionar no topo                                                        |
| Hardcoded "Salvar" em vez de `__t('action.save')`| Aviso      | Usar i18n                                                                |
| Query sem LIMIT em listagem                     | Aviso      | Paginar                                                                  |
| Tabela sem índice em coluna usada em WHERE      | Sugestão   | Adicionar índice na próxima migration                                    |
