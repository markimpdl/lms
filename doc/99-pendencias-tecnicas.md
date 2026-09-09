# 99 — Pendências técnicas

Lista de itens que ficaram anotados ao longo do desenvolvimento e que precisam ser verificados/executados antes de cada milestone. Atualize conforme itens forem sendo resolvidos (marque `✅` e mova para `Resolvidas` no fim).

Para decisões arquiteturais já tomadas, ver `14-decisoes-e-pendencias.md` (ADRs).

> **Triagem de 2026-09-09 — nenhum item aberto.** O PO revisou a lista inteira: mandou corrigir a cópia de curso (feita, ver `Resolvidas`), **descartou** os 8 itens que restavam como pendência real e o resto estava vencido — 22 validações "contra MySQL real" de E0–E2 que a produção já exercitou desde 2026-04-22, as 4 "pendências externas" que bloqueavam stories hoje em produção (Judge0, FTPS, cPanel) e o HTMLPurifier, que entrou. Vencido foi marcado como **RESOLVIDA** com a evidência, não como descartado: trabalho feito não é trabalho ignorado.
>
> A lista tinha 35 itens abertos e ~26 eram ficção. Se ela voltar a crescer sem ninguém triar, desconfie do mesmo jeito.

---

## ~~Prism.js carregado via CDN externa (POLISH-03)~~ ✅ DESCARTADA (2026-09-09)

- **Descartada na triagem do PO em 2026-09-09.** Cai pra `<pre>` sem cor se o cdnjs falhar, não quebra nada. Revisitar só se aparecer CSP estrita ou reclamação de aluno em rede restritiva.

O syntax highlighting de blocos de código publicados (POLISH-03) usa **Prism 1.29.0** servido pelo `cdnjs.cloudflare.com`:
- `themes/prism-tomorrow.min.css`
- `prism.min.js` (core + markup/css/clike/javascript)
- `components/prism-python.min.js`
- `components/prism-csharp.min.js`

Carregado apenas em áreas autenticadas (`$isThemedArea` no `layout.php`). Se o cdnjs ficar fora do ar ou for bloqueado, blocos de código publicados após esta versão renderizam como `<pre>` simples sem cor — não quebra nada, só perde a cor.

- **Ação futura:** considerar self-host (vendorar em `public/assets/vendor/prism/`) se houver CSP estrita, comportamento offline, ou se a hospedagem bloquear cdnjs.
- **Quando:** quando trocarmos a estratégia de hospedagem ou se aparecer reclamação de aluno em rede corporativa restritiva.

---

## ~~Compatibilidade com PHP 8.3 em produção~~ ✅ RESOLVIDA (v0.29.0)

- Duplicata: a entrada "Hostinger subiu o domínio para PHP 8.3" na seção **Resolvidas** já registra isso. Produção roda 8.3 desde 2026-04-22, validada por todo o roadmap até v0.30.0, e o CLAUDE.md já reflete 8.3. O texto abaixo ficou vencido (dizia que o CLAUDE.md precisava ser atualizado).

A Hostinger subiu automaticamente o domínio `lms.rumo.info` para **PHP 8.3** durante o primeiro deploy (v0.1.0 — 2026-04-22). O projeto foi escrito mirando PHP 8.2 e `composer.json` trava em `"php": "^8.2"` (permite 8.2/8.3/8.4), então o código roda, mas não foi validado em 8.3.

- **Ação:** varrer o código em busca de features/APIs que mudaram de comportamento em 8.3 e validar que nenhuma está em uso indevido. Candidatos a checar: [`DateTime::createFromFormat`](https://www.php.net/manual/en/datetime.createfromformat.php) (stricter), `Random\Randomizer` (não usamos), deprecations de `E_STRICT`, `readonly` em classes, `#[Override]` (não usamos — CLAUDE.md proíbe).
- **Ação:** conferir no painel se é possível voltar a 8.2 (algumas hospedagens deprecam). Se não for possível, atualizar CLAUDE.md para refletir 8.3 e relaxar a proibição de features 8.3.
- **Risco baixo:** o código usa subset conservador de PHP (PDO, sessões, funções string/array, `password_hash`, `random_bytes`, `hash_hmac`). Nada documentadamente quebrado em 8.3.
- **Quando:** primeira oportunidade antes de E3 ganhar volume de código; minimamente rodar o smoke test de v0.1.0 e observar os `storage/logs/*.log` após as primeiras sessões reais.

---

## ~~Validações que dependem de ambiente real (Hostinger / MySQL)~~ ✅ RESOLVIDAS (2026-09-09)

Eram 22 itens do tipo "validar login / cadastro de professor / métricas do super-admin contra MySQL real" e "conferir viewport 360px", escritos como checklist do MVP (E0–E2) quando ainda não havia ambiente.

**Validados por uso real:** o sistema está em produção em `lms.rumo.info` desde 2026-04-22 (v0.1.0) e o roadmap seguiu até v0.30.0 com uso diário de professor e alunos — login, recuperação de senha, perfil, cadastro e toggle de professor, métricas e reset administrativo todos exercitados em MySQL/MariaDB real, no mobile inclusive. Manter a lista aberta só fazia a página mentir sobre o tamanho da dívida.

---

## Limpezas de produção (não-bloqueantes)

### ~~404 do front controller ainda emite token CSRF~~ ✅ DESCARTADA (2026-09-09)
- **Descartada na triagem do PO.** `CSRF_POOL_MAX` é 128 e as 13 rotas que servem arquivo já respondem seco, então sobra pouco caminho pra doer.
- `abort_subresource()` cobre as 13 rotas que servem arquivo (anexo, brief, PDF de relatório, widget) — lá o erro virou texto seco, sem `layout.php`, e por isso sem `csrf_field()` do `header.php`.
- **Sobra um caminho:** URL que não casa com rota nenhuma cai no 404 do `public/index.php`, que renderiza a página de erro completa e portanto emite um token. Um `<img>` apontando pra URL fora do padrão de rota (formato antigo, por exemplo) gasta um slot do pool por imagem.
- **Impacto:** baixo — `CSRF_POOL_MAX` é 128, então é preciso muita imagem quebrada e fora de rota pra despejar o token de uma aba com formulário aberto. As URLs de anexo do conteúdo casam com rota e já estão cobertas.
- **Ação (se virar problema):** no fallthrough do front controller, responder seco quando o `Accept` da request não incluir `text/html` — é o sinal que separa `<img>`/download de navegação de gente.

### ~~Re-hospedagem de imagem grava anexo antes do save que pode falhar~~ ✅ DESCARTADA (2026-09-09)
- **Descartada na triagem do PO.** A janela é entre o GET e o POST do formulário, e o dedup por hash reusa o anexo órfão na colagem seguinte em vez de multiplicar.
- `ContentImageRehost::apply` copia arquivo + cria linha em `content_attachments` e só **depois** o caller chama `Lesson::create` / `Lesson::update` / `Content::upsertForCu` (`src/pages/teacher/lesson/new.php`, `lesson/edit.php`, `cu/content-edit.php`).
- **Sintoma:** se o save devolver `course_archived` (curso arquivado entre o GET e o POST) ou `not_found` (CU apagada), o HTML reescrito é descartado mas o anexo copiado fica na CU, sem ninguém apontando pra ele, contando contra o teto de 50. O `flashResult` também não é alcançado, então o professor não fica sabendo.
- **Impacto:** baixo — a janela é entre o carregamento do form e o submit, e o arquivo repetido é reusado pelo dedup (hash) na próxima vez que a mesma imagem for colada, em vez de multiplicar.
- **Ação (se virar problema):** rodar o rehost só depois de o save confirmar, com um segundo UPDATE só do `html`; ou registrar os anexos criados e apagá-los no caminho de erro.

### ~~Reparo retroativo de imagens carrega todo o HTML do tenant na memória~~ ✅ DESCARTADA (2026-09-09)
- **Descartada na triagem do PO.** Escopo de um tenant, `set_time_limit(0)` e idempotência — se estourar, rodar de novo continua.
- `public/_rehost_foreign_images.php` faz `fetchAll()` de `contents.html` + `lessons.html` (ambos `MEDIUMTEXT`) e uma query por id de anexo por linha, mais `hash_file` por candidato a gêmeo.
- **Mitigado:** escopo é um tenant só (super-admin não roda), `@set_time_limit(0)` no POST, e a operação é idempotente — se estourar no meio, rodar de novo continua de onde deu.
- **Ação:** se algum tenant crescer o bastante pra estourar memória, paginar por curso.

### ~~XP da avaliação não é revogado quando o professor rebaixa a nota~~ ✅ DESCARTADA (2026-09-09)
- **PO considerou o comportamento OK na triagem de 2026-09-09.** Coerente com a decisão de que XP é congelado no evento: rebaixar nota já corrigida é raro e o desvio é a favor do aluno. A divergência entre o cabeçalho da unidade (deriva da nota) e perfil/ranking (leem o snapshot) fica aceita.
- `XpEvents::awardEvaluation` credita a partir de `XpEvents::EVALUATION_MIN_GRADE` (8.0) e grava um **snapshot** em `xp_events`. Não existe caminho de revogação: `revokeEvaluation()` nunca é chamado.
- **Sintoma:** professor corrige com 9 (XP creditado) e depois rebaixa para 7. O cabeçalho da unidade passa a NÃO contar aquele XP (ele deriva da nota atual), enquanto o perfil e o ranking continuam com o evento gravado. As duas telas divergem. Mesmo efeito ao editar `evaluations.xp_value` depois do crédito.
- **Impacto:** baixo — rebaixar nota já corrigida é raro, e o desvio é a favor do aluno no total global.
- **Contexto:** decisão do PO de que XP é congelado no evento (ver `project_xp_snapshot_decision`) é o que torna isso "esperado" e não bug; o que falta é a UI da unidade concordar com o snapshot, ou um revoke-on-regrade explícito.
- **Ação:** decidir com o PO entre (a) revogar XP no rebaixamento, ou (b) o cabeçalho ler `xp_events` em vez de derivar da nota.


### ~~[E26 v1] Template Skills Hub renderiza diferente do Word original~~ ✅ DESCARTADA (2026-09-09)
- **Descartada na triagem do PO**, ciente de que era o item de maior impacto de UX da lista. Reabrir como história (E30/F21) se a fidelidade ao Word voltar a incomodar.
- A v1 do `public/assets/report-templates/skill_hub/template.html` foi gerada via Word "Save as Web Page Filtered" e usa marcação MSO-específica (`<!--[if gte mso 9]>`, VML, fontes Calibri/Arial sem fallback no dompdf, tabelas sem larguras explícitas).
- **Sintoma**: layout do PDF gerado fica visualmente diferente do PDF que o Word exporta diretamente. Esperado dada a estratégia de migração — `Save as HTML Filtered` é o caminho de menor esforço pra sair do Word, não o de melhor render no dompdf.
- **Impacto:** alto pra UX do PO (que esperava fidelidade visual ao Word).
- **Ação:** **E30 (F21)** — refazer o template em HTML/CSS limpo, fiel ao `template_reports/CU_SKILL_HUB_PDF.pdf`. Se mesmo após reescrever houver gaps, considerar swap dompdf→mPDF. Backfill das submissions já corrigidas executando `ReportService::generate` em loop.
- **Mitigação enquanto E30 não chega:** pipeline (trigger, storage, endpoint, segurança) está OK e funciona com qualquer template — quando E30 substituir o template.html + rodar backfill, todos os PDFs antigos são regerados sem nova migração de schema.

### ~~[E26-04] Bootstrap Icons CSS não carrega fora do student area~~ ✅ DESCARTADA (2026-04-27)
- PO decidiu que o impacto é baixo demais pra justificar mudança. Pode ficar como está.

### ~~[E25-02] Re-cadastro de LOs apaga notas existentes via FK CASCADE~~ ✅ RESOLVIDA (v0.29.0)

### ~~[E25-05] UC sem 5 LOs cadastrados em curso LO mode — aluno fica sem orientação~~ ✅ DESCARTADA (2026-09-09)
- **Descartada na triagem do PO.** Janela de transição, e o professor já recebe alerta clicável pro cadastro.
- Quando o curso virou LO depois de criar UCs, e o professor ainda não cadastrou os 5 LOs em alguma CU, o aluno em `/student/evaluation/{id}` daquela CU **não vê o card "Critérios avaliados"** (defesa silenciosa: só renderiza com `loList !== []`).
- **Impacto:** baixo — feedback do professor já está bloqueado nesse cenário (E25-03 mostra alerta clicável pro cadastro), então o aluno fica sem orientação só durante a janela de transição.
- **Ação (se virar problema):** mostrar mensagem alternativa pro aluno ("Os critérios desta avaliação estão sendo definidos pelo professor"). Sobre-engineering pro MVP.

### ~~[E24-03] Logos órfãs em `public/uploads/logos/`~~ ✅ RESOLVIDA (v0.29.0)

---

## ~~Pendências externas (bloqueiam stories específicas)~~ ✅ TODAS RESOLVIDAS (2026-09-09)

Nenhuma bloqueia mais nada — Judge0, FTPS e cPanel estão em produção. As duas de cPanel ficam como runbook pra quando houver domínio novo.

### ~~Judge0 RapidAPI~~ ✅ RESOLVIDA (2026-09-09)
- Plano **gratuito** (ADR-029). Credenciais em `config/env.php` (gitignored).
- ~~Bloqueia E8~~ — E8 está em produção; `/api/code/run` roda Python, C# e JavaScript. (Nota conhecida: o endpoint não envia stdin ao Judge0.)

### ~~FTPS Hostinger cPanel~~ ✅ RESOLVIDA (2026-09-09)
- Credenciais em `.env.deploy` (gitignored).
- ~~Bloqueia E13-03~~ — `scripts/deploy/ftp-deploy.mjs` é o caminho de deploy real desde então (`npm run deploy`, incremental por hash com state em `scripts/deploy/.ftp-state.json`).

### ~~cPanel — seleção de versão PHP~~ ✅ RESOLVIDA (2026-09-09) — mantido como runbook
- **Status (2026-04-27):** Hostinger subiu o domínio pra **PHP 8.3** automaticamente em 2026-04-22. Validado por uso real (todo o roadmap rodou em 8.3 sem incidente). CLAUDE.md atualizado pra refletir 8.3.
- **Ação na primeira configuração de domínio novo:** travar em PHP **8.3** no MultiPHP Manager. Não deixar no "latest" (pode pular versões sem aviso).

### ~~cPanel — document root~~ ✅ RESOLVIDA (2026-09-09) — mantido como runbook
- Já configurado em `lms.rumo.info`. **Ao configurar um domínio novo:** apontar o document root para `/public_html/public/` (e não `/public_html/`) — o front controller e o `.htaccess` estão em `public/`.

### ~~[E36-08] Nav Voltar/Avançar da atividade + modo foco~~ ✅ DESCARTADA (2026-09-09)
- **Descartada na triagem do PO.** O que faltava era só o smoke com banco dos 3 fluxos; a parte visual (modo foco, item travado, mobile, dark) já tinha sido verificada em 2026-09-07, e o beco sem saída da avaliação foi corrigido na mesma data.

- **Status (2026-09-07):** o **modo foco foi verificado em navegador** num harness estático com o CSS/JS reais (Chrome, 1728px e 386px, claro e escuro). Confirmado: sidebar → rail de ícones, conteúdo de 490px → 1019px, trilha permanece à direita (x=1194), estado sobrevive ao reload sem flash e sem `defer`, os dois toggles (conteúdo e rail) sincronizam ícone/`aria-pressed`/label, mobile 386px devolve a grid de 1 coluna e esconde rail + botão sem scroll horizontal, dark mode herda os tokens corretamente.
- **O que continua SEM execução:** todo o caminho que depende de banco — não há MySQL local (`config/env.php` aponta pra `rumo_lms@localhost`, acesso negado). O harness renderiza markup equivalente, não as páginas PHP reais.
- **A conferir no smoke em lote:**
  1. `/student/activity/{id}` em curso **V1**: Voltar → `/student/cu/{id}`; Avançar aparece só depois de entregue e vai pro mesmo lugar. Vale pros 3 fluxos: projeto/código, quiz e submissão já com feedback (read-only).
  2. `/student/activity/{id}` em curso **V2**: Voltar → item anterior da trilha (capa da CU quando a atividade abre o percurso); Avançar → próximo item, incluindo a avaliação que fecha a trilha; volta pra capa quando é o último ou quando a avaliação está travada (`UnitTrackService::evaluationUnlocked`).
  3. Botão maximizar aparece só em curso **V2** (lição sempre; atividade e quiz quando `structure_version = 2`); o restaurar, no rail, aparece em qualquer tela do aluno.
- **Beco sem saída da avaliação — CORRIGIDO (2026-09-07).** O "Próximo →" da lição, o redirect do "Concluir e continuar" (`lesson/complete.php`) e os links da timeline apontavam pra avaliação sem checar `eval_after_activities`; com `activity_mode = 'free'` o aluno chegava ao fim da trilha com exercícios pendentes e era devolvido pra capa com flash `progression.eval_locked` — no `complete.php`, logo depois de ganhar o XP da lição. Agora há **um único ponto de decisão**, `UnitTrackService::nextHrefForStudent()`, usado pelos 3 CTAs de avanço; na timeline a avaliação travada vira `<span>` (não `<a>`) com cadeado e a dica do motivo. A checagem visual do item travado está feita (claro e escuro, coluna de 274px e maximizada, dica quebra em 2 linhas sem vazar); o que falta é o caminho com banco.
- **Removido nessa mudança:** os dois CTAs `submissions.form.continue` ("Continuar para a unidade") de `student/activity/show.php` — o Avançar da nova barra cobre os dois casos. A chave saiu de `lang/pt.php` e `lang/en.php`.

---

## Inconsistências menores de documentação para alinhar

### ~~[E0-03] Skill `/code-review` cita `MySQL::pdo()` em vez de `Database::pdo()`~~ ✅ RESOLVIDA (v0.29.0)

### ~~[E0-04] Divergência AC "toast" vs. implementação "alert"~~ ✅ DESCARTADA (2026-09-09)
- **Descartada na triagem do PO** (duplicata: o mesmo item já aparecia acima).
- A issue #17 fala em "toast Bootstrap"; a implementação usa `alert` do Bootstrap.
- **Justificativa:** alerts são melhores para PRG (mensagens pós-redirect persistem até dismiss; não auto-fecham como toasts). Toasts combinam mais com notificações ephemeral dirigidas por JS client-side.
- **Ação:** se em uso o PO quiser toasts de verdade, trocar em uma story futura.

---

## Composer / dependências a trazer

### ~~HTMLPurifier~~ ✅ RESOLVIDA (2026-09-09)
- Entrou: `composer.json` trava `ezyang/htmlpurifier ^4.19` e o pacote está em `vendor/ezyang/`. É o que o `ContentSanitizer::purify()` usa pra sanitizar o HTML do TinyMCE (conteúdo da CU e lição) antes de gravar, incluindo o `URI.SafeIframeRegexp` que limita iframe a YouTube e Vimeo.

---

## Resolvidas

- **2026-09-09 — Bug: cópia de curso levava as imagens apontando pro curso de origem, e perdia as lições.** `CourseCopyService::copyContent` inseria o `html` literal e criava os anexos novos sem reescrever os `src`, então curso duplicado nascia citando os anexos do original: o professor via tudo (a rota dele autoriza por tenant) e o aluno do curso novo tomava 404, porque `findForStudent` autoriza pela matrícula no curso **dono do anexo**. Mesmo bug que o `ContentImageRehost` conserta no save, aqui na raiz. `copyContent` passa a devolver o mapa `aid de origem => aid novo` e reescreve o HTML com `ContentImageRehost::remap()` (mapa explícito, então funciona também na cópia entre tenants, onde o rehost por tenant não alcançaria). **No mesmo pacote:** `copyCuInto` não visitava `lessons` — duplicar curso V2 devolvia a trilha VAZIA, com unidades e atividades no lugar e nenhuma lição, sem erro nenhum. Novo `copyLessons()` copia título, html (remapeado), XP, published e position; `lesson_completions` fica de fora por ser progresso de aluno. Vale pros três pontos de entrada (`duplicateCourse`, `copyCoreCompetence`, `copyCompetenceUnit`), que todos passam por `copyCuInto`.

- **2026-04-23 — Bug: `countDescendants` quebra com `PDO::ATTR_EMULATE_PREPARES = false`.** Descoberto no smoke test do Epic E3 (PDOException `SQLSTATE[HY093] Invalid parameter number` ao abrir `/teacher/courses/{id}`). Causa: `Course`, `CoreCompetency` e `CompetenceUnit` reusavam o placeholder nomeado `:id` várias vezes na mesma query; com emulação desligada em produção (MariaDB 10.11) cada ocorrência conta como slot separado e `execute()` com um único valor para `:id` dispara o erro. Fix: trocar para placeholders posicionais `?` e passar o mesmo valor múltiplas vezes. Revisão: `TeacherAdmin::findById` e `Course::findForTenant` usam `:id` só uma vez — OK.
- **2026-04-23 — Bug crítico: `tenant_id` do professor nunca era resolvido na sessão.** Descoberto no smoke test do Epic E3 em produção: criar curso / qualquer página `/teacher/*` que usa `current_tenant_id()` caía em 403. Causa: `users.tenant_id IS NULL` para teachers (CHECK constraint `chk_users_role_tenant`); o elo real é `tenants.owner_user_id = users.id` (ADR-025), mas `AuthController::authenticate()` lia `tenant_id` direto de `users`, gravando NULL na sessão. Fix em `src/controllers/AuthController.php`: SELECT agora faz `LEFT JOIN tenants t ON t.owner_user_id = u.id AND t.active = 1` + `COALESCE(t.id, u.tenant_id) AS tenant_id`. Preserva student (vem de `u.tenant_id`) e super_admin (permanece NULL). Sessões anteriores precisam de logout/login para repopular.
- **2026-04-22 — [E10-03] PHPMailer + SMTP real.** `composer.json` criado (`php ^8.2` + `phpmailer/phpmailer ^6.9`), `bootstrap.php` passa a incluir `vendor/autoload.php`, `Mailer::send()` usa PHPMailer quando `SMTP_HOST/USER/PASS/FROM` estão preenchidos em `config/env.php` e mantém o fallback em `storage/logs/mail-debug.log` caso contrário. Falhas de SMTP vão para `storage/logs/mail.log` (não relançam). `Mailer::isConfigured()` passa a ler o env. Interface pública intocada — callers de E1-03/E2-02/E2-07 seguem iguais. Credenciais de produção em `config/env.php` (gitignored). Item "[E1-03] Integração PHPMailer quando E10 chegar" e "SMTP Hostgator" (pendência externa) saem da lista.
- **2026-04-22 — Pendência externa "SMTP Hostgator" reclassificada.** O domínio `lms.rumo.info` oferece SMTP próprio (cPanel/Hostinger) — não é mais Hostgator. Credenciais já em `config/env.php` (gitignored).
- **2026-04-27 — [E25-02] Re-cadastro de LOs apaga notas existentes via FK CASCADE (v0.29.0).** Adicionado `LearningOutcome::countGradesByCu(int $cuId): int` (JOIN learning_outcomes × evaluation_submission_lo_grades). Page handler `src/pages/teacher/cu/learning-outcomes.php` exibe alerta amarelo + checkbox `confirm_drop_grades` quando `gradesCount > 0`; submit sem confirmação devolve erro `learning_outcomes.err.confirm_required`. 4 chaves i18n novas em PT/EN.
- **2026-04-27 — [E24-03] Logos órfãs em `public/uploads/logos/` (v0.29.0).** Adicionado `Tenant::clearLogo(int $tenantId): void` (zera só `logo_path`, mantém `platform_name`). `AdminTeachersController::update` agora detecta transição não-Actvet → Actvet e, se há `logo_path` setada, chama `clearLogo` dentro da tx + `LogoStorage::deleteByBasename` após o commit (best-effort). Cobre o caso comum; logos órfãs já existentes em prod requerem cleanup manual via FileZilla (raras).
- **2026-04-27 — [E0-03] Skill `/code-review` cita `MySQL::pdo()` em vez de `Database::pdo()` (v0.29.0).** Trocado em `.claude/skills/code-review.md`.
- **2026-04-27 — Hostinger subiu o domínio para PHP 8.3 (v0.29.0).** Auto-upgrade da Hostinger em 2026-04-22; validado por uso real durante todo o roadmap pós-MVP estendido (E15-E30). CLAUDE.md atualizado pra refletir 8.3 (features 8.3 OK, ainda proibir 8.4).
