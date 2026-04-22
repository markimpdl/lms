# 99 — Pendências técnicas

Lista de itens que ficaram anotados ao longo do desenvolvimento e que precisam ser verificados/executados antes de cada milestone. Atualize conforme itens forem sendo resolvidos (marque `✅` e mova para `Resolvidas` no fim).

Para decisões arquiteturais já tomadas, ver `14-decisoes-e-pendencias.md` (ADRs).

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

### [E1-03] Integração PHPMailer quando E10 chegar
- `src/lib/Mailer.php` hoje só escreve em `storage/logs/mail-debug.log`.
- **Ação em E10-03:** adicionar composer, PHPMailer, e trocar o corpo de `Mailer::send()` para enviar via SMTP Hostgator. Interface pública fica igual — nenhum caller precisa mudar.

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

### [E1-04] Bug menor no helper `current_lang()`
- Em `src/helpers.php:43`, fallback lê `current_user()['lang']`, mas o payload de sessão em `AuthController::completeLogin()` grava `'language'`. Na prática a condição nunca dispara — só `$_SESSION['lang']` (do `?lang=` ou da atualização de perfil) é que funciona.
- **Impacto:** usuário com `users.language='en'` no banco vê a UI em PT até clicar `?lang=en` pela primeira vez ou atualizar o perfil.
- **Ação:** corrigir a chave no helper (de `'lang'` para `'language'`) em uma story futura — fora do escopo de E1-04.

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
- **Placeholder E2-04 remanescente:** botão "Desativar/Reativar" segue `disabled` — E2-03 já ativou o "Abrir".

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

### [E0-04] Seletor de idioma preserva query string atual
- Links atuais são `?lang=pt` / `?lang=en` — se a URL for `/courses/5?tab=overview`, trocar idioma joga fora o `tab`.
- **Ação (follow-up):** substituir por um helper `lang_url('pt')` que chama `http_build_query(array_merge($_GET, ['lang' => 'pt']))`. Fazer junto da primeira página que tiver query string real (E3 ou E4).

### [E0-04] Divergência "toast" no AC vs "alert" implementado
- A issue #17 fala em "toast Bootstrap"; a implementação usa `alert-dismissible`.
- **Justificativa:** alerts são melhores para o fluxo PRG (persistem até dismiss; não auto-fecham). Toasts combinam mais com notificações ephemeral dirigidas por JS client-side.
- **Ação:** se preferir toast de verdade, trocar em follow-up (ex.: após login poderia ser toast, após erro de validação fica alert).

---

## Pendências externas (bloqueiam stories específicas)

### Judge0 RapidAPI
- Plano **gratuito** (ADR-029). Precisa de `JUDGE0_HOST` e `JUDGE0_KEY` em `config/env.php`.
- **Bloqueia:** E8 (compilador online).

### SMTP Hostgator
- Remetente fixo `naoresponda@<dominio>`; sem per-tenant no MVP.
- **Bloqueia:** envio real em E10. Antes disso, stub loga em `storage/logs/mail-debug.log`.

### FTPS Hostinger cPanel
- Credenciais necessárias: `FTP_HOST`, `FTP_USER`, `FTP_PASSWORD`, `FTP_REMOTE_ROOT=/public_html`, `FTP_SECURE=true`, `FTP_ALLOW_SELF_SIGNED=true`.
- **Bloqueia:** E13-03 (script de deploy incremental).

### cPanel — seleção de versão PHP
- **Ação na primeira configuração:** garantir que o domínio está travado em **PHP 8.2** no MultiPHP Manager. Não deixar no "latest" (pode pular versões sem aviso).

### cPanel — document root
- **Ação na primeira configuração:** apontar o document root do domínio para `/public_html/public/` (e não `/public_html/`). O front controller e o `.htaccess` estão em `public/`.

---

## Inconsistências menores de documentação para alinhar

### [E0-03] Skill `/code-review` cita `MySQL::pdo()` em vez de `Database::pdo()`
- Arquivo: `.claude/skills/code-review.md`, linha que diz "PDO via `MySQL::pdo()` singleton".
- **Ação:** trocar para `Database::pdo()` em uma das próximas oportunidades (não urgente — o skill não é auto-invocado).

### [E0-04] Divergência AC "toast" vs. implementação "alert"
- A issue #17 fala em "toast Bootstrap"; a implementação usa `alert` do Bootstrap.
- **Justificativa:** alerts são melhores para PRG (mensagens pós-redirect persistem até dismiss; não auto-fecham como toasts). Toasts combinam mais com notificações ephemeral dirigidas por JS client-side.
- **Ação:** se em uso o PO quiser toasts de verdade, trocar em uma story futura.

---

## Composer / dependências a trazer

### `composer.json` com PHPMailer + HTMLPurifier
- Ainda não existe `composer.json`.
- **Quando criar:** na primeira story que precisar de biblioteca externa (E1-05 para password reset email, ou E5 para TinyMCE purifier, o que vier primeiro).
- **Lembrar:** travar `"require": {"php": "^8.2"}` para barrar quem tenta rodar em <8.2.

---

## Resolvidas

_(Mova itens para cá com a data quando for concluída.)_
