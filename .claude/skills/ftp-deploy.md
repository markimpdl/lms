---
name: ftp-deploy
description: "Deploy incremental do LMS via FTPS para a Hostinger. Primeira vez é full; execuções seguintes enviam só o que mudou (hash SHA-256 contra state local). NÃO sobrescreve uploads de produção, .env ou .mcp.json."
version: "1.0.0"
---

# LMS — Deploy via FTP

> **Estado atual:** **PENDENTE de credenciais FTP da Hostinger.** O script `scripts/deploy/ftp-deploy.mjs` ainda não foi criado. Esta skill descreve o comportamento esperado quando o usuário fornecer:
> - `WEB_FTP_HOST`
> - `WEB_FTP_USER`
> - `WEB_FTP_PASS`
> - `WEB_FTP_BASE_PATH` (ex.: `/public_html` ou subdir)
> - `WEB_FTP_ALLOW_SELF_SIGNED` (true/false)
>
> Essas credenciais ficam em `.env.deploy` (gitignored). Quando o usuário fornecer, materializar o script.

## Quando o usuário invocar `/ftp-deploy`

1. **Verifique pré-requisitos**:
   - `.env.deploy` existe na raiz com as variáveis acima preenchidas
   - `node_modules/basic-ftp/` existe (rodar `npm install` se faltar)
   - Node 18+ disponível (`node --version`)
   - `scripts/deploy/ftp-deploy.mjs` existe

   Se faltar qualquer coisa, diga ao usuário o que precisa e pare.

2. **Rode o script** (modo padrão é incremental):

   ```bash
   node scripts/deploy/ftp-deploy.mjs
   ```

   Flags opcionais (só use se o usuário pedir):
   - `--dry-run` — lista o que seria enviado sem enviar
   - `--force` — ignora o state e reenvia tudo

3. **Interprete a saída**:
   - Primeira execução: muitos `↑ arquivo` e contagem grande
   - Execuções seguintes: poucos ou nenhum envio ("Nada a fazer")
   - Erros comuns:
     - `ECONNREFUSED` → host errado em `.env.deploy`
     - `530 Login incorrect` → user/senha errados
     - `self-signed certificate` → setar `WEB_FTP_ALLOW_SELF_SIGNED=true`
     - `550` em um arquivo → permissão remota; verificar se o subdomínio/document root aponta pra pasta certa
     - Timeout → checar bloqueio por IP/firewall na Hostinger

4. **Resumo ao usuário** em 2-3 linhas: quantos arquivos enviados, status, warnings.

## Política de exclusão (NUNCA enviar)

- `.env`, `.env.deploy`, `.env.example`, `config/env.php`
- `.mcp.json` (contém token GitHub)
- `.git/`, `node_modules/`, `vendor/` (composer install no servidor se necessário)
- `doc/`, `examples/`, `scripts/`, `.claude/`
- `package.json`, `package-lock.json`, `composer.json`, `composer.lock` (decidir caso a caso)
- `CLAUDE.md`, `README.md`, `*.md`
- `*.log`, `*.tmp`, `*.cache`
- `storage/uploads/` e `public/uploads/` (preservados no servidor — alunos enviaram lá)

## Pastas preservadas no servidor

Estas pastas **não são tocadas** pelo deploy — apenas `.htaccess`, `.gitignore` e `.gitkeep` são enviados (para criar a pasta vazia caso ainda não exista):

- `public/uploads/`
- `storage/uploads/`
- `storage/cache/`
- `storage/logs/`

## Migrations / mudanças de schema

Quando houver alteração em `install/schema.sql`:

1. O arquivo é enviado normalmente pelo deploy (está no repo)
2. **Aplicar manualmente no phpMyAdmin** — o script não roda SQL no servidor
3. Avise o usuário no resumo final se `install/schema.sql` foi enviado

## Outros pontos importantes

- **Sem rollback automático.** Se o deploy quebrou produção, restaurar o código via `git checkout <tag-anterior>` e rodar `/ftp-deploy --force`.
- **State local** em `scripts/deploy/.ftp-state.json` (gitignored). Trocou de máquina → próxima execução será tratada como primeira (upload completo). Nada é apagado no servidor.
- **Sem deleção remota.** Renomeou ou removeu arquivo no repo? O órfão fica no servidor. Remover manualmente via FTP se preciso.
- **Sem backup automático antes do deploy** (decisão ADR-013). Se quiser segurança extra, fazer dump SQL via phpMyAdmin antes.

## Materialização do script (quando credenciais chegarem)

Estrutura esperada:

```
scripts/deploy/
├── ftp-deploy.mjs         ← script principal (basic-ftp + walk + sha256)
├── exclude.json           ← lista de exclusões por path/regex
└── .ftp-state.json        ← gitignored, hash de cada arquivo enviado
```

Dependências (`package.json`):

```json
{
  "type": "module",
  "scripts": { "deploy": "node scripts/deploy/ftp-deploy.mjs" },
  "devDependencies": { "basic-ftp": "^5.0.5" }
}
```

Quando o usuário fornecer as credenciais, criar o script seguindo o padrão dos outros projetos dele (ver `examples/deploy-money-planner.md` ou `examples/deploy-versaoweb.md` para referência — mas adaptar exclusões e paths para o LMS).
