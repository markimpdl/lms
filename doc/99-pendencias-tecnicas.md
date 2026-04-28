# 99 — Pendências técnicas

Lista de itens que ficaram anotados ao longo do desenvolvimento e que precisam ser verificados/executados antes de cada milestone. Atualize conforme itens forem sendo resolvidos (marque `✅` e mova para `Resolvidas` no fim).

Para decisões arquiteturais já tomadas, ver `14-decisoes-e-pendencias.md` (ADRs).

---

## Prism.js carregado via CDN externa (POLISH-03)

O syntax highlighting de blocos de código publicados (POLISH-03) usa **Prism 1.29.0** servido pelo `cdnjs.cloudflare.com`:
- `themes/prism-tomorrow.min.css`
- `prism.min.js` (core + markup/css/clike/javascript)
- `components/prism-python.min.js`
- `components/prism-csharp.min.js`

Carregado apenas em áreas autenticadas (`$isThemedArea` no `layout.php`). Se o cdnjs ficar fora do ar ou for bloqueado, blocos de código publicados após esta versão renderizam como `<pre>` simples sem cor — não quebra nada, só perde a cor.

- **Ação futura:** considerar self-host (vendorar em `public/assets/vendor/prism/`) se houver CSP estrita, comportamento offline, ou se a hospedagem bloquear cdnjs.
- **Quando:** quando trocarmos a estratégia de hospedagem ou se aparecer reclamação de aluno em rede corporativa restritiva.

---

## Compatibilidade com PHP 8.3 em produção

A Hostinger subiu automaticamente o domínio `lms.rumo.info` para **PHP 8.3** durante o primeiro deploy (v0.1.0 — 2026-04-22). O projeto foi escrito mirando PHP 8.2 e `composer.json` trava em `"php": "^8.2"` (permite 8.2/8.3/8.4), então o código roda, mas não foi validado em 8.3.

- **Ação:** varrer o código em busca de features/APIs que mudaram de comportamento em 8.3 e validar que nenhuma está em uso indevido. Candidatos a checar: [`DateTime::createFromFormat`](https://www.php.net/manual/en/datetime.createfromformat.php) (stricter), `Random\Randomizer` (não usamos), deprecations de `E_STRICT`, `readonly` em classes, `#[Override]` (não usamos — CLAUDE.md proíbe).
- **Ação:** conferir no painel se é possível voltar a 8.2 (algumas hospedagens deprecam). Se não for possível, atualizar CLAUDE.md para refletir 8.3 e relaxar a proibição de features 8.3.
- **Risco baixo:** o código usa subset conservador de PHP (PDO, sessões, funções string/array, `password_hash`, `random_bytes`, `hash_hmac`). Nada documentadamente quebrado em 8.3.
- **Quando:** primeira oportunidade antes de E3 ganhar volume de código; minimamente rodar o smoke test de v0.1.0 e observar os `storage/logs/*.log` após as primeiras sessões reais.

---

## Validações que dependem de ambiente real (Hostinger / MySQL)

### [E0-03] `scripts/dbcheck.php` contra MySQL real
- Feito apenas o error-path no dev local (sem `pdo_mysql` instalado).
- **Ação:** quando subir para Hostinger, rodar `php scripts/dbcheck.php` e conferir saída `OK — conectado ao MySQL <versão>. SELECT 1 + 1 = 2.`
- **Quando:** junto do deploy da primeira versão (E13-03) ou antes se subir staging manual.

### [E0-03] `Database::tx()` rollback efetivo
- Lógica revisada por código; rollback automático ao lançar exceção dentro do callable.
- **Ação:** validar no primeiro model real (E2 ou E3) com um teste do tipo: `tx(fn() => insert + throw)` e confirmar que a linha **não** existe no banco depois.

### [E1-01] Login end-to-end com MySQL real
- Smoke testado só o rendering e a lógica pura do `AuthController` (`dashboardFor`, `safeNext`). `isIpBlocked`, `recordAttempt` e `authenticate` requerem `login_attempts` e `users` em banco real.
- **Ações:** (a) rodar `install/schema.sql` atualizado (agora com `password_changed_at` em users) no phpMyAdmin; (b) `php install/seed-admin.php` para definir a senha do super-admin; (c) `curl -i http://<host>/login` deve retornar 200 com form; (d) submeter POST inválido 6× e verificar que o 6º recebe `auth.rate_limited`; (e) submeter POST válido e verificar redirect para `/admin` + sessão persistida.

### [E1-01] Validar mobile 360×640 do /login
- Form com `form-control-lg` (≥48px de altura) + botão `btn-lg`, layout `col-12 col-sm-8`. Precisa de DevTools para confirmar zero overflow e fontes ≥16px.

### [E1-03] Fluxo de recuperação de senha com MySQL real
- Schema tem nova tabela `password_resets` (id, user_id FK CASCADE, token_hash CHAR(64) UNIQUE, expires_at, used_at, created_at, idx user+created).
- Lógica testada só em partes: `__t(lang)`, `Mailer::send` (fallback log), rotas. A geração+validação+consumo de token precisa de MySQL real.
- **Ações:**
  - Rodar schema.sql atualizado (agora 19 tabelas); conferir `password_resets` criada
  - Em `/forgot`, submeter email de super-admin → verificar linha nova em `password_resets`, email em `storage/logs/mail-debug.log` com link `/reset?token=...`
  - Abrir o link em browser → form de nova senha aparece
  - Submeter senha nova ≥8 chars → login funciona; token fica `used_at` preenchido
  - Submeter o mesmo link 2ª vez → "Link inválido ou expirado" (one-time use confirmado)
  - Submeter `/forgot` 4× no mesmo email → 4ª solicitação silenciosamente ignorada (rate limit 3/h)
  - Email inexistente → sempre mensagem genérica, nenhum token criado

### [E1-03] Mobile 360×640 das telas /forgot e /reset
- Mesmo padrão do login (`col-12 col-sm-8`, `form-control-lg`). Validar no DevTools.

### [E1-04] Fluxo de edição de perfil com MySQL real
- Código revisado; sem MySQL local não dá para validar as queries `UPDATE users` e `password_verify` na senha atual.
- **Ações:**
  - Logar como super-admin → acessar `/profile` → ver abas "Dados" e "Senha"
  - Editar nome, salvar → flash de sucesso; recarregar e confirmar que persistiu
  - Trocar idioma pt → en → UI deve ficar em inglês imediatamente (sem logout/login)
  - Confirmar que o campo email está `readonly` + `disabled` e tooltip aparece no hover
  - Aba "Senha": submeter com senha atual errada → erro `profile.error.current_wrong`
  - Submeter nova senha com <8 chars → erro `profile.error.password_min`
  - Submeter nova ≠ confirmação → erro `profile.error.password_mismatch`
  - Submeter válido → flash; logout/login com a nova senha deve funcionar; `users.password_changed_at` atualizado no banco

### [E1-04] Mobile 360×640 da tela /profile
- `col-12 col-md-10 col-lg-8`, `form-control-lg`. Validar no DevTools que as nav-tabs não quebram em 360px e que os botões ficam full-width abaixo de sm.

### ~~[E1-04] Bug menor no helper `current_lang()`~~ ✅ RESOLVIDA
- Corrigido em PR #81 (hotfix do smoke v0.3.0). `current_lang()` agora lê `current_user()['language']` corretamente.

### [E1-05] Middleware de auth com MySQL real
- Código revisado; sem MySQL local não dá para validar as SELECTs de `active` + `password_changed_at` que `require_auth()` agora faz a cada request autenticada.
- **Ações:**
  - Logar como super-admin → acessar `/admin` (funciona normal); abrir DevTools Network e checar que a request não dispara logout.
  - No banco, `UPDATE users SET active = 0 WHERE id = 1` → próxima navegação para `/admin` deve deslogar com flash `auth.account_deactivated`.
  - Restaurar `active = 1`, logar de novo. Em outra aba, usar `/profile` → aba Senha para trocar a senha. Na aba antiga, qualquer clique deve disparar `auth.session_invalidated` (comparação de `password_changed_at`).
  - Aluno tentar acessar `/admin` → 403 amigável (não stack trace nem tela branca).
  - Rota inexistente (`/qualquer-coisa-aleatoria`) → 404 amigável.
  - `/admin/foo/bar` → deve cair no handler de `/admin` (prefix match); aluno em `/admin/foo` ainda recebe 403.
- **Risco conhecido:** a comparação de `password_changed_at` é string-to-string. O UserController já gera o timestamp em PHP e passa literal ao UPDATE para evitar divergência de 1s entre clock do MySQL e do PHP. Se um futuro caller voltar a usar `CURRENT_TIMESTAMP`, o bug ressurge — sentinela para o review.

### [E2-01] Listagem de professores com MySQL real
- `users.last_login_at` é nova (schema v atual). Schema precisa ser rerrodado (ou `ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL AFTER password_changed_at;` manualmente em ambiente já existente — ainda não temos um, mas fica a nota).
- `AuthController::completeLogin` agora dispara `UPDATE users SET last_login_at = CURRENT_TIMESTAMP` a cada login — testar que preenche e que a listagem mostra o valor corretamente formatado.
- `TeacherAdmin::list` faz LEFT JOINs em `tenants → courses → enrollments` com `COUNT(DISTINCT ...)`. Volume esperado é baixo (dezenas de professores) — validar `EXPLAIN` se em produção a query começar a lentidão aparecer.
- **Ações:**
  - Sem professores: abrir `/admin/teachers` → empty state com CTA "Cadastrar primeiro professor" (botão desabilitado por ora).
  - Inserir 2 professores via phpMyAdmin; logar com um → `last_login_at` preenchido, outro ainda em "nunca".
  - Criar 1 curso ativo e 1 curso `archived=1` para um dos professores; matricular 2 alunos no ativo → contadores `Cursos ativos = 1` e `Alunos únicos = 2` devem bater.
  - Testar filtros: buscar por fragmento do email; status ativo/inativo; idioma PT/EN.
  - Clicar em cada cabeçalho de coluna e confirmar que ordena corretamente (e alterna ASC/DESC ao clicar duas vezes).
  - Criar >20 professores (script rápido) para ver paginação funcionando (prev/next, página X de Y, anchors preservam filtros).
- **Mobile 360×640:** tabela some e vira cartão empilhado em `<lg`; validar que cada cartão não estoura a viewport e que badges ficam legíveis.
- Todos os botões de ação agora estão funcionais (E2-03 liga "Abrir", E2-04 liga toggle).

### [E2-02] Cadastro de professor com MySQL real
- Schema ganhou `UNIQUE KEY uk_tenants_name (name)` — precisa re-rodar `install/schema.sql` (ou `ALTER TABLE tenants ADD UNIQUE KEY uk_tenants_name (name);`).
- `TeacherProvisioningService::create` faz pré-check de unicidade + transação com rollback em falha. Precisa validar em MySQL real que o rollback funciona: forçar um ERROR (ex.: violar `chk_users_role_tenant` temporariamente) e confirmar que nenhum user/tenant foi gravado.
- Email é enviado via `Mailer::send`; `Mailer::isConfigured()` devolve `false` até E10-03 conectar o PHPMailer. Enquanto isso, o fallback grava credenciais em `$_SESSION['teacher_creds_once']` e a listagem mostra uma vez.
- **Ações:**
  - Abrir `/admin/teachers/new` logado como super-admin → ver alert azul informando que email não está configurado.
  - Submeter form vazio → erros por campo visíveis (name, email, password, tenant_name).
  - Email com formato inválido → erro `form.err.email_invalid`.
  - Reutilizar email já existente em users → erro `form.err.email_taken`.
  - Reutilizar nome de tenant já existente → erro `form.err.tenant_taken` (case-insensitive via collation).
  - Criar com sucesso (checkbox ligado, mas SMTP off) → redirect para `/admin/teachers` com flash + card alert verde mostrando email/senha/tenant.
  - Atualizar a página → card some (transient drenado da sessão).
  - Logar com o novo professor e confirmar acesso + idioma correto.
  - Testar edge: o JS de sugestão automática do nome do tenant espelha enquanto o campo não é editado, mas para de espelhar assim que admin digita no tenant.
- **Mobile 360×640:** form em coluna única, campos com `form-control-lg`, botões visíveis sem scroll horizontal. Alert de credenciais com `dl.row.small` precisa caber na viewport.
- **Placeholder de template de email:** templates PHP por idioma (`teacher_welcome.{pt,en}.php`) duplicam pouca HTML mas saem do padrão `__t()` do email de reset. Considerado aceitável pela separação clara do AC; se virar problema de manutenção, migrar para chaves i18n + template único.

### [E2-03] Edição de professor com MySQL real
- Nova camada no roteador: `role_patterns` em `src/routes.php` + bloco regex em `public/index.php` (entre exact e prefix match). Capturas viram `$_REQUEST[nome_param]`.
- `TeacherAdmin::findById` reusa o JOIN da listagem com `WHERE u.id = ? AND u.role = 'teacher'` — carrega também `tenant_id` e `tenant_name`.
- `Tenant::rename` faz pré-check + UPDATE; captura SQLSTATE 23000 como rede de segurança.
- `AdminTeachersController::update` envolve `UPDATE users` e `Tenant::rename` em `Database::tx`. Erro do rename é propagado como RuntimeException e capturado como erro no campo `tenant_name`.
- **Ações:**
  - `/admin/teachers/<id>` com id válido → form preenchido + resumo read-only (status, criado em, último login, cursos ativos, alunos únicos).
  - `/admin/teachers/999999` (inexistente) → 404 amigável.
  - `/admin/teachers/abc` → 404 (regex só aceita `\d+`).
  - Email aparece readonly com tooltip e `form-text` explicativos (ADR-021).
  - Alterar nome + idioma + nome de tenant → submit → flash + redirect para listagem; F5 não altera nada.
  - Renomear tenant para um nome já usado por outro → erro `tenant_taken`; a transação não grava o UPDATE em users.
  - Logar como o professor após alterar idioma → UI no idioma novo.
- **Mobile 360×640:** form em coluna única em <md; `dl.row` usa `col-6 col-md-4` para o resumo; validar sem overflow.

### [E2-04] Toggle active do professor com MySQL real
- Rota nova `POST /admin/teachers/{id}/toggle` via `role_patterns` (reusa o padrão de E2-03). Handler em `src/pages/admin/teachers/toggle.php` só aceita POST + CSRF.
- `AdminTeachersController::toggleActive` envolve `UPDATE users` + `UPDATE tenants WHERE owner_user_id = ?` em `Database::tx`. O middleware de E1-05 cuida de expulsar sessões vivas na próxima request.
- Modal de confirmação (listagem): único no DOM, preenchido dinamicamente por `show.bs.modal` com `data-*` do botão clicado. Reativar é form POST direto (sem modal). Na tela de edição, desativar usa `window.confirm` simples porque o resumo read-only já mostra os números.
- **Ações:**
  - Listagem: clicar "Desativar" em um professor ativo → modal mostra nome, nº de cursos e de alunos; confirmar → flash "desativado" + badge vira "Inativo".
  - Cancelar no modal → nada muda.
  - Sessão ativa do professor desativado (em outra aba) → próxima request desloga com `auth.account_deactivated`.
  - Tentar logar com credenciais do professor desativado → erro genérico "E-mail ou senha inválidos".
  - Reativar → botão direto na listagem (form POST) → badge volta para "Ativo", login volta a funcionar.
  - Tela de edição `/admin/teachers/<id>`: botão no fim do card "Resumo da conta"; desativar pede `confirm()`; redirect traz de volta para a própria tela (from=edit) com flash.
- **Mobile 360×640:** modal usa `modal-fullscreen-sm-down` → ocupa a tela toda abaixo de sm.
- **Follow-up E3/E4:** `Course::listByTenant` (ainda não existe) deve filtrar `tenants.active = 1` para que cursos de tenant desativado não apareçam no dashboard do aluno. O AC de E2-04 cita esse comportamento, mas depende de features que entram em épicos posteriores. Deixar registrado para quando o model `Course` for criado.

### [E2-05] Toggle de cadastro público com MySQL real
- Helpers `setting_get` e `setting_set` usam `INSERT ... ON DUPLICATE KEY UPDATE` sobre `settings(setting_key, setting_value)` — a tabela já existia desde E0 com seed `public_registration=off`.
- **Ações:**
  - Logar como super-admin → `/admin/settings` → switch deve estar desligado (seed inicial).
  - Ligar + salvar → flash verde; reabrir a tela mostra o switch ligado.
  - Verificar no banco: `SELECT * FROM settings WHERE setting_key = 'public_registration';` → `on`; `updated_at` no momento do save.
  - `/register-teacher` (deslogado ou logado) → mensagem "cadastro fechado" (o switch não afeta ainda, porque o form público só vem pós-MVP — AC explícito).
- **Pós-MVP:** ligar o form de cadastro público de verdade em `/register-teacher`, consultando `setting_get('public_registration')`. Quando isso chegar, reusar `TeacherProvisioningService::create` (já pronto) mudando apenas quem preenche os campos (o próprio usuário).

### [E2-06] Painel de métricas do super-admin com MySQL real
- `AdminMetricsService::snapshot` faz 6+ queries agregadas (COUNT e MAX) + 1 UNION ALL para a série diária. Cache via `cached_json('admin-metrics', 300, …)` em `storage/cache/admin-metrics.json`.
- `/admin` (antes stub) agora é o painel. AC pedia `/admin/dashboard`, mas como `/admin` já era a home do super-admin (redirect pós-login), concentramos na mesma URL para evitar duas rotas "home". `/admin/dashboard` não está registrada; se o super-admin digitar, cai no prefix match e acaba no mesmo painel.
- **Ações:**
  - Logar como super-admin → `/admin` carrega o painel com cartões e gráfico (ou placeholder "sem submissões" se `max=0`).
  - Fazer submissões em `activity_submissions` / `evaluation_submissions` → os contadores sobem em ≤5 min (TTL do cache).
  - Forçar invalidação: `rm storage/cache/admin-metrics.json` → próximo F5 recarrega do banco.
  - Desativar um professor → `teachers_active` diminui, `teachers_inactive_hint` aumenta.
  - Cartão "Emails" mostra `—` com nota "Disponível quando E10 for entregue".
  - Mobile 360×640: cartões empilham em `col-6`; gráfico flex mantém 140px de altura com barras finas. Grid desktop volta a `col-lg-4` (3 por linha).
- **Follow-up E10:** expor `emails_sent_30d` / `emails_failed_30d` a partir da tabela `email_failures` (ou tabela de log equivalente). Remover o `null` literal em `AdminMetricsService::snapshot` e a nota i18n `emails_pending_hint`.
- **Produção:** validar permissões de escrita em `storage/cache/` (o helper usa `@mkdir` + `@file_put_contents` silenciosos — se falhar, página só recalcula a cada hit).

### [E2-07] Reset administrativo de senha com MySQL real
- Rota nova `POST /admin/teachers/{id}/reset-password` via `role_patterns`. Handler `src/pages/admin/teachers/reset-password.php` só aceita POST + CSRF.
- `AdminTeachersController::resetPassword` gera timestamp em PHP e passa literal no UPDATE (consistente com `UserController::changePassword`), para o middleware de E1-05 não deslogar o próprio admin por drift de relógio.
- Fluxo de transient replica o padrão de E2-02: quando SMTP off ou admin desmarca "enviar por email", a nova senha vai para `$_SESSION['teacher_password_reset_once']` e a tela de edição mostra uma vez, drenando depois. O transient é validado pelo `teacher_id` para não vazar entre telas.
- Templates de email em `src/templates/email/password_reset_by_admin.{pt,en}.php` (controller gate entre welcome/reset via helper `sendEmailTemplate`).
- **Ações:**
  - Logar como super-admin → `/admin/teachers/<id>` → botão "Resetar senha" abre modal.
  - Submeter vazio ou <8 chars → flash de erro `form.err.password_min` + redirect de volta.
  - Submeter senha válida com checkbox ligado (SMTP off) → redirect + card alert verde mostrando nova senha.
  - F5 na mesma tela → card some (transient drenado).
  - Logar com o professor usando a senha antiga → falha (password_hash novo). Logar com a nova → sucesso.
  - Sessão ativa do professor em outra aba → próximo clique desloga com `auth.session_invalidated` (middleware compara password_changed_at).
  - Após E10 subir `Mailer::isConfigured()`: alert azul some, email sai para `storage/logs/mail-debug.log` (ou SMTP real), transient deixa de aparecer.
- **Mobile 360×640:** modal usa `modal-fullscreen-sm-down`; validar que o campo de senha e checkbox cabem sem overflow.
- **Rate limit 10/h (deixado fora do escopo):** o AC cita como proteção contra abuso interno. MVP tem 1-2 super-admins, papel confiável; implementar agora envolveria uma tabela nova (ou sujar `login_attempts`). Ação futura: criar `admin_actions(admin_id, action, created_at)` ou reusar algum log de E10-04 (falhas de email) quando este existir, e contar os últimos 60min por admin.

### [E0-05/06] `install/schema.sql` executa limpo no phpMyAdmin
- Revisão visual feita por código, mas nenhum MySQL real foi executado no dev local (sem `pdo_mysql`, sem mysql client).
- **Ação:** rodar o SQL em banco vazio no phpMyAdmin Hostinger **ou** em MySQL 8 local. Esperado: criar **18 tabelas** (17 do domínio + `login_attempts`), inserir 2 seeds, zero erros. Rodar duas vezes — segunda execução deve ser no-op (idempotente via `CREATE TABLE IF NOT EXISTS` + `INSERT IGNORE`).
- **Riscos conhecidos:** (a) `CHECK` constraints só são enforcadas em MySQL 8.0.16+; (b) coluna gerada `STORED` + `UNIQUE` requer MySQL 5.7.6+; (c) `groups` é palavra reservada — cobri com backticks.
- **Atualização E0-06:** TIMESTAMP → DATETIME em todas as colunas (evita 2038); FK circular agora via `SET FOREIGN_KEY_CHECKS=0/1` (mais simples que o ALTER condicional anterior).

### [E0-05] `install/seed-admin.php` contra MySQL real
- Rodou `php -l` (ok). Não foi executado contra banco real.
- **Ação:** depois de rodar `schema.sql`, executar `php install/seed-admin.php` e confirmar que imprime o bloco com email/senha. Rodar de novo e confirmar que aparece "senha rotacionada".

### [E0-04] Layout em viewport 360px (mobile-first)
- Precisa de Chrome DevTools para validar: sem overflow horizontal, fontes ≥16px, navbar collapse funciona com o botão hamburger, dropdowns de idioma/usuário não saem da viewport.
- **Ação:** depois de mergear E0-04, abrir `index.php` em DevTools device mode 360×640.

### [E0-04] Flash messages no browser
- Smoke via render test já validou que `flash()` → layout renderiza `alert-success`, que `$_SESSION['flash']` é drenado e que o markup tem `data-bs-dismiss` + `btn-close`.
- **Ainda falta validar no browser:** clicar no "Testar flash" na raiz faz redirect 302 → `/` → mostra o alerta; botão "×" fecha o alerta com animação (depende do bundle JS do Bootstrap carregado do CDN).
- **Ação:** abrir a home, clicar em "Testar flash".

### ~~[E0-04] Seletor de idioma preserva query string atual~~ ✅ RESOLVIDA
- Logado: `/settings/language` já preservava o query string via `HTTP_REFERER` (lines 45-56 em `src/pages/settings/language.php`).
- Anônimo: era um `<form method="get" action="">` que substituía o query string — quebrava em `/reset?token=XYZ` (perdia o token). Resolvido trocando por `<a href>` que usa helper novo `lang_url($lang)` em `src/helpers.php` — merge de `array_merge($_GET, ['lang' => $lang])`.

### [E0-04] Divergência "toast" no AC vs "alert" implementado
- A issue #17 fala em "toast Bootstrap"; a implementação usa `alert-dismissible`.
- **Justificativa:** alerts são melhores para o fluxo PRG (persistem até dismiss; não auto-fecham). Toasts combinam mais com notificações ephemeral dirigidas por JS client-side.
- **Ação:** se preferir toast de verdade, trocar em follow-up (ex.: após login poderia ser toast, após erro de validação fica alert).

---

## Limpezas de produção (não-bloqueantes)

### [E26 v1] Template Skills Hub renderiza diferente do Word original
- A v1 do `public/assets/report-templates/skill_hub/template.html` foi gerada via Word "Save as Web Page Filtered" e usa marcação MSO-específica (`<!--[if gte mso 9]>`, VML, fontes Calibri/Arial sem fallback no dompdf, tabelas sem larguras explícitas).
- **Sintoma**: layout do PDF gerado fica visualmente diferente do PDF que o Word exporta diretamente. Esperado dada a estratégia de migração — `Save as HTML Filtered` é o caminho de menor esforço pra sair do Word, não o de melhor render no dompdf.
- **Impacto:** alto pra UX do PO (que esperava fidelidade visual ao Word).
- **Ação:** **E30 (F21)** — refazer o template em HTML/CSS limpo, fiel ao `template_reports/CU_SKILL_HUB_PDF.pdf`. Se mesmo após reescrever houver gaps, considerar swap dompdf→mPDF. Backfill das submissions já corrigidas executando `ReportService::generate` em loop.
- **Mitigação enquanto E30 não chega:** pipeline (trigger, storage, endpoint, segurança) está OK e funciona com qualquer template — quando E30 substituir o template.html + rodar backfill, todos os PDFs antigos são regerados sem nova migração de schema.

### ~~[E26-04] Bootstrap Icons CSS não carrega fora do student area~~ ✅ DESCARTADA (2026-04-27)
- PO decidiu que o impacto é baixo demais pra justificar mudança. Pode ficar como está.

### ~~[E25-02] Re-cadastro de LOs apaga notas existentes via FK CASCADE~~ ✅ RESOLVIDA (v0.29.0)

### [E25-05] UC sem 5 LOs cadastrados em curso LO mode — aluno fica sem orientação
- Quando o curso virou LO depois de criar UCs, e o professor ainda não cadastrou os 5 LOs em alguma CU, o aluno em `/student/evaluation/{id}` daquela CU **não vê o card "Critérios avaliados"** (defesa silenciosa: só renderiza com `loList !== []`).
- **Impacto:** baixo — feedback do professor já está bloqueado nesse cenário (E25-03 mostra alerta clicável pro cadastro), então o aluno fica sem orientação só durante a janela de transição.
- **Ação (se virar problema):** mostrar mensagem alternativa pro aluno ("Os critérios desta avaliação estão sendo definidos pelo professor"). Sobre-engineering pro MVP.

### ~~[E24-03] Logos órfãs em `public/uploads/logos/`~~ ✅ RESOLVIDA (v0.29.0)

---

## Pendências externas (bloqueiam stories específicas)

### Judge0 RapidAPI
- Plano **gratuito** (ADR-029). Credenciais em `config/env.php` (gitignored).
- **Bloqueia:** E8 (compilador online).

### FTPS Hostinger cPanel
- Credenciais e configurações em `config/env.php` (gitignored). Ver `.env.example` para referência.
- **Bloqueia:** E13-03 (script de deploy incremental).

### cPanel — seleção de versão PHP
- **Status (2026-04-27):** Hostinger subiu o domínio pra **PHP 8.3** automaticamente em 2026-04-22. Validado por uso real (todo o roadmap rodou em 8.3 sem incidente). CLAUDE.md atualizado pra refletir 8.3.
- **Ação na primeira configuração de domínio novo:** travar em PHP **8.3** no MultiPHP Manager. Não deixar no "latest" (pode pular versões sem aviso).

### cPanel — document root
- **Ação na primeira configuração:** apontar o document root do domínio para `/public_html/public/` (e não `/public_html/`). O front controller e o `.htaccess` estão em `public/`.

---

## Inconsistências menores de documentação para alinhar

### ~~[E0-03] Skill `/code-review` cita `MySQL::pdo()` em vez de `Database::pdo()`~~ ✅ RESOLVIDA (v0.29.0)

### [E0-04] Divergência AC "toast" vs. implementação "alert"
- A issue #17 fala em "toast Bootstrap"; a implementação usa `alert` do Bootstrap.
- **Justificativa:** alerts são melhores para PRG (mensagens pós-redirect persistem até dismiss; não auto-fecham como toasts). Toasts combinam mais com notificações ephemeral dirigidas por JS client-side.
- **Ação:** se em uso o PO quiser toasts de verdade, trocar em uma story futura.

---

## Composer / dependências a trazer

### HTMLPurifier
- Ainda não entrou (`composer.json` já existe e trava `"php": "^8.2"` + PHPMailer 6.x desde E10-03).
- **Quando adicionar:** E5, para sanitizar o HTML rico do TinyMCE antes de persistir em `contents.body_html`.

---

## Resolvidas

- **2026-04-23 — Bug: `countDescendants` quebra com `PDO::ATTR_EMULATE_PREPARES = false`.** Descoberto no smoke test do Epic E3 (PDOException `SQLSTATE[HY093] Invalid parameter number` ao abrir `/teacher/courses/{id}`). Causa: `Course`, `CoreCompetency` e `CompetenceUnit` reusavam o placeholder nomeado `:id` várias vezes na mesma query; com emulação desligada em produção (MariaDB 10.11) cada ocorrência conta como slot separado e `execute()` com um único valor para `:id` dispara o erro. Fix: trocar para placeholders posicionais `?` e passar o mesmo valor múltiplas vezes. Revisão: `TeacherAdmin::findById` e `Course::findForTenant` usam `:id` só uma vez — OK.
- **2026-04-23 — Bug crítico: `tenant_id` do professor nunca era resolvido na sessão.** Descoberto no smoke test do Epic E3 em produção: criar curso / qualquer página `/teacher/*` que usa `current_tenant_id()` caía em 403. Causa: `users.tenant_id IS NULL` para teachers (CHECK constraint `chk_users_role_tenant`); o elo real é `tenants.owner_user_id = users.id` (ADR-025), mas `AuthController::authenticate()` lia `tenant_id` direto de `users`, gravando NULL na sessão. Fix em `src/controllers/AuthController.php`: SELECT agora faz `LEFT JOIN tenants t ON t.owner_user_id = u.id AND t.active = 1` + `COALESCE(t.id, u.tenant_id) AS tenant_id`. Preserva student (vem de `u.tenant_id`) e super_admin (permanece NULL). Sessões anteriores precisam de logout/login para repopular.
- **2026-04-22 — [E10-03] PHPMailer + SMTP real.** `composer.json` criado (`php ^8.2` + `phpmailer/phpmailer ^6.9`), `bootstrap.php` passa a incluir `vendor/autoload.php`, `Mailer::send()` usa PHPMailer quando `SMTP_HOST/USER/PASS/FROM` estão preenchidos em `config/env.php` e mantém o fallback em `storage/logs/mail-debug.log` caso contrário. Falhas de SMTP vão para `storage/logs/mail.log` (não relançam). `Mailer::isConfigured()` passa a ler o env. Interface pública intocada — callers de E1-03/E2-02/E2-07 seguem iguais. Credenciais de produção em `config/env.php` (gitignored). Item "[E1-03] Integração PHPMailer quando E10 chegar" e "SMTP Hostgator" (pendência externa) saem da lista.
- **2026-04-22 — Pendência externa "SMTP Hostgator" reclassificada.** O domínio `lms.rumo.info` oferece SMTP próprio (cPanel/Hostinger) — não é mais Hostgator. Credenciais já em `config/env.php` (gitignored).
- **2026-04-27 — [E25-02] Re-cadastro de LOs apaga notas existentes via FK CASCADE (v0.29.0).** Adicionado `LearningOutcome::countGradesByCu(int $cuId): int` (JOIN learning_outcomes × evaluation_submission_lo_grades). Page handler `src/pages/teacher/cu/learning-outcomes.php` exibe alerta amarelo + checkbox `confirm_drop_grades` quando `gradesCount > 0`; submit sem confirmação devolve erro `learning_outcomes.err.confirm_required`. 4 chaves i18n novas em PT/EN.
- **2026-04-27 — [E24-03] Logos órfãs em `public/uploads/logos/` (v0.29.0).** Adicionado `Tenant::clearLogo(int $tenantId): void` (zera só `logo_path`, mantém `platform_name`). `AdminTeachersController::update` agora detecta transição não-Actvet → Actvet e, se há `logo_path` setada, chama `clearLogo` dentro da tx + `LogoStorage::deleteByBasename` após o commit (best-effort). Cobre o caso comum; logos órfãs já existentes em prod requerem cleanup manual via FileZilla (raras).
- **2026-04-27 — [E0-03] Skill `/code-review` cita `MySQL::pdo()` em vez de `Database::pdo()` (v0.29.0).** Trocado em `.claude/skills/code-review.md`.
- **2026-04-27 — Hostinger subiu o domínio para PHP 8.3 (v0.29.0).** Auto-upgrade da Hostinger em 2026-04-22; validado por uso real durante todo o roadmap pós-MVP estendido (E15-E30). CLAUDE.md atualizado pra refletir 8.3 (features 8.3 OK, ainda proibir 8.4).
