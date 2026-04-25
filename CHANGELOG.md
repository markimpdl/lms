# Changelog

Todos os releases do LMS ficam documentados neste arquivo. O formato segue
[Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e o projeto adota
[Semantic Versioning](https://semver.org/lang/pt-BR/).

## [0.13.0] — 2026-04-25

Décima terceira release. Escopo: **Epic E16 inteiro — Fundamentos do aluno** (4 stories) + 1 chore de deploy. Adiciona atributos de gestão ao aluno (sexo, doc identificação, status no curso) e auditoria de acesso (histórico de conexões com geo-IP). Backfill silencioso de alunos legados como `gender = 'male'`.

### Novas funcionalidades

#### Epic E16 — Fundamentos do aluno

- **Cadastro com sexo + doc identificação** (E16-01, #207): 2 colunas novas em `users` — `gender ENUM('male','female') NOT NULL DEFAULT 'male'` (obrigatório no form, default cobre backfill) e `id_document VARCHAR(30) NULL` (opcional, validação `^[0-9]{1,30}$`). Forms de criar/editar aluno (`/teacher/students/new` e `/teacher/students/{id}`) ganham os campos. Aluno **não** vê em `/student/*` nem `/profile`. PO confirmou que só havia 1 aluno legado em prod (vira 'male' silenciosamente). Bloqueia E17 (avatares no painel dependem do sexo).
- **Listagem `/teacher/students` com nome curto + doc + busca expandida** (E16-02, #208): helper novo `format_short_name(string): string` em `src/helpers.php` — "Nome Múltiplos Tokens" → "Primeiro Último" (token único passa direto). Tabela ≥lg usa nome curto + tooltip com nome completo no hover. Coluna nova "Documento" entre Nome e E-mail. Filtro `q` agora bate em `id_document` além de name+email. Mobile cards mantidos como estavam.
- **Status do aluno no curso visível só pro professor** (E16-03, #209): `enrollments.status ENUM('active','absent','completed') NOT NULL DEFAULT 'active'` — atributo manual e indicativo, **não altera regras de negócio** (XP, progresso, ranking continuam idênticos). Roster do curso (`/teacher/courses/{id}`) ganha select inline com auto-submit on change. Aluno **não** vê. Endpoint POST com whitelist + ownership via JOIN duplo (`u.tenant_id` E `c.tenant_id`).
- **Histórico de conexões do aluno com geo-IP** (E16-04, #210): tabela nova `user_logins(user_id, tenant_id, ip, location, user_agent, logged_in_at)` populada a cada login bem-sucedido via hook em `AuthController::completeLogin`. Localização vem de **ip-api.com (free tier 45 req/min)** com fallback NULL em rate-limit/timeout. Visível APENAS pelo professor no detalhe do aluno (últimas 10). Retenção 180 dias via cron novo `scripts/cron/purge-old-logins.php` (setup manual no cPanel). LGPD note em `doc/14` ADR-031.

### Mudanças internas / Tooling

- **`scripts/cron/` agora vai pro deploy** (#211): `INCLUDE_OVERRIDES` novo em `scripts/deploy/ftp-deploy.mjs` permite que `scripts/cron/` suba mesmo com `scripts/` excluído por design (que continua bloqueando devtools como `ftp-deploy.mjs`). Refactor de `walk()` com `hasOverrideUnder()` permite descer em pastas excluídas quando algum override aponta pra dentro.
- **`package.json`** bumpado para 0.13.0.

### Convenções consolidadas nesta janela

- **Defaults retroativos via DEFAULT no schema** — em vez de DML separado pra backfill, usar `DEFAULT 'male'` na coluna nova já cobre o caso. Limpa, atômica, sem race.
- **Engine de tracking silencioso** (mesmo padrão do `Enrollment::touchLastAccess` em E14): `UserLogin::recordLogin` engole `Throwable` — falha de histórico jamais derruba o login.
- **GeoIP defensivo** — pular IPs privados/locais antes de chamar API externa economiza quota e evita 422 na resposta.
- **Hook `gh pr create` aplica auto-fix de "segurança"** que pode estar errado pro contexto — ex.: trocou `http://ip-api.com` por `https://` (free tier de ip-api.com **só** suporta HTTP; HTTPS exige pro plan). Solução: descartar o working tree quando o "fix" não couber, e seguir.

### Pendências (herdadas, ainda abertas)

- **`JUDGE0_KEY` em prod**: endpoint responde 503 amigável até o PO configurar.
- **C# sem syntax highlight no CodeMirror 6** — plain text funciona; nice-to-have futuro.
- **Cross-tenant smoke** ainda parcial — só 1 tenant em prod.

[0.13.0]: https://github.com/markimpdl/lms/releases/tag/v0.13.0

## [0.12.0] — 2026-04-25

Décima segunda release. Escopo: **Epic E15 inteiro — UX e fundamentos pós-MVP** (2 stories pequenas) + materialização do roadmap pós-MVP em `doc/15`. Primeira release abrindo a fase pós-MVP, com 7 épicos novos (E15-E21) já priorizados pelo PO.

### Novas funcionalidades

#### Epic E15 — UX e fundamentos
- **Login redireciona direto pro dashboard** (E15-01, #199): `/` faz `header('Location: ...')` + `exit` quando há sessão válida, baseado no papel via `AuthController::dashboardFor` (já existente). Remove o clique extra "Welcome → Go to dashboard" que existia. Anônimo continua vendo a home com botão de login. Removido o branch `else` de markup que ficou inalcançável.
- **Card de Ranking separado no ProfileSidebar** (E15-02, #200): refatora o sidebar movendo `#posição` de dentro do `lms-xp-block` (ficou poluído em E9-07 / #193) pra card próprio `.lms-ranking-block` abaixo, com eyebrow "POSIÇÃO" + valor `#N` (ou "—" pra aluno zero XP) + CTA outline-primary full-width "Ver ranking" → `/student/ranking`. Mesmo design language do XP block (`#FAFAFA` bg, radius 14px).

### Mudanças internas / Tooling

- **`doc/15-roadmap-pos-mvp.md`** (#195): doc agregador com 12 funcionalidades pós-MVP (F1-F12) aprovadas pelo PO em 2026-04-25 — login redirect, card ranking, cadastro com sexo+doc, acesso ao curso (período/bloqueio/avatar default), conquistas, status no curso, sequencial vs livre, quiz, notif config, histórico de conexões, avatares no painel, listagem teacher/students. ~810 linhas, 7 épicos sugeridos (E15-E21).
- **`package.json`** bumpado para 0.12.0.

### Pendências (herdadas, ainda abertas)
- **`JUDGE0_KEY` em prod**: endpoint responde 503 amigável até o PO configurar.
- **C# sem syntax highlight no CodeMirror 6** — plain text funciona; nice-to-have futuro.
- **Cross-tenant smoke** ainda parcial — só 1 tenant em prod.

[0.12.0]: https://github.com/markimpdl/lms/releases/tag/v0.12.0

## [0.11.0] — 2026-04-25

Décima primeira release. Escopo: **Epic E9 inteiro — Rankings de gamificação**. O XP que vinha sendo acumulado em `xp_events` desde o E6/E7 agora ganha uma cara: aluno vê sua posição absoluta no header lateral, abre uma tela cheia de ranking com 3 janelas (Geral / 7d / 30d), filtra por grupo e ano civil, e tem rota dedicada por curso. Professor ganha visão equivalente com coluna extra de "última entrega" pra identificar engajamento e risco. Zero schema change — tudo derivado do `xp_events` + `groups` + `users` existentes.

### Novas funcionalidades

#### Epic E9 — Rankings

- **`RankingService` — fundação de leitura agregada** (E9-02, #188): novo service em `src/services/RankingService.php` com 2 métodos. `compute(tenantId, window, filters, page, perPage)` retorna lista paginada com `[position, student_id, name, group_names, xp, last_event_at]` + total. `myPosition(studentId, tenantId, window, filters)` retorna posição linear via `ROW_NUMBER() OVER (...)` sem trazer toda a lista pro PHP. 3 janelas rolantes (`all`/`7d`/`30d`), filtros opcionais combináveis (`group_id`, `year`, `course_id`), desempate `xp DESC, last_event_at DESC, name ASC`. Janela "all" sem filtros temporais inclui aluno zero XP via LEFT JOIN; demais escopos aplicam `HAVING > 0`. Multi-tenant via `users.tenant_id`. Sem cache no MVP (decisão consolidada — reavaliar com métrica em prod).
- **Tela `/student/ranking`** (E9-03, #189): rota nova com pills Geral / Últimos 7 dias / Últimos 30 dias via `?window=`, tabela 4 colunas (posição, nome, grupos, XP) com linha do aluno logado destacada (gradient indigo/pink + badge "Você"), paginação 50/página preservando filtros via querystring. Item "Ranking" novo no navbar do aluno com active state. CSS scoped a `body.lms-student-area` com tokens E14 (Plus Jakarta Sans, paleta indigo/pink). Mobile 360px: coluna grupos oculta via `d-none d-md-table-cell`.
- **Filtros de grupo e ano civil** (E9-04, #190): form GET com selects de grupo (lista do tenant via `Group::listForSelect`) e ano (DISTINCT `YEAR(created_at)` de `xp_events` do tenant DESC). Ano default = ano corrente quando `?year` não passado; `?year=all` = todos os anos. Whitelist no controller: `group_id` precisa existir em `groups` do tenant; `year` válido entre 2020-2099 com fallback silencioso. Filtros encadeiam livremente com a janela; trocar filtro reseta `?page=1`. Builder `$qs(...)` que preserva todo o estado e aceita overrides com `null` pra remover chave.
- **Tela `/teacher/ranking`** (E9-05, #191): mesmo Service, layout com Bootstrap default (`.table table-hover`) alinhado com `/teacher/students`, `/teacher/groups`. 5 colunas: posição, nome, grupos, XP, **última entrega** (`format_short_date($last_event_at)`). Sem destaque de linha (professor não compete). Item "Ranking" no navbar do professor reusando lógica do aluno (`$rankingHref` resolvido condicionalmente por role). Mobile 360px: grupos hidden md+, última entrega hidden lg+.
- **Ranking por curso específico** (E9-06, #192): 2 rotas novas (`/student/course/{id}/ranking` + `/teacher/courses/{id}/ranking`) que reusam as pages existentes injetando `course_id` via route pattern. Aluno valida matrícula via `Enrollment::isEnrolled` → 404 se não-matriculado. Professor valida tenant via `Course::findForTenant` → 404 cross-tenant. Header das pages muda quando em escopo de curso (eyebrow "RANKING DO CURSO" + h1 com nome + breadcrumb pro professor + link "← Voltar ao curso" pro aluno). Links de entrada: aluno via card abaixo do hero em `student/course/show.php`; professor via botão ao lado de "Matriz" em `teacher/courses/show.php`.
- **`#posição` no ProfileSidebar** (E9-07, #193): linha nova `.lms-xp-position` entre TOTAL XP e a barra de progresso, com eyebrow "POSIÇÃO" + `#N` clicável que leva pra `/student/ranking`. Helper novo `student_ranking_position(studentId, tenantId): ?int` em `src/helpers.php` que delega pra `RankingService::myPosition('all', [])` e engole Throwable graciosamente (mesmo padrão do tracking silencioso de E14 — sidebar nunca quebra a UI). Edge case do AC: aluno zero XP mostra "—" em vez de "#último" (check `$totalXp > 0` no partial reusa query já calculada).

### Correções

- **Switcher anônimo de idioma quebrava com query string** (#180): em `/reset?token=XYZ`, clicar no toggle de idioma fazia GET pra `/reset?lang=pt` perdendo o token. Fix com helper novo `lang_url($lang)` em `src/helpers.php` que faz `array_merge($_GET, ['lang' => $lang])` e preserva todo o query string. Aplicado no header do navbar quando o user está deslogado.

### Mudanças internas / Tooling

- **Script `scripts/deploy/ftp-cleanup-orphans.mjs`** (#179): contraparte do `ftp-deploy.mjs` (que é upload-only) pra remover arquivos órfãos em prod. Dry-run default + lista curada `ORPHANS` no topo do script (commitada vazia — próximos órfãos se somam lá) + validação de estrutura (rejeita paths vazios, com `..`, ou começando com `/`). Usado nesta janela pra apagar 3 arquivos órfãos em prod (`src/lib/HtmlPurifier.php` herdado de v0.4.0, `public/_diag_students.php`, `public/_diag_purify.php`).
- **Doc fix: ENUM `activities.type` em `doc/12-modelo-de-dados.md`** (#181): valores antigos (`quiz`, `pesquisa`, `formulario`) tinham sido removidos do schema durante E6-05 (PR #113) mas a doc nunca foi atualizada. Commit recuperado de branch órfã `feature/115-evaluations-schema` que não tinha sido mergeada após o E7-00.
- **Issues stale fechadas em massa**: 18 issues do GitHub (#1-5, #8, #9, #11, #13-20, #137, #138) fechadas com referência ao release em que foram entregues — limpa o backlog visível.
- **`package.json` bumpado para 0.11.0.**

### Convenções consolidadas nesta janela

- **Service de leitura agregada com `ROW_NUMBER()` em window function** — MariaDB 10.11 / MySQL 8 suportam window functions sobre agregados, então `ROW_NUMBER() OVER (ORDER BY SUM(x.value) DESC, ...)` em GROUP BY query é o caminho mais limpo pra calcular posição linear sem trazer toda a lista pro PHP. Padrão aplicável a outros rankings/leaderboards futuros.
- **Reaproveitamento de pages via route pattern** com filtros injetados (`/teacher/courses/{id}/ranking` injeta `course_id`) — quando o delta entre 2 contextos é pequeno (filter extra + header diferente), reutilizar a page com 2 patterns diferentes evita duplicar 100+ linhas. Padrão já usado em `/teacher/courses/{id}/matrix`.
- **`$qs(...)` builder de querystring** com overrides explícitos e suporte a `null` pra remover chave — substitui `urlencode + concat` em pages com múltiplos filtros encadeáveis. Reusável.
- **Visual diverge intencionalmente entre aluno e professor**: aluno tem o look gamificado (gradients, Plus Jakarta Sans, badge "Você"), professor tem o look administrativo (Bootstrap default), alinhado com as outras telas do professor. Não compartilham CSS — duplicação aceitável de ~100 linhas em troca de separação visual clara entre as 2 áreas.
- **Hook `gh pr create` rodando code-review automático** começou a aplicar sugestões via Edit em commits subsequentes — quando o working tree fica sujo após `gh pr create`, é o hook agindo. Solução: commitar como follow-up commit no mesmo branch antes do merge.

### Pendências (herdadas, ainda abertas)

- **`JUDGE0_KEY` em prod**: endpoint responde 503 amigável até o PO configurar a key do RapidAPI no painel Hostinger.
- **C# sem syntax highlight no CodeMirror 6** — plain text funciona; nice-to-have futuro.
- **Cross-tenant smoke** ainda parcial — só 1 tenant ativo em prod; novo cenário coberto em E9-06 (cross-tenant 404 forjando URL de curso de outro tenant).
- **`context_lang($courseId)` helper** — adiado do E14-03; hoje `current_lang()` cobre o uso.

[0.11.0]: https://github.com/markimpdl/lms/releases/tag/v0.11.0

## [0.10.0] — 2026-04-25

Décima release. Escopo: **Epic E11 inteiro — Dashboards do professor**. O `/teacher` vira uma home útil (totalizadores + submissões recentes + alunos inativos) em vez de menu de cards; CU ganha aba "Alunos" com matriz cruzada; curso ganha nova rota de matriz alunos × CUs; e as listas de submissões de atividade/avaliação/curso ganham cards de métricas agregadas inline. Zero mudança de schema — tudo derivado de queries sobre tabelas existentes.

### Novas funcionalidades

#### Epic E11 — Dashboards do professor

- **Dashboard home do professor** (E11-01, #166): `/teacher` vira home útil em vez de menu de cards. Totalizadores (cursos ativos, alunos ativos, submissões pendentes), lista de 10 submissões recentes (UNION ALL de activity_submissions + evaluation_submissions is_current=1, ordenado por created_at DESC) linkando direto pra correção, e alunos inativos (LEFT JOIN + MAX(last_access_at) + HAVING pra pegar quem não acessou em 14 dias ou nunca acessou, NULLs primeiro). Novo model `TeacherDashboard` com 3 agregações: `totalsForTenant`, `recentSubmissions`, `inactiveStudents`. Design Bootstrap-native (cards grandes, grid 8/4) — não aplica design tokens do student-area do E14-01.
- **Matriz Alunos × Unidades de Competência** (E11-02, #173): nova rota `/teacher/courses/{id}/matrix` mostrando em 1 tela todos os alunos matriculados no curso com status de cada CU em formato de matriz. Estados derivados inline sem N queries (evitando N*M com 30 alunos × 20 CUs): `completed` / `in_progress` / `not_started` / `evaluated_ok` / `evaluated_fail`. Novo model `CourseMatrix::forCourse`.
- **Aba Alunos na CU com status cruzado** (E11-03, #171): `/teacher/cu/{id}` ganha seção "Alunos" com matriz alunos × atividades + coluna "Avaliação" quando eval existe. Tabela responsiva (≥md) com colunas "A1..AN" + "Avaliação"; cards empilhados (<md) com mini-badges coloridos. Cor Bootstrap `bg-*-subtle` + `text-*-emphasis` (contraste WCAG ok). Célula clicável linka direto pra review da submissão correspondente; célula vazia não-clicável. Filter Alpine.js client-side "Apenas ativos" default ligado. Novo model `CuRoster::listForCu` com 4 queries compostas em PHP — contexto + eval_id via CU, enrolled students do curso, activity_submissions da CU de uma tacada, evaluation_submissions correntes quando eval existe. Mapeamento final agrupa por student e mapeia `activity_id → 'not_submitted'|'pending'|'with_feedback'`, `eval → state` consistente com E7-05.
- **Métricas agregadas inline** (E11-04, #175): `/teacher/activity/{id}/submissions`, `/teacher/evaluation/{id}/submissions` e `/teacher/courses/{id}` ganham card 4-col de métricas no topo (matriculados, submetidos, % submetido/aprovado, tempo médio de feedback em minutos/horas/dias). Novo model `CourseMetrics` com `forActivity`, `forEvaluation`, `forCourse`. Helper `format_duration_minutes` formata em `45 min` / `2h 30min` / `3 dias` segundo o idioma.

### Correções

- **Defesa em profundidade no CU roster** (#172): subquery de evaluation na `CuRoster::listForCu` agora filtra `tenant_id` explícito mesmo quando o contexto já foi validado upstream no primeiro select. Hardening pré-emptivo contra regressões futuras — se algum refactor separar as queries, o tenant isolation não depende do gate anterior.
- **Largura do /student variando com idioma** (3 PRs encadeados — #174, #176, #177): PO reportou que cards do `/student` ficavam menores que a tela de detalhes do curso e variavam de tamanho conforme o idioma (EN < PT). Diagnóstico final com repro headless + outlines coloridos por wrapper: `main.lms-student-grid` sizava ao `max-content` do conteúdo (~1032px) em vez de esticar até o `max-width: 1280px`, porque `<body>` é `display: flex; flex-direction: column` e **auto-margins no cross-axis absorvem free-space antes de `align-items: stretch` agir**. Em `/student/course/{id}` o hero tem conteúdo grande (título 30px + ring + stats) que empurra o max-content alem de 1280, batendo o cap e mascarando o bug. Fix definitivo em #177: `width: 100%` no `main.lms-student-grid`. Alterações em #174 (`grid-template-columns: 1fr` → CourseCard full-width) e #176 (`width: 100%` defensivo em `.lms-student-main`, `.lms-dashboard-header`, `.lms-course-grid` + troca de grid por flex column) permanecem como belt-and-suspenders.

### Convenções consolidadas nesta janela

- **Models de agregação em `src/models/*Dashboard.php` / `*Matrix.php` / `*Metrics.php`** — não espelham tabela, são puramente derivados. Queries compostas em PHP em vez de SQL único gigante (mais testável, mais legível, e permite cacheamento futuro por método).
- **Estados consistentes de submissão no vocabulário do professor** (`approved` / `failed` / `retry` / `pending` / `not_submitted` / `none` para evals; `with_feedback` / `pending` / `not_submitted` para activities) — mesmas strings em E7-05, E11-02 e E11-03.
- **Card 4-col Bootstrap `row text-center g-3`** com `col-6 col-md-3` pra métricas — padrão reutilizável em 3 páginas do professor, mobile stackeia em 2x2.
- **Alpine.js filter local com `x-data`** é preferível a reload por query param quando o dataset cabe numa renderização (ex.: "Apenas ativos" no roster) — UX instantânea, zero round-trip.
- **Repro headless + outlines coloridos pra debugar CSS** — Edge headless + `--screenshot` + outlines de cor únicos por wrapper + `console.log` das medidas via `getBoundingClientRect` é o caminho mais rápido pra isolar bugs de layout que não reproduzem numa leitura de CSS.

### Tooling

- `package.json` bumpado para 0.10.0.

### Pendências (herdadas de v0.9.0, ainda abertas)

- **`JUDGE0_KEY` em prod**: endpoint responde "indisponível" (HTTP 503) até o PO configurar a key do RapidAPI no painel Hostinger.
- **Arquivos órfãos em prod**: `src/lib/HtmlPurifier.php` e `public/_diag_*.php` (cleanup pendente).
- **Cross-tenant smoke** ainda pendente — só 1 tenant em prod.
- **C# sem syntax highlight no CodeMirror 6** — plain text funciona; nice-to-have futuro com `@codemirror/legacy-modes/mode/clike`.
- **`context_lang($courseId)` helper** — mencionado no AC do E14-03 mas ficou adiado.

[0.10.0]: https://github.com/markimpdl/lms/releases/tag/v0.10.0

## [0.9.0] — 2026-04-24

Nona release. Escopo: **execução online de código — Epic E8 inteiro** + pequenos ajustes UI na tela de curso do aluno. Primeira release que dá ao aluno feedback imediato do código sem sair da plataforma — Python/C#/JavaScript via Judge0 CE (RapidAPI) e HTML em sandbox local, tudo com CodeMirror 6 como editor.

### Novas funcionalidades

#### Execução online de código (Epic E8)
- **Schema + editor** (E8-00): coluna `activities.code_language ENUM('python','csharp','javascript','html') NULL` cadastrada pelo professor no form de atividade quando `type='codigo'`. Aluno em `/student/activity/{id}` ganha editor CodeMirror 6 via CDN (esm.sh dynamic imports) com syntax highlight por linguagem — Python, JavaScript e HTML com pacote oficial; C# em plain text (CM6 não tem pacote oficial). Textarea `#f-code` permanece como source-of-truth hidden; editor sincroniza no submit. Fallback gracioso quando JS falha: textarea editável.
- **HTML sandbox local** (E8-03): botão "Executar" renderiza o código HTML num `<iframe sandbox="allow-scripts">` — sem `allow-same-origin`, sem `allow-top-navigation`, sem `allow-forms`. `srcdoc` via JS, zero network, estado efêmero. UI do painel de resultado já pronta pra reuso em E8-02.
- **Backend proxy** (E8-01): `src/lib/Judge0Client.php` com `run(language, code)` retornando shape consistente `{status, stdout?, stderr?, time?, status_id?, error?}`. Endpoint `POST /api/code/run` autenticado com cadeia de 13 validações (método, role, CSRF, activity_id, matrícula, type=codigo, allow_online_code_run=1, language != html, size ≤ 64KB) — cada falha com HTTP status + error_key i18n específico. Key do Judge0 fica em `config/env.php` (nunca no browser); `isConfigured()` permite degradação graciosa quando não setada. Timeout 15s + CPU limit 5s. Log estruturado em `storage/logs/judge0.log`.
- **UI final de execução** (E8-02): state machine explícita com 5 estados (idle / loading / iframe / tabs / error) + `hideAllStates()` pra mutual exclusion. Tabs Saída (stdout) / Erros (stderr) / Info (tempo + status friendly) usando o pre escuro `#0F172A` igual à unit-prose. Ctrl+Enter / Cmd+Enter como atalho dentro do editor. Botão "Executar" desabilita durante fetch com `finally` garantindo unlock. Status ID do Judge0 → label friendly em 5 categorias (accepted, time_limit, compile_error, runtime_error, unknown).

### Correções
- Hero ring da Course page mostrava % invisível (letra branca em fundo branco). Corrigido com remoção do override `::before { background: transparent }` + label em `neutral-900`. Nos avatares de Competências e Unidades, trocadas as iniciais do nome pelo número sequencial (1..N) — mais legível e consistente com os eyebrows "Competência N" / "Unidade N" (#161).

### Mudanças de schema
- `activities.code_language ENUM('python','csharp','javascript','html') NULL` — idempotente via `INFORMATION_SCHEMA` check; aplicado em prod durante o ciclo.

### Convenções consolidadas nesta janela
- **CodeMirror 6 via esm.sh dynamic imports** — zero bundle no repo, carrega só a linguagem usada. Fallback a textarea preserva comportamento se JS falhar.
- **Shape consistente em cliente HTTP** — `Judge0Client::run` sempre retorna `{status, ...}` com enums de status; caller faz switch por `status === 'ok'`.
- **Cadeia de validações com error_key**: cada falha no endpoint tem HTTP status adequado + chave i18n pro client-side lookup. Zero branching por tipo de falha no cliente.
- **`csrf_verify_no_rotate()` novo helper** pra endpoints AJAX chamáveis múltiplas vezes numa mesma página sem reload. `csrf_verify()` padrão (one-time rotativo) continua pros POSTs com redirect.
- **Sandbox HTML com `allow-scripts` apenas** — sem `allow-same-origin` garante que o código do aluno roda em origem única, isolado de cookies/localStorage do parent.
- **State machine UI explícita** via `hideAllStates()` + `showX()` funcs por state — impossible ter 2 estados visíveis simultaneamente.
- **Map error_key → i18n message** pré-renderizado via `json_encode` do servidor — client faz só lookup por chave conhecida, zero risco de injection.
- **textContent em vez de innerHTML** pros conteúdos vindos do Judge0 — stdout/stderr pode conter qualquer coisa, renderizado como texto puro.

### Tooling
- `package.json` bumpado para 0.9.0.

### Pendências
- **`JUDGE0_KEY` em prod**: endpoint responde "indisponível" (HTTP 503 + `code_run.err.unavailable`) até o PO configurar a key do RapidAPI no painel Hostinger. UI exibe mensagem amigável — zero quebra.
- **C# sem syntax highlight no CodeMirror 6** — plain text funciona; se o PO pedir highlight, adicionar `@codemirror/legacy-modes/mode/clike` em story futura.
- **Sem retry automático** no Judge0Client — 429 e 5xx retornam direto. Aceitável pro MVP (ADR-029); exponential backoff fica pra story futura se a quota ficar apertada.
- **`context_lang($courseId)` helper** — AC do E14-03 mencionava mas ficou adiado. Plantado `course_language` em models; implementar quando o PO pedir.
- **Arquivos órfãos em prod** herdados de v0.4.0: `src/lib/HtmlPurifier.php` e `public/_diag_*.php`.
- **Cross-tenant smoke** ainda pendente — só 1 tenant em prod.

[0.9.0]: https://github.com/markimpdl/lms/releases/tag/v0.9.0

## [0.8.0] — 2026-04-24

Oitava release. Escopo: **área do aluno redesenhada + patentes por tenant** — Epic E14 inteiro (redesign handoff) + início de E9 com patentes. Primeira release visual grande: 3 telas do aluno (My Courses / Course page / Unit page) ganham design novo do handoff, ProfileSidebar 300px, design tokens globais scoped e ActivityCard com 6 estados. Professor ganha CRUD de patentes por tenant.

### Novas funcionalidades

#### Patentes (Epic E9 parcial)
- Professor cadastra faixas de XP com nome e cor em `/teacher/ranks` (ex.: Aprendiz 0-1000, Cadete 1001-5000, Mestre 5001+). Tabela `ranks` nova por tenant com overlap detection em PHP, unique name, reordenação por position swap+renormalize. `Rank::findCurrentByXp` e `findNextByXp` plantados pro ProfileSidebar consumir. Sem cascade destrutivo — excluir patente não afeta aluno (cálculo on-the-fly) (E9-01).

#### Redesign da área do aluno (Epic E14)
- **Schema** (E14-00): `competence_units.workload_hours INT UNSIGNED` pra carga horária cadastrada pelo professor; `enrollments.last_access_at DATETIME NULL` atualizada quando aluno abre `/student/course/{id}` (silencioso em falha, tracking é melhoria não-crítica). Form de CU ganhou input number 0-999 pros dois campos em ambos modais (new + edit).
- **Design tokens + ProfileSidebar** (E14-01): fonts Plus Jakarta Sans + Inter via Google CDN, CSS vars da paleta (primary/success/warning/danger/violet/muted/neutrals + page-bg `#F8F7FB`), radii, shadows — **tudo scoped a `body.lms-student-area`** (professor/admin inalterados). Layout grid 300/1fr com ProfileSidebar sticky top 88px; mobile <768 empilha. Sidebar renderiza avatar 104 com border colorida pela patente, rank pill gradient sobreposto ou "—" muted, nome com ellipsis, subtítulo do curso acessado mais recente, XP block com barra de progresso e footer "X para {next rank}" ou "Nível máximo". 4 helpers novos: `student_total_xp`, `student_current_rank`, `student_next_rank`, `student_recent_course_name`.
- **My Courses redesign** (E14-02): header com eyebrow roxo + H1 32 + contadores (total/ativos/concluídos) + filter pills Alpine.js client-side (All/Active/Completed com counts, selecionado = gradient). Grid `auto-fill minmax(300px, 1fr)`. CourseCard com cover 56px gradient determinístico por `crc32(courseId) % 6` (palette do handoff, zero schema change), body com StatusBadge + H3 + instrutor (via `tenants.owner_user_id`) + ProgressRing 52 + barra 6px + "{done}/{total} unidades" + "{hours}h no total", footer com "Último acesso: {date}" ou "Ainda não acessado" + CTA arrow contextual ("Acessar/Retomar/Revisar", gradient ou `#111827` se completed). Empty state dedicado.
- **Course page redesign** (E14-03): hero banner gradient 3-stops, 2 círculos decorativos, eyebrow "Welcome back", H1 30, stats row (Overall %, Units done/total, CC count) + ProgressRing 112 em painel translúcido com backdrop-filter blur. Per-CC section (partial) com ícone 52×52 letra inicial, eyebrow "Competência N" em cor do curso, H2 22, summary "{done} de {total}", barra 8px, ring 72 "Overall" à direita. UnitCard (partial) com ícone 40 + ring 64 + eyebrow "Unidade N" + título + StatusBadge + clock/horas + "+XP" em roxo. Hover com translateY e glow indigo. `StudentCurriculum::forStudent` expandido com workload_hours, xp_activities e xp_evaluation por CU + course_language.
- **Unit page redesign** (E14-04): breadcrumb 4 níveis; unit header card com eyebrow "UNIT N · {CC}" roxo, H1 28, meta row (clock+h, star+earned/total XP roxo, dot+%), ProgressRing 84; section tabs pill group com Alpine.js (active highlight + ancoras nativas); 4 seções — Content (unit-prose CSS completo: h2/h3, blockquote violet, pre dark, code chip, tables, iframes responsivos), Activities (lista vertical de ActivityCards), Assessment (1 ActivityCard com `isAssessment=true` e accent amber→red), Attachments (rows com FileTypeChip colorido por extensão PDF/ZIP/IMG/outros). ActivityCard unificado com 6 estados (unavailable/pending/with-feedback/approved/failed/resubmit-pending), ring 64 com grade overlay opcional (verde ≥6 / vermelho <6), CTAs gradient ou outline. `CompetenceUnit::findForStudent` expandido com `cu_index_in_cc` subquery pra eyebrow "UNIT N".

### Mudanças de schema
- `ranks` tabela nova — `(tenant_id, name, xp_min, xp_max NULL, color_hex, position, created_at, updated_at)`, UK `(tenant_id, name)`, idx `(tenant_id, xp_min)`, FK CASCADE, CHECK `xp_max > xp_min`.
- `competence_units.workload_hours INT UNSIGNED NOT NULL DEFAULT 0` — idempotente via `INFORMATION_SCHEMA`.
- `enrollments.last_access_at DATETIME NULL DEFAULT NULL` — idempotente via `INFORMATION_SCHEMA`.

Todas aplicadas em prod via `install/schema.sql` (ADR-017) durante o ciclo.

### Convenções consolidadas nesta janela
- **Design tokens scoped** via `body.lms-student-area` — permite redesign progressivo sem quebrar telas existentes do professor/admin. Padrão replicável pra futuras áreas temáticas.
- **Gradient determinístico por id** (`crc32(id) % palette`) — cor consistente por entidade sem schema change. Usado pra cover de curso e ícones de CC/CU.
- **Partials por componente** — `section_header`, `unit_header`, `section_tabs`, `activity_card`, `attachment_row`, `course_card`, `profile_sidebar`, `student_course_hero`, `student_cc_section`, `unit_card`. Cada um isolado e reusável; state logic fica no controller, partial é declarativo.
- **ActivityCard unificado** pra atividade e avaliação via flag `is_assessment` — 1 partial serve múltiplos fluxos, DRY entre 6 estados.
- **6 estados da atividade/avaliação** consolidados: unavailable, pending, with-feedback, approved, failed, resubmit-pending. Cada um tem cor de borda, ActivityBadge e variante de CTA definidos.
- **Tracking silencioso** (`Enrollment::touchLastAccess`) — operações não-críticas engolem Throwable pra não travar UI.
- **CSS vars inline** (`--lms-avatar-color`, `--lms-rank-gradient`, `--lms-xp-pct`, etc.) — tematização dinâmica sem CSS por request.
- **Alpine.js como padrão** pro client state leve (filter pills, section tabs, bell dropdown) — reusa a dependência já carregada pro sino (E10-01).

### Tooling
- `package.json` bumpado para 0.8.0.

### Pendências
- **`context_lang($courseId)` helper adiado** — AC do E14-03 pedia helper que `__t` respeitasse `courses.language` quando dentro de curso. Plantado `course_language` em todos os models relevantes; `context_lang` fica pra story dedicada ou quando o PO pedir. Hoje `__t` usa preferência do usuário.
- **Avatar do aluno é placeholder** (letra inicial) — upload de foto não está no escopo do MVP.
- **Ranking, badges e conquistas** (restante do E9) — adiados. Este release só entrega patentes.
- **Remover arquivos órfãos em prod herdados**: `src/lib/HtmlPurifier.php` e `public/_diag_*.php` (v0.4.0).
- **Cross-tenant smoke** ainda pendente — só 1 tenant em prod.

[0.8.0]: https://github.com/markimpdl/lms/releases/tag/v0.8.0

## [0.7.0] — 2026-04-24

Sétima release. Escopo: **notificações — primeiro feedback loop de verdade** — Epic E10 inteiro. A partir desta release, aluno e professor recebem sinais fora da plataforma (email) e dentro dela (sino in-app), fechando a cadeia de eventos dos épicos anteriores que até aqui eram fanout stub.

### Novas funcionalidades

#### Notificações (Épico E10)
- `Notification` model + `NotificationService::fanout` centralizam criação de sinos. 2 callsites inline (`Content.php` e `submission-review.php`) migrados pro service sem mudança de comportamento. `resolveLanguage(userId, courseId)` resolve o idioma do email via `courses.language` (se contexto de curso) ou `users.language` (E10-00).
- Sino no header com badge de contador de não-lidas (gradient pink→red), dropdown com 10 mais recentes, "Marcar todas", clicar redireciona pro link associado. Página `/notifications` com paginação. **Navbar rebuild** conforme design handoff: logo gradient 34×34, wordmark LMS, language pill (persiste `users.language`), sino, avatar+nome com dropdown Profile/Logout. Super_admin não vê sino (coerente com matriz do doc/09). Alpine.js adicionado ao layout comum (E10-01).
- `EmailTemplates::render(name, lang, vars)` — 1 arquivo PHP por (name, lang) retornando `['subject', 'html', 'text']`, layout base table-based CSS-inline sem imagens externas, fallback EN→PT, guard contra path traversal, log em `email-missing.log`. Primeiro template funcional: `activity_feedback` (E10-02).
- **Canal email ativado** pros fanouts já existentes (`content_published` + `activity_feedback`). `Mailer::send` agora retorna `?string` (null=ok, error=falha); `SMTP_TIMEOUT` env (default 10s) evita request travada. Novo helper `app_url($path)` pra URL absoluta usando `APP_BASE_URL`. Falhas de SMTP gravam em `storage/logs/mail-failures.log` com user_id + type + error; sino **sempre** inserido antes do loop de email (E10-03).
- Eventos do E7 ligados ao service — `new_evaluation` (ao criar avaliação), `grade_evaluation` (após correção), `retry_enabled` (condicional a `retry_effective===1` após clamp server-side). TODO do E7 removido. 3 templates PT+EN novos; `retry_enabled` usa CTA pink (`#EC4899`) pra diferenciar visualmente do informativo (E10-04).
- Eventos remanescentes da matriz do doc/09 — `enrollment` (email + sino ao matricular em curso novo; guard `isEnrolled` evita spam em re-enroll idempotente), `activity_new` (só sino, quando atividade nasce com entrega aberta), `submission_closed` (só sino, na transição 1→0 do toggle). Template `enrollment` PT+EN. Flag `sendEmail=false` (plantada em E10-00) serviu pros 2 eventos sino-only (E10-05).

### Convenções consolidadas nesta janela
- **Storage por caso de uso** já era padrão; agora o fanout tem **um service central** (`NotificationService::fanout`) com contrato fixo — `insert primeiro, email depois, try/catch individual`. Callers só passam `type`, `userIds`, `title`, `body`, `link`, `courseId`, `sendEmail`.
- **Vars unificadas nos templates** — `student_name`, `title`, `body`, `link` (URL absoluta). Cada template escolhe quais usar. Abre espaço pra E10 futuro adicionar eventos sem refactor no service.
- **Pre-check de idempotência** em fanouts que dependem de criação idempotente (`Enrollment::isEnrolled` pro INSERT IGNORE do enrollment). Evita email duplicado em re-ações silenciosas.
- **`Enrollment::activeStudentIdsForCourse`** com double tenant filter (`c.tenant_id` + `u.tenant_id`) — virou helper compartilhado pra todos os fanouts de curso (`new_evaluation`, `activity_new`, `submission_closed`).
- **Anti open-redirect no mark-read**: `str_starts_with($link, '/') && !str_starts_with($link, '//')` bloqueia protocol-relative (`//evil.com`). `mark-all-read` e `settings/language` parseiam referer com host match.

### Tooling
- `package.json` bumpado para 0.7.0.
- Alpine.js 3.14.1 adicionado ao layout via CDN (sem SRI — alpine canary sem hash oficial estável).
- CSS `.lms-navbar`, `.lms-bell`, `.lms-notif-row` em `public/assets/css/app.css` — design tokens globais ficam pro E14.

### Pendências
- **E10-06 Digest diário do professor** adiado — depende de `tenants.timezone` configurável + cron + template agregador. Sino do professor já mostra submissões em tempo quase-real via E10-05, cobrindo o MVP.
- **E10-07 Tabela `email_failures` + UI admin** adiado — log em arquivo (`mail-failures.log`) cobre diagnóstico operacional hoje.
- **Epic E14 (Redesign da área do aluno)** + **#136 E9-01 Patentes** são o próximo ciclo — base visual pra ProfileSidebar + dados de patente pra ranking.
- Remover arquivos órfãos em prod herdados: `src/lib/HtmlPurifier.php` e `public/_diag_*.php`.
- Cross-tenant smoke ainda pendente — 1 tenant em prod.

[0.7.0]: https://github.com/markimpdl/lms/releases/tag/v0.7.0

## [0.6.0] — 2026-04-24

Sexta release. Escopo: **avaliações — primeiro ciclo pedagógico completo** — Épico E7 inteiro. A partir desta release o fluxo fecha: aluno lê conteúdo (E5), entrega atividades (E6), envia avaliação com PDF de enunciado, professor corrige com nota + feedback + XP condicional, aluno acompanha status e reenvia quando liberado.

### Novas funcionalidades

#### Avaliações (Épico E7)
- CRUD de avaliação pelo professor com upload de PDF de enunciado (até 10 MB, ADR-028) via `EvaluationBriefStorage` — 1 avaliação por CU (ADR-007). Campo `instructions` em texto complementa o PDF. Edição sem PDF novo preserva o arquivo atual. Exclusão apaga arquivos físicos e cascade FK apaga submissões (E7-01).
- Entrega do aluno em `/student/evaluation/{id}` com upload (PDF/ZIP/TXT ≤ 3 MB), histórico de tentativas preservado (nome `<student_id>_<attempt>.<ext>`, sem sobrescrita), badges por estado e reenvio quando liberado. `EvaluationSubmissionService::submit` concentra os gates transacionais (1ª entrega com `submission_open=1`; reenvio exige `retry_allowed=1` na atual) (E7-02).
- Correção pelo professor em `/teacher/evaluation/{id}/submission/{student_id}` com nota `DECIMAL(3,1)` de 0,0 a 10,0, feedback, checkbox de reenvio. Regras consolidadas: **nota ≥ 6 = aprovado e sem reenvio** (clamp server-side independente do checkbox), **nota ≥ 8 = libera XP** via `XpEvents::awardEvaluation` (ADR-002, UK composite evita duplicação em re-correção). `StudentProgress` passa a contar avaliação aprovada no % da CU e do curso (E7-03).
- Listagem de entregas de uma avaliação em `/teacher/evaluation/{id}/submissions` com ordenação por pendentes (`feedback_at IS NULL` no topo), agregando só a tentativa corrente (`is_current=1`) acelerado por `idx_es_eval_current` (E7-04).
- Visão completa da avaliação pelo aluno em `/student/evaluation/{id}` com 5 estados (ainda não entregou / entregue aguardando / aprovada / reprovada sem reenvio / reprovada com reenvio liberado), badges, histórico de tentativas e form de reenvio contextual (E7-05).

### Correções
- Card "Sua entrega" não aparecia mais depois que o aluno aprovava, evitando mostrar form de reenvio indevido em estado final (#127).

### Mudanças de schema
- `evaluations.tenant_id BIGINT UNSIGNED NOT NULL` + FK `fk_evaluations_tenant` CASCADE + `idx_evaluations_tenant` — redundância deliberada pra simplificar filtro multi-tenant sem JOIN em `cu→cc→courses`.
- `evaluations.instructions TEXT NULL` — texto opcional complementar ao PDF.
- `evaluation_submissions.tenant_id` + FK `fk_es_tenant` CASCADE + `idx_es_tenant`.
- `evaluation_submissions.idx_es_eval_current (evaluation_id, is_current)` — acelera listagem do professor em E7-04.

Todas aplicadas em prod via seção "Migrações incrementais" do `install/schema.sql` (ADR-017) com backfill idempotente — tabelas estavam vazias mas o backfill é defensivo.

### Convenções consolidadas nesta janela
- **Storage por caso de uso, não genérico** — `EvaluationBriefStorage` (determinístico `brief.pdf`, pdf-only) e `EvaluationSubmissionStorage` (nome versionado `<student_id>_<attempt>.<ext>`, preserva histórico). Contraste com `SubmissionStorage` de E6 que sobrescreve.
- **Service transacional como fonte de verdade** — `EvaluationSubmissionService::submit/grade` concentram gates e clamps; JS do form só espelha por UX. Clamp `retry_allowed=0 quando grade>=6` roda server-side independente do checkbox.
- **XP idempotente por UK composite** — `(student_user_id, source_type='evaluation', source_id)` garante que re-correção 8,5→9,0 não duplica XP e 5,0→8,5 credita na 2ª.
- **Filtro multi-tenant direto sem JOIN** — quando a coluna `tenant_id` redundante existe (E7-00), filtra por ela. Mais barato que JOIN encadeado até `courses`.

### Tooling
- `package.json` bumpado para 0.6.0.

### Pendências
- Notificações `new_evaluation` / `grade_evaluation` / `retry_enabled` adiadas pro Epic E10 (TODOs nos pontos de disparo).
- Execução online de código (Judge0) — Epic E8.
- Gamificação completa (rankings, badges visuais) — Epic E9.
- Remover arquivos órfãos em prod herdados: `src/lib/HtmlPurifier.php` e `public/_diag_*.php`.
- Cross-tenant smoke ainda pendente — 1 tenant em prod.

[0.6.0]: https://github.com/markimpdl/lms/releases/tag/v0.6.0

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
