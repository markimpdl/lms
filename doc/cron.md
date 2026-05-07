# Crons do LMS

Tabela dos jobs agendados no cPanel da Hostinger. Cada script é
self-contained (carrega `src/bootstrap.php`, lê `config/env.php`).

| Script                                       | Frequência | O que faz                                                                                       |
| -------------------------------------------- | ---------- | ----------------------------------------------------------------------------------------------- |
| `scripts/cron/purge-old-logins.php`          | Diário 03:00 UTC | Apaga `user_logins` com mais de 180 dias (E16-04 / F10).                                  |
| `scripts/cron/close-stale-sessions.php`      | A cada 1 min     | Fecha `student_sessions` cujo `last_ping_at > 3 min` ago — usa `last_ping_at` como `ended_at` e popula `duration_seconds` (TIME-03 / #438). |

## Setup no cPanel

Cada job precisa ser cadastrado manualmente em **cPanel → Advanced → Cron
Jobs** apontando pro PHP CLI da Hostinger:

```
php /home/USER/PUBLIC_HTML/scripts/cron/<script>.php
```

Saída pode ir pra `/dev/null` ou pra um log dedicado. Os scripts já
imprimem 1 linha de resumo em stdout e código de saída 0/1 — ideal para
encadear com `>> storage/logs/cron-NAME.log 2>&1` se quiser histórico.

## Convenções

- Sempre `require dirname(__DIR__, 2) . '/src/bootstrap.php';` no topo
- Idempotente — rodar 2× no mesmo minuto não pode causar erro nem duplicar trabalho
- Erros graves vão pra `STDERR` + `exit(1)`; sucesso imprime resumo + `exit(0)`
- Nunca acopla a `$_SESSION` (CLI não tem sessão)
