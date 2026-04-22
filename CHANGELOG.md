# Changelog

Todos os releases do LMS ficam documentados neste arquivo. O formato segue
[Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e o projeto adota
[Semantic Versioning](https://semver.org/lang/pt-BR/).

## [0.2.0] — 2026-04-23

Segunda release. Escopo: **estrutura pedagógica do professor** (Curso → Core
Competency → Competence Unit). Cobre o Épico E3 inteiro; primeira release
validada ponta a ponta em produção por smoke test manual.

### Novas funcionalidades

#### Catálogo do professor (Épico E3)
- CRUD de Curso com listagem paginada, busca, filtros por status e ordenação clicável; arquivamento reversível que preserva CCs/CUs filhos (E3-01).
- CRUD de Core Competency com modal Bootstrap para criar e renomear; reordenação por swap + renormalização de positions em `0..N-1` (E3-02).
- CRUD de Competence Unit no mesmo padrão da CC; validação estrita de URL composta `/teacher/courses/{c}/cc/{cc}` — CC precisa pertencer ao curso, não basta ser do tenant (E3-03).
- Breadcrumbs dinâmicos e sidebar/offcanvas de árvore curricular colapsável em toda página dentro de um curso, com helpers `curriculum_tree()` e `breadcrumbs()` (E3-04).
- Exclusão destrutiva com confirmação por digitação do nome (case-sensitive); modal único por página populado via `show.bs.modal`; cascade do schema InnoDB cuida dos descendentes; `format_delete_counts()` já traduz as contagens (E3-05).

### Mudanças de schema
- `courses.archived_at DATETIME NULL` e índice `idx_courses_tenant_archived (tenant_id, archived)`.
- Bases novas aplicam via `CREATE TABLE IF NOT EXISTS`; bases com v0.1.0 aplicam pela nova seção **Migrações incrementais** do `install/schema.sql` (idempotente via `INFORMATION_SCHEMA` + `PREPARE/EXECUTE`, funciona em MySQL 8+ e MariaDB 10.5+). Padrão consolidado para futuras mudanças sem migrations versionadas (ADR-017).

### Correções

- **`tenant_id` do professor nunca era resolvido na sessão.** `AuthController::authenticate` lia `users.tenant_id` direto (sempre NULL por `chk_users_role_tenant`); o elo real é `tenants.owner_user_id = users.id` (ADR-025). Qualquer página `/teacher/*` que usa `current_tenant_id()` dava 403 após o login. SELECT passou a fazer `LEFT JOIN tenants t ON t.owner_user_id = u.id AND t.active = 1` + `COALESCE(t.id, u.tenant_id) AS tenant_id`. Sessões anteriores ao fix precisam de logout/login para repopular.
- **`SQLSTATE[HY093] Invalid parameter number` em `countDescendants`.** Três models (`Course`, `CoreCompetency`, `CompetenceUnit`) reusavam o placeholder nomeado `:id` na mesma query. Com `PDO::ATTR_EMULATE_PREPARES = false` em `Database::pdo()`, cada ocorrência de `:id` é um slot separado e `execute([':id' => X])` dispara o erro. Trocado por `?` posicional com o valor repetido.

### Convenções consolidadas nesta janela
- **Modais Bootstrap** (não form inline) para criar e renomear entidades; um modal único por página populado via `show.bs.modal` + `data-*`.
- **Renomear via modal** (não inline edit double-click).
- **Breadcrumbs só dentro de um curso** — não aparecem no dashboard nem na listagem.
- **Reordenação com swap + renormalização** de positions em `0..N-1` ao final de cada operação — dispensa gaps.
- **Rotas com params capturados por regex** em `role_patterns` aceitam múltiplas capturas (ex.: `/teacher/cc/{id}/move-{up|down}`).

### Tooling
- Skill `/code-review` ganhou regra explícita para reuso de placeholder nomeado em query PDO (severidade Crítico), formalizando o gotcha descoberto no smoke test.
- `package.json` bumpado para 0.2.0.

### Pendências registradas
- `doc/99-pendencias-tecnicas.md` recebeu as entradas "Resolvidas" dos dois bugs acima. Sem novas pendências desta janela além das que já estavam na lista.

[0.2.0]: https://github.com/markimpdl/lms/releases/tag/v0.2.0

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
