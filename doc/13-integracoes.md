# 13 — Integrações

## SMTP (envio de email)

- **Provedor:** **SMTP da Hostgator** (credenciais serão fornecidas pelo professor durante o desenvolvimento).
- **Credenciais:** guardadas em `config/env.php` (fora do repositório público), com suporte a variáveis de ambiente. Nunca versionadas.
- **Biblioteca PHP:** **PHPMailer**.
- **Fallback:** se SMTP falhar, registrar em `email_failures` e ainda criar a notificação in-app.

## Judge0 (execução de código)

- **Provedor inicial:** Judge0 CE via RapidAPI (plano gratuito permite testes iniciais).
- **Chave:** no servidor, nunca no cliente.
- **Endpoints utilizados:**
  - `POST /submissions` — enviar código para execução.
  - `GET /submissions/{token}` — consultar resultado.
- **Linguagens mapeadas:** Python 3, C# (Mono/.NET), JavaScript (Node).
- **Limites aplicados no backend:**
  - Código máximo: 64 KB.
  - Rate limit: **30 execuções/aluno/min**, **3 simultâneas**, cap diário **200/aluno**.
  - Timeout: 5 s (padrão Judge0).

## Embeds de vídeo

### YouTube
- Regex: aceitar URLs `https://www.youtube.com/watch?v=*`, `https://youtu.be/*`, `https://www.youtube.com/embed/*`.
- Converter para `<iframe src="https://www.youtube.com/embed/<ID>" ...>`.
- Atributos: `allowfullscreen`, `loading="lazy"`, `title`.

### Vimeo
- Regex: `https://vimeo.com/<ID>`.
- Converter para `<iframe src="https://player.vimeo.com/video/<ID>" ...>`.
- Mesmos atributos responsivos.

### OnlineGDB (compilador C++ embutido)
Colado no botão **Mídia** do editor. Dois modos, decididos pelo que o professor cola:

| O professor cola | Vira | Aluno |
| --- | --- | --- |
| Link do **Share** (`https://onlinegdb.com/<ID>`) ou `onlinegdb.com/fork/<ID>` | `<iframe src="https://www.onlinegdb.com/fork/<ID>" class="content-ide">`, 600px | **Edita** e roda o código do professor, com console interativo (`cin`) |
| Código de embed (`<script src="//onlinegdb.com/embed/js/<ID>?theme=...">`) ou `/embed/<ID>` | `<iframe src="https://www.onlinegdb.com/embed/<ID>[?theme=...]" class="content-code">`, 520px | **Só leitura**: o embed oficial trava o editor (`readOnly: true`) e só tem Run |

- O script oficial do embed só injeta o iframe; geramos direto porque `<script>` não passa no sanitizador.
- Altura fixa em vez de 16:9: o script oficial ajusta a altura via `postMessage`, o que não temos sem script.
- O modo editável é a IDE inteira do OnlineGDB (barra lateral, Login/Sign Up). O que o aluno digita some
  ao recarregar a página, a menos que ele entre no OnlineGDB e salve lá.
- Link do Share tipo "cópia" pode expirar: para lição, usar o link permanente.

**Política de allowlist:** o HTML sanitizado só permite iframes cuja origem seja `youtube.com/embed`, `player.vimeo.com` ou `www.onlinegdb.com/embed` / `www.onlinegdb.com/fork`. Qualquer outro iframe é removido.

## Uploads

- Armazenamento local no servidor da Hostinger (disco).
- Estrutura: `storage/uploads/tenant_<id>/{content|activity|evaluation}/<id>/<filename>`.
- Pasta `storage/` preferencialmente fora da raiz web; se impossível no cPanel, proteger com `.htaccess` negando acesso direto.
- Download sempre via script PHP que valida sessão, tenant e matrícula.

## Cron / agendadores

Usando o **Cron Jobs** do cPanel:

| Job | Frequência | Função |
|-----|------------|--------|
| Digest diário ao professor | 20:00 local | Consolidar submissões do dia e enviar email. |
| Limpeza de notificações antigas | semanal | Remover notificações lidas com mais de 90 dias. |
| Limpeza de falhas de email | mensal | Purgar `email_failures` antigos. |

Cron chama endpoints PHP CLI (`php cron/run.php <job>`).

## Autenticação

- Login por **email + senha** apenas nesta fase.
- Sem Google/Microsoft SSO no MVP.
- Recuperação de senha: email com token de uso único (válido 1 hora).

## Observabilidade externa (opcional)

- Integração futura com Sentry ou similar para captura de exceções PHP. **Fora do MVP.**
