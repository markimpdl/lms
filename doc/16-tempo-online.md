# Tempo online dos alunos

Mede há quanto tempo cada aluno fica conectado e ativo na plataforma.
Histórias **TIME-01 a TIME-05** (épico [#435](https://github.com/markimpdl/lms/issues/435)).

## Modelo

Tabela `student_sessions` (criada em TIME-01):

| Coluna             | Tipo                | Significado                                                        |
| ------------------ | ------------------- | ------------------------------------------------------------------ |
| `session_uuid`     | `CHAR(36)` UNIQUE   | UUID v4 gerado client-side (`crypto.randomUUID`)                   |
| `tenant_id`        | FK `tenants`        | Tenant do aluno                                                    |
| `user_id`          | FK `users`          | Aluno dono da sessão                                               |
| `started_at`       | `DATETIME`          | 1º ping recebido                                                   |
| `last_ping_at`     | `DATETIME`          | Ping mais recente (atualizado pelo heartbeat)                      |
| `ended_at`         | `DATETIME` NULL     | Quando a sessão fechou (sendBeacon ou cron)                        |
| `duration_seconds` | `INT UNSIGNED` NULL | `TIMESTAMPDIFF(SECOND, started_at, ended_at)`                      |
| `ip_address`       | `VARCHAR(45)`       | IP do 1º ping                                                      |
| `user_agent`       | `VARCHAR(255)`      | UA do 1º ping                                                      |

Sessão **ativa** = `ended_at IS NULL`.

## Fluxo

```
+--------+    POST /api/student/heartbeat   +---------+    UPDATE last_ping_at
|  JS    | -------------------------------> | endpoint| ----------------------> MySQL
| (líder)|        a cada 60s, visible       +---------+
+--------+
     |
     | beforeunload
     v
+----------------+    sendBeacon /api/student/session-end
| Beacon final   | ---------------------------------------> ended_at = NOW()
+----------------+

+----------------+    cron a cada 1 min                     ended_at = last_ping_at
| close-stale-   | -----------------------------------> WHERE last_ping_at < NOW() - 3min
| sessions.php   |
+----------------+
```

### 1. Heartbeat (TIME-01 + TIME-02)

- JS em `public/assets/js/student-heartbeat.js`, carregado pelo
  `src/templates/layout.php` quando `$isStudentArea` é true.
- `POST /api/student/heartbeat` a cada **60s** se `document.visibilityState === 'visible'`.
- 1º ping com UUID novo → backend cria sessão (status `created`).
- Ping subsequente → `last_ping_at = NOW()` (status `updated`).
- Gap > **30 min** → backend rejeita (`rejected_stale`); cliente regenera UUID e
  o próximo tick abre nova sessão.

### 2. Multi-tab (TIME-02)

Sem coordenação, 3 abas abertas triplicariam o tempo. Estratégia:

- Cada aba tem `tabId` próprio em `sessionStorage`.
- Eleição por **lock TTL** em `localStorage`:
  ```
  lms_leader = { tabId: '...', expiresAt: now + 3000 }
  ```
- Líder renova o lock a cada 1s (tick).
- Aba **hidden** **cede a liderança** — outra aba visível assume.
- Líder único envia o POST; outras só ficam escutando.
- `BroadcastChannel('lms_session')` propaga sinal `leader-down` pra acelerar
  reeleição quando uma aba fecha (não substitui o lock; só adianta).
- `lms_last_heartbeat_at` em `localStorage` evita ping duplicado em handover.

### 3. Encerramento

3 caminhos, todos idempotentes:

1. **`navigator.sendBeacon`** no `beforeunload` da aba líder → fecha imediatamente
2. **Cron `close-stale-sessions.php`** (a cada 1 min) → fecha sessões com
   `last_ping_at > 3 min` ago, usando `last_ping_at` como `ended_at`
3. **Aluno volta após > 30 min** → backend rejeita o UUID antigo; o cron fecha
   posteriormente

## Métricas (TIME-04 — pendente)

A partir de `student_sessions`, calcular por aluno:
- **Último acesso**: `MAX(last_ping_at)`
- **Acessos**: `COUNT(*)` de sessões com `ended_at IS NOT NULL`
- **Tempo total**: `SUM(duration_seconds)` + (sessão ativa: `NOW() - started_at`)
- **Tempo médio**: `AVG(duration_seconds)`

Janela opcional via `WHERE started_at > NOW() - INTERVAL ? DAY`.

## Privacidade

- `ip_address` e `user_agent` salvos só do 1º ping de cada sessão.
- Visíveis APENAS pelo professor do tenant na página do aluno (TIME-05).
- Retenção infinita por enquanto — purge manual quando necessário (definido no
  épico #435; não há cron de retenção planejado).

## Limites e gotchas

| Caso                                | Tratamento                                                                  |
| ----------------------------------- | --------------------------------------------------------------------------- |
| Aluno deixa aba aberta sem usar     | `visibilityState !== 'visible'` pausa pings; cron fecha em 3 min            |
| Mobile vai pra background           | Igual acima — `visibilitychange → hidden` é disparado                       |
| Crash do browser                    | Sem `beforeunload`; cron fecha em 3 min usando `last_ping_at`               |
| Rede lenta / heartbeat falha        | Catch silencioso; próximo tick (60s) tenta de novo                          |
| 2 abas abertas, ambas em background | Lock expira; nenhum ping; cron fecha sessão                                 |
| Aluno em 2 dispositivos             | Cada device gera UUID próprio = 2 sessões paralelas (esperado)              |
| `crypto.randomUUID` indisponível    | Script aborta cedo (sem rastreio nesse browser; nada quebra na UI)          |
