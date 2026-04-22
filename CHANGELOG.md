# Changelog

Todos os releases do LMS ficam documentados neste arquivo. O formato segue
[Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e o projeto adota
[Semantic Versioning](https://semver.org/lang/pt-BR/).

## [0.1.0] — 2026-04-22

Primeira release pública. Escopo: **administração + autenticação**. Cobre
Épicos E0 (fundações), E1 (auth e perfil), E2 (gestão de professores) e
E10-03 (SMTP via PHPMailer).

### Novas funcionalidades

#### Autenticação e perfil (Épico E1)
- Login por email + senha com rate limit de 5 tentativas por IP a cada 15 min e rehash oportunista.
- Logout via POST com CSRF.
- Recuperação de senha por email com token one-time (SHA-256 hex) e rate limit de 3 solicitações por hora.
- Edição do próprio perfil (nome, idioma PT/EN, troca de senha); email imutável conforme ADR-021.
- Roteador com middleware de autenticação e autorização; páginas 403/404 amigáveis.

#### Super-admin: gestão de professores (Épico E2)
- Listagem administrativa de professores com busca, filtros, ordenação e paginação; mostra último login, cursos ativos e alunos únicos por professor.
- Cadastro administrativo de professor (cria `users` + `tenants` em transação).
- Edição de dados do professor e renome do tenant.
- Ativar/desativar professor (propaga para o tenant; expulsa sessões vivas pelo middleware).
- Toggle de cadastro público (placeholder — form real é pós-MVP).
- Painel de métricas agregadas do super-admin com gráfico de submissões diárias nos últimos 30 dias e cache de 5 min.
- Reset de senha do professor pelo super-admin, com envio por email ou exibição única na tela.

#### Notificações (Épico E10, parcial)
- SMTP real via PHPMailer (E10-03). `Mailer::send()` envia por SMTP quando `config/env.php` tem `SMTP_HOST/USER/PASS/FROM`; caso contrário, mantém o fallback em `storage/logs/mail-debug.log` (útil para dev sem SMTP).

### Fundações (Épico E0)
- Estrutura de pastas, front controller em `public/`, bootstrap com timezone `Asia/Dubai`, sessão `HttpOnly`/`SameSite=Lax` e autoload por convenção.
- Helpers globais: `e()`, `__t()`, `current_lang()`, `csrf_field()`, `csrf_verify()`, `require_auth()`, `require_role()`, `flash()`, `render_flash()`, `cached_json()`, `setting_get()`, `setting_set()`.
- Singleton `Database::pdo()` com `tx(callable)` e logging em `storage/logs/db.log`.
- Layout Bootstrap 5.3.8 + SRI oficial; mobile-first (ADR-006).
- Schema MySQL inicial com **19 tabelas**, 2 seeds, idempotente (roda duas vezes sem erro).
- i18n PT/EN via `__t('chave.ponteada')` com fallback por idioma do usuário.

### Infraestrutura
- `composer.json` travado em `"php": "^8.2"` + `phpmailer/phpmailer ^6.9`.
- `scripts/deploy/ftp-deploy.mjs` — deploy incremental FTPS baseado em SHA-256 (`.ftp-state.json`) com modos `deploy`, `deploy:dry`, `deploy:force`.
- `scripts/deploy/upload-env.mjs` — upload one-shot de `config/env.php`.
- `package.json` na raiz com scripts `deploy`, `deploy:dry`, `deploy:force`, `upload-env`.

### Decisões registradas (ADRs novas nesta janela)
- ADR-021 email imutável; ADR-022 senha inicial definida pelo professor; ADR-024 professor só desativa (não deleta); ADR-025 owner do tenant é fixo; ADR-026 aluno exclusivo do tenant; ADR-029 sem rate limit próprio para Judge0; ADR-030 sem `audit_log` no MVP (supersede Epic E12).

### Segurança
- CSRF por sessão com TTL de 30 min e consumo one-time após validação.
- Senhas em bcrypt cost 12.
- Rate limit em DB para login e solicitações de reset.
- Erros genéricos em login e forgot (anti-enumeração).
- `safeNext()` bloqueia open-redirect.

### Não incluído nesta release (planejado)
- Épico E3 em diante (catálogo do professor, alunos, conteúdo, atividades, avaliações, Judge0, gamificação, notificações completas, dashboards, deploy orquestrado).
- HTML Purifier (entra em E5 com o editor TinyMCE).
- Tabela `email_failures` e dashboard de métricas de email (entra em E10 completo).

[0.1.0]: https://github.com/markimpdl/lms/releases/tag/v0.1.0
