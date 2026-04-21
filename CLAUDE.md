# Project Context — LMS

Plataforma LMS mini-SaaS para apoiar cursos presenciais nos Emirados Árabes Unidos. Multi-tenant (cada professor tem seu próprio espaço de dados). Documentação completa em `doc/`.

## Stack

- **Backend:** PHP 8.2+ (sem framework)
- **DB:** MySQL 8 (InnoDB + utf8mb4) via PDO com prepared statements
- **Frontend:** HTML5 + **Bootstrap 5** (CDN) + Alpine.js para interações leves
- **Editor de conteúdo:** TinyMCE 6 community (com plugin `media` para YouTube/Vimeo)
- **Editor de código:** CodeMirror 6 (modular, mobile-friendly)
- **Compilador online:** Judge0 via RapidAPI (Python, C#, JavaScript)
- **Auth:** sessão PHP nativa + token CSRF
- **i18n:** Português e Inglês via `__t('chave.ponteada')`
- **Email:** SMTP da Hostgator + PHPMailer
- **Hospedagem:** Hostinger (plano revenda) + cPanel
- **Multi-tenancy:** por coluna (`tenant_id` em todas as tabelas relevantes)

## Antes de codar — leia

1. `doc/README.md` — índice completo
2. `doc/00-visao-geral.md` — pitch e personas
3. `doc/03-dominio-e-hierarquia.md` — modelo conceitual
4. `doc/12-modelo-de-dados.md` — schema MySQL planejado
5. `doc/14-decisoes-e-pendencias.md` — ADRs (decisões já tomadas)

---

## Fluxo de Desenvolvimento

### 1. PO escreve a demanda

O dono do produto (o professor) descreve a feature em linguagem natural com critérios de aceite.

### 2. Story Breakdown

```
/story-breakdown
```

Quebra a demanda em **Épicos** e **Histórias** com critérios de aceite. Aprovação antes de seguir.

### 3. Issues no GitHub

Após aprovação, criar issues via **GitHub MCP** usando templates em `.github/ISSUE_TEMPLATE/` (a serem criados):

- `epic.md`, `story.md`, `bug_report.md`

### 4. Desenvolvimento por história

1. Criar branch: `feature/ISSUE-ID-nome-curto`
2. Implementar respeitando as convenções abaixo
3. Conventional Commits
4. Rodar `/code-review` antes do PR
5. Abrir PR usando o template (a criar) `.github/pull_request_template.md`

### 5. Code Review

```
/code-review
```

Revisão automática de segurança e qualidade. Veredicto: **APROVADO / APROVADO COM RESSALVAS / REPROVADO**.

### 6. Release

```
/release-prep
```

Analisa commits, sugere bump semver e gera CHANGELOG.

### 7. Deploy

```
/ftp-deploy
```

Deploy incremental via FTPS para a Hostinger. _Pendente das credenciais FTP._

---

## Branching Strategy

```
main          ← produção
develop       ← integração
feature/123-nome-da-historia   ← feature
release/1.2.0 ← preparação de release
hotfix/1.2.1  ← correção urgente em produção
```

- Nunca commitar direto em `main` ou `develop`
- PRs de `feature/*` apontam para `develop`
- PRs de `release/*` e `hotfix/*` apontam para `main` (e depois merge de volta em `develop`)
- Após merge em `main`, criar tag: `git tag v1.2.0`

---

## Conventional Commits

```
feat: adiciona ranking semanal por grupo
fix: corrige cálculo de XP quando aluno não tem matrícula ativa
chore: atualiza Bootstrap para 5.3.x
refactor: extrai lógica de submissão para SubmissionService
docs: atualiza doc de gamificação
feat!: muda contrato da API de notificações (BREAKING CHANGE)
```

| Prefixo                        | Bump          |
| ------------------------------ | ------------- |
| `feat:`                        | MINOR (1.1.0) |
| `fix:`                         | PATCH (1.0.1) |
| `feat!:` / `BREAKING CHANGE`   | MAJOR (2.0.0) |
| `chore:`, `refactor:`, `docs:` | Nenhum        |

---

## Skills disponíveis

### Processo

- `/story-breakdown` — quebra demanda do PO em épicos e histórias
- `/code-review` — revisão de PR antes do merge
- `/release-prep` — gera CHANGELOG, sugere bump semver

### Desenvolvimento PHP

- `/php-mysql-model` — Model PHP 8 com PDO, prepared statements, isolamento por tenant
- `/php-page-generator` — Página PHP com auth, CSRF, Bootstrap 5, i18n
- `/mysql-schema` — Migration MySQL InnoDB + utf8mb4
- `/i18n-sync` — Sincroniza `lang/pt.php` × `lang/en.php` × código

### Operação

- `/ftp-deploy` — deploy incremental via FTPS (pendente credenciais)

---

## MCPs configurados

- **github** — issues, PRs, gerenciamento do repositório

> Token configurado em `.mcp.json` (gitignored). Para trocar: GitHub → Settings → Developer settings → Personal access tokens.

---

## Convenções de código (PHP/MySQL)

### Sempre

- `declare(strict_types=1);` no topo de todo arquivo PHP
- PDO com **prepared statements** (`:placeholder` ou `?`) — **zero concatenação** em SQL
- Toda query do **professor**: filtra por `tenant_id = :tid` (use o helper `current_tenant_id()`)
- Toda query do **aluno**: valida matrícula ativa antes de retornar dados de cursos
- Toda saída dinâmica: `e($valor)` (alias de `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`)
- Todo `<form method="POST">`: `csrf_field()` no form + `csrf_verify()` no handler
- Toda página protegida: `require_auth()` ou `require_role('teacher'|'student'|'super_admin')` no topo
- Toda string visível ao usuário: `__t('chave.ponteada')` — nunca hardcode em pt/en
- Senhas: `password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 12])` + `password_verify`
- Conteúdo HTML rico (TinyMCE): **sanitizar com HTML Purifier** antes de salvar
- iframe: allowlist `youtube.com/embed` e `player.vimeo.com` (qualquer outro removido)
- Uploads: `finfo_file` para validar mime real, máximo **3 MB**, extensões aceitas: `pdf`, `zip`, `txt` (+ imagens em conteúdo)
- Senha do Judge0, SMTP, DB: sempre em `config/env.php` fora do repo (carregado via include)

### Nunca

- `var_dump`, `print_r`, `die()` esquecidos
- Credenciais hardcoded (DB, SMTP, Judge0, FTP, GitHub)
- `Throwable::getMessage()` direto para o usuário (logar + mensagem genérica)
- Concatenação de input em `require`/`include` (path traversal)
- HTML rico salvo sem sanitização

---

## Multi-tenancy: regra de ouro

- **Professor:** toda query precisa de `WHERE tenant_id = :tid`. Use o repositório/helper que injeta automaticamente — não construa queries sem ele.
- **Aluno:** toda query precisa validar matrícula em curso ou pertencimento a tenant. Aluno **nunca** acessa dados de tenants em que não está matriculado.
- **Super-admin:** opera fora do tenant; tem visibilidade administrativa, mas NÃO edita conteúdo dos professores.

---

## Estrutura de pastas (planejada)

```
lms/
├── public/                  document root web
│   ├── index.php            front controller
│   ├── assets/              css/js compilado
│   └── uploads/             (gitignored, criado em produção)
├── src/
│   ├── pages/               páginas PHP
│   ├── controllers/
│   ├── models/              um arquivo por tabela
│   ├── services/            lógica de negócio
│   ├── lib/                 Auth.php, MySQL.php, Mailer.php, Judge0.php
│   ├── templates/           layout + partials
│   └── helpers.php          __t(), e(), csrf_*, require_auth, etc.
├── lang/
│   ├── pt.php
│   └── en.php
├── config/
│   ├── env.php              (gitignored — segredos)
│   └── env.example.php
├── install/
│   └── schema.sql           schema completo + seeds para rodar no phpMyAdmin
├── scripts/
│   ├── cron/                rotinas de cron do cPanel
│   └── deploy/              ftp-deploy.mjs (a configurar)
├── doc/                     documentação (versionada)
├── examples/                (gitignored — referências de outros projetos)
├── .claude/
│   ├── settings.json
│   └── skills/              skills locais do projeto
├── .mcp.json                (gitignored — contém token)
├── .gitignore
└── CLAUDE.md
```

---

## Arquivos sensíveis — NUNCA versionar

- `config/env.php`, `.env`, `.env.deploy`
- `.mcp.json` (contém token GitHub)
- `.claude/settings.local.json`
- `examples/` (referências copiadas de outros projetos)
- `storage/uploads/`, `public/uploads/`

Tudo já listado em `.gitignore`.
