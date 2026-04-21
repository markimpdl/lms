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
