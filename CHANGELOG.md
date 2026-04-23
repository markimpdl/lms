# Changelog

Todos os releases do LMS ficam documentados neste arquivo. O formato segue
[Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e o projeto adota
[Semantic Versioning](https://semver.org/lang/pt-BR/).

## [0.3.0] — 2026-04-23

Terceira release. Escopo: **gestão de alunos pelo professor** — Épico E4 inteiro, do cadastro individual até o reset de senha. Primeira release deployada por story (FTPS incremental) com smoke test consolidado no fim do épico.

### Novas funcionalidades

#### Alunos, matrículas e grupos (Épico E4)
- Cadastro, edição, desativação e exclusão de aluno pelo professor com envio opcional de credenciais por email; listagem paginada com busca, filtro de status e ordenação clicável; exclusão destrutiva com confirmação por digitação do email (E4-01).
- Matrícula de aluno em curso(s) do tenant com atalho no próprio POST de cadastro; lote idempotente via UK composta `(student_user_id, course_id)` + `INSERT IGNORE`; listagens espelhadas em `/teacher/students/{id}` e `/teacher/courses/{id}`; cursos arquivados aparecem com badge mas preservam matrícula (E4-02).
- CRUD de grupos com nome único por tenant (`uk_groups_tenant_name` + pré-check + `catch PDOException 23000` defensivo); `groups` com backticks obrigatórios (palavra reservada MySQL 8); exclusão com confirmação por digitação (E4-03).
- Atribuição e remoção de alunos em grupos pelos dois lados (modal no grupo e modal no aluno); validação cross-tenant via JOIN em ambos os lados (`users.tenant_id` AND `groups.tenant_id`); idempotente via PK composta (E4-04).
- Reset de senha do aluno pelo professor via modal fullscreen-sm-down na tela de detalhe, com "gerar senha forte" e envio opcional por email; `password_changed_at` com timestamp PHP literal para invalidar sessões ativas no middleware E1-05; transient de sessão com `student_id` validado para exibir a senha uma única vez quando SMTP off (E4-05).

### Correções

- **`curriculum_nav.php` clobberava `$cc` e `$ccId` do escopo da página hospedeira.** O partial usava essas variáveis como loop vars; como `require` em PHP compartilha escopo, o `foreach` vazava o último item da árvore, fazendo `/teacher/courses/{c}/cc/{cc}` exibir o nome da última CC no `<h1>` e direcionar CUs criadas pra ela quando qualquer CC não-última era aberta. Todas as variáveis do partial passaram a usar prefixo `$nav*`.
- **Parse error em `src/pages/teacher/students/index.php`.** A cláusula `use ()` vazia numa closure é syntax error em PHP — o 500 ocorria antes de qualquer handler, devolvendo tela em branco. Removida a captura vazia; `php -l` agora passa e a memória do projeto (`feedback_php_lint_before_commit.md`) registrou a obrigatoriedade de lint antes de commit.
- **`current_lang()` lia chave errada da sessão.** Procurava `current_user()['lang']` mas `AuthController::completeLogin` grava como `language` (espelhando `users.language`). O fallback `'pt'` sempre ganhava — bug existia desde E0-02 (commit `2dffb9a`, pré-v0.1.0), mas só ficou visível agora com o primeiro usuário real de idioma EN. Fix: trocar a chave para `language`.
- **`SQLSTATE[HY093]` em `Enrollment::listByCourse`.** Mistura de `?` posicional com `:limit`/`:offset` nomeados no mesmo statement quebrava com `ATTR_EMULATE_PREPARES=false`. Uniformizado tudo para posicional (mesmo padrão da query de COUNT acima).

### Convenções consolidadas nesta janela
- **Isolamento de escopo em partials PHP** — toda variável interna usa prefixo `$nav*`/`$part*`/`$tpl*` conforme o partial para não vazar via `require`.
- **Idempotência em many-to-many** — UK/PK composta + `INSERT IGNORE` absorve race sem SQLSTATE 23000.
- **Validação de tenant em pivôs** — JOIN em ambos os lados (`users.tenant_id` AND `groups.tenant_id`); nunca confiar em só um.
- **`u.role = 'student'`** em toda query de `Enrollment`/`GroupMember` como defesa extra contra lixo hipotético.
- **Transients de credenciais pós-ação (cadastro / reset)** — validados por `student_id` / `teacher_id` antes de exibir para não vazarem entre telas.
- **Placeholders PDO** — ou tudo `?` posicional ou tudo `:nome` nomeado; nunca misturar com `EMULATE_PREPARES=false`. Regra já estava no `/code-review`; reforçada.

### Tooling
- `/ftp-deploy` ativado em produção — `.env.deploy` preenchido, `basic-ftp` instalado, `.ftp-state.json` mantém hash SHA-256 incremental. Epic E4 inteiro subiu story-por-story (30 arquivos no primeiro push de hoje + 3 hotfixes deployados isoladamente).
- Memória de projeto ganhou `feedback_autodeploy_after_merge.md` (rodar deploy sem perguntar após merge) e `feedback_php_lint_before_commit.md` (lint obrigatório).
- `package.json` bumpado para 0.3.0.

### Pendências
- **Cross-tenant smoke test não executado** — produção tem apenas 1 tenant (Marcos Ortolani). Código foi auditado em code-review, mas a validação manual com segundo tenant ficará para quando um 2º professor for cadastrado.
- Remover `public/_diag_students.php` do servidor via cPanel File Manager (stub 410 já está deployado; arquivo físico ainda presente).

[0.3.0]: https://github.com/markimpdl/lms/releases/tag/v0.3.0

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
