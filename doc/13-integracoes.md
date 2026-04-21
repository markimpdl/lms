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

**Política de allowlist:** o HTML sanitizado só permite iframes cuja origem seja `youtube.com/embed` ou `player.vimeo.com`. Qualquer outro iframe é removido.

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
