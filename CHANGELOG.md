# Changelog

Todos os releases do LMS ficam documentados neste arquivo. O formato segue
[Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e o projeto adota
[Semantic Versioning](https://semver.org/lang/pt-BR/).

## [0.5.0] — 2026-04-23

Quinta release. Escopo: **atividades — ciclo aluno produz, professor corrige** — Épico E6 inteiro + redesign visual do painel do aluno em cards com anel de progresso. É a primeira release onde o fluxo pedagógico fecha de ponta a ponta: aluno lê conteúdo (E5), entrega atividade, ganha XP, recebe feedback.

### Novas funcionalidades

#### Atividades (Épico E6)
- CRUD de atividade pelo professor com instrução em HTML rico (TinyMCE + ContentSanitizer, padrão E5), seletor de tipo, XP ao entregar, toggle de entrega aberta/fechada, checkbox "execução online de código" com badge "em breve" (Judge0 virá em E8). Alert amarelo no form de edição quando há submissões: "N aluno(s) já enviaram… as entregas existentes permanecem e os alunos não podem reenviar" (E6-01).
- Listagem de atividades na tela da CU com reordenação por swap + renormalização de positions (padrão E3-02), toggle instantâneo de abertura de entrega, contador de submissões clicável (link pra lista de correção) (E6-02).
- Entrega do aluno em `/student/activity/{id}` com upload (PDF/ZIP/TXT ≤ 3 MB, mime real via `finfo_file`) e/ou `code_text` pra atividades do tipo Código. XP creditado automaticamente na primeira entrega via `XpEvents::awardActivity` (ADR-002) — idempotente por UK composite. ADR-027: aluno edita/remove enquanto `feedback_at IS NULL`; após feedback, tela fica readonly. Nome de arquivo determinístico `<student_id>.<ext>` substitui a versão anterior ao reenviar (E6-03).
- Feedback do professor em `/teacher/activity/{id}/submissions` com lista ordenada por pendentes no topo (`feedback_at IS NULL DESC`). Tela de correção individual tem a instrução (readonly), download do arquivo, preview do code_text e textarea de feedback (≤ 4000 chars). Ao salvar dispara fanout stub em `notifications` (padrão E5-06) pronto pra o E10 consumir (E6-04).
- Exclusão permanente de atividade com confirmação por digitação do título (padrão E3-05). Cascade FK apaga `activity_submissions`; `xp_events` é polimórfico e recebe DELETE manual em transação; arquivos físicos e diretório da atividade são removidos pós-commit com validação anti path traversal (E6-05).
- Cards de atividade no painel do aluno em `/student/cu/{id}` com 4 estados (não entregue / entregue / com feedback / entrega fechada). Helpers `student_cu_status` e `student_course_status` deixam de ser placeholders e delegam pro novo model `StudentProgress` — anéis de progresso do dashboard e da tela do curso passam a refletir o estado real automaticamente (E6-06).

#### UX do painel do aluno (antecipação de E6/E7)
- Cards com borda lateral colorida por status (cinza / laranja / verde) e anel circular de % via `conic-gradient` (zero JS). Aplicado no dashboard (cursos) e em `/student/course/{id}` (CUs dentro do curso). Navegação em árvore "curso → CCs → CUs" consolidada (#99).

### Mudanças de schema
- `activities.position INT UNSIGNED NOT NULL DEFAULT 0` — alimenta a reordenação de E6-02.
- `xp_events.uk_xp_student_source (student_user_id, source_type, source_id)` UNIQUE — garante idempotência de `XpEvents::awardActivity` (múltiplos saves da mesma submissão não duplicam XP).
- `activities.type` reduzido de `ENUM('quiz','pesquisa','formulario','projeto','codigo')` para `ENUM('projeto','codigo')` — os tipos estruturados (quiz/survey/form) voltam em um épico futuro com modelagem própria; rows existentes com tipos antigos foram migradas pra `projeto` via guard idempotente em `INFORMATION_SCHEMA.COLUMN_TYPE`.

Todas aplicadas em prod via seção "Migrações incrementais" do `install/schema.sql` (ADR-017).

### Convenções consolidadas nesta janela
- **Cálculo de progresso centralizado** — model `StudentProgress` concentra a fórmula documentada em `doc/10` (entregues + avaliação aprovada) / (total atividades + tem avaliação). Helpers globais são thin wrappers. Quando E7 chegar, basta alterar o cálculo em um lugar.
- **Fanout de notification por evento de aluno** — stubs (E5-06 publicar conteúdo + E6-04 feedback) seguem o mesmo formato `type + title + body + link`. E10 deduplica/entrega.
- **Cascade polimórfico em aplicação** — quando uma FK não cobre (ex.: `xp_events.source_id` polimórfico), o controller faz o DELETE manual em transação antes do DELETE da origem.
- **Upload com nome determinístico** — submissões usam `<student_id>.<ext>` em vez de UUID porque a semântica é "1 entrega por aluno por atividade"; substituir a versão anterior vira o comportamento natural.
- **Guard de migração ENUM via COLUMN_TYPE** — pra reduzir ENUMs sem quebrar em runs repetidos, consultar `INFORMATION_SCHEMA.COLUMNS.COLUMN_TYPE` pra detectar valores antigos antes de `UPDATE + ALTER MODIFY`.

### Tooling
- `package.json` bumpado para 0.5.0.

### Pendências
- Tipos de atividade estruturados (quiz, survey, form) voltam com modelagem própria em épico futuro.
- Execução online de código (Judge0) — toggle já persiste em `activities.allow_online_code_run`, integração real é o Epic E8.
- Notificações reais (email, sino) — infra em `notifications` recebe inserts stub desde E5-06 e E6-04; entrega efetiva é o Epic E10.
- Remover arquivos órfãos em prod (pendências herdadas de v0.4.0): `src/lib/HtmlPurifier.php` e `public/_diag_*.php` (stubs 410).
- Cross-tenant smoke ainda pendente — 1 tenant em prod (Marcos Ortolani).

[0.5.0]: https://github.com/markimpdl/lms/releases/tag/v0.5.0

## [0.4.0] — 2026-04-23

Quarta release. Escopo: **conteúdo da CU** — Épico E5 inteiro, do editor TinyMCE até a visão do aluno. Primeira entrega que o aluno consome ativamente. Entra também o dashboard funcional do aluno.

### Novas funcionalidades

#### Conteúdo e editor (Épico E5)
- Editor TinyMCE 6 community via CDN com toolbar completa (texto, títulos H2/H3/H4, listas, links, tabelas, code blocks com syntax highlight para Python/C#/JS/HTML/CSS), sanitização server-side via HTML Purifier com allowlist estrita, checkbox "Publicar" que controla visibilidade do conteúdo pro aluno (E5-01).
- Embeds de YouTube e Vimeo via plugin `media`: resolver custom aceita apenas esses dois provedores em qualquer formato (watch, youtu.be, shorts, vimeo.com) e normaliza para iframe canônico. Allowlist espelhada no backend via `URI.SafeIframeRegexp` — defense-in-depth (E5-02).
- Upload de anexos (PDF/ZIP/TXT + PNG/JPG/GIF/WEBP) com validação de mime real via `finfo_file`, limite 3 MB, arquivos renomeados para UUID e salvos fora do document root em `storage/uploads/tenant_<tid>/content/<cu_id>/`. Imagens viram dropdown "Image list" do plugin `image` do TinyMCE — professor escolhe e a imagem entra inline no conteúdo (E5-03).
- Download autenticado de anexos com 4 rotas (`/view` inline e `/` download para professor e para aluno matriculado). `ContentAttachment::findForStudent` faz JOIN compound com `enrollments` — 404 amigável quando aluno não tem matrícula. `AttachmentStorage::stream` consolida a emissão de headers + defesa contra path traversal (E5-04).
- Visão do aluno sobre a CU em `/student/cu/{id}` com HTML sanitizado, vídeo 16:9 responsivo, imagens inline servidas pela rota autenticada do aluno (URL reescrita do `/teacher/...` para `/student/...` no render). Dashboard do aluno passou de stub para listagem navegável dos cursos matriculados → CC → CU. Nova tela `/student/course/{id}` (E5-05).
- Stub de notification ao publicar conteúdo: uma linha por aluno matriculado em `notifications` (type=`content_published`), pronta para o E10 consumir; zero email, zero UI (E5-06).

### Mudanças de schema
- `contents.published TINYINT(1) NOT NULL DEFAULT 0` — controla visibilidade do conteúdo pro aluno. Aplicada em prod via seção "Migrações incrementais" do `install/schema.sql` (idempotente).

### Correções

- **Colisão case-insensitive de classe:** o wrapper inicial `HtmlPurifier` ocupava o mesmo slot da classe `HTMLPurifier` da lib `ezyang/htmlpurifier` (PHP trata nomes de classe como case-insensitive, e o Composer autoload carrega a lib antes). Chamada estática a `HtmlPurifier::purify()` era resolvida pro método de instância da lib. Renomeado para `ContentSanitizer`; comentário no topo documenta a armadilha.
- **TinyMCE reescrevia URLs de anexos:** `convert_urls: true` + `relative_urls: true` (defaults) transformavam `/teacher/cu/.../attachment/.../view` em caminho relativo à página de edição, quebrando links quando o HTML era renderizado em outra rota. Setados ambos para `false` — URLs sobrevivem purify + save + render em qualquer rota.
- **Polish do smoke E5-05:** (a) imagens inseridas pelo professor quebravam pro aluno porque o `src` apontava para `/teacher/cu/...` (rota protegida por role=teacher) — fix via reescrita `/teacher/cu/` → `/student/cu/` no render do aluno; (b) breadcrumb do aluno em `/student/cu/{id}` não tinha links navegáveis — adicionado "Meus cursos" e nome do curso clicáveis; (c) tela `/teacher/cu/{id}` não listava anexos com link de download — seção adicionada reusando a rota autenticada de download do E5-04.

### Convenções consolidadas nesta janela
- **Wrapper de lib Composer** — nunca usar nome que difira só em case do nome da classe da lib; escolher nome semanticamente distinto (`ContentSanitizer`, `LmsMailer`, `JudgeClient`).
- **URL de anexo role-agnóstica por reescrita** — conteúdo salvo tem URLs `/teacher/cu/...`; tela do aluno faz `str_replace('"/teacher/cu/', '"/student/cu/', ...)`. Authorization real continua no handler da rota (`findForStudent` valida matrícula).
- **Fanout de notifications** — uma linha por destinatário em vez de event log global. Simples, alinha com schema existente (`notifications.user_id` + FK CASCADE).

### Tooling
- `public/_diag_*.php` e `public/_debug_*.php` gitignorados — scripts temporários de diagnóstico ficam só em prod via FTP manual.
- `ezyang/htmlpurifier ^4.19` adicionado via composer (`vendor/` sobe no deploy FTPS).
- `package.json` bumpado para 0.4.0.

### Pendências
- Remover `src/lib/HtmlPurifier.php` (arquivo renomeado, ficou órfão no servidor — o deploy FTPS não apaga remotamente). Apagar via cPanel File Manager quando conveniente.
- Remover `public/_diag_students.php` e `public/_diag_purify.php` do servidor via cPanel (já neutralizados como stubs 410 por algumas iterações).
- Cross-tenant smoke ainda não executado em prod — só 1 tenant configurado (`Marcos Ortolani`). Mantém pendência desde v0.3.0.
- Storage de anexos (ezyang htmlpurifier + uploads) consome subiu ~384 arquivos novos no FTP — deploy futuro em massa pode falhar de novo por ECONNRESET. Padrão consolidado: zip + extract via cPanel é o workaround pra rajadas.

[0.4.0]: https://github.com/markimpdl/lms/releases/tag/v0.4.0

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
