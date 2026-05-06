<?php
declare(strict_types=1);

/**
 * Sessoes de tempo online do aluno (TIME-01, issue #436).
 *
 * Cada sessao representa uma janela contigua em que o aluno esteve com a
 * plataforma aberta e visivel. O cliente JS gera um UUID v4
 * (crypto.randomUUID) e bate em /api/student/heartbeat a cada 60s enquanto
 * document.visibilityState === 'visible'. O backend cria a sessao no 1o
 * ping e atualiza last_ping_at nos seguintes. Se o gap entre pings passar
 * de 30 min, o UUID e considerado obsoleto e o cliente abre nova sessao.
 *
 * Sessao "ativa" = ended_at IS NULL. O fechamento acontece em 3 caminhos:
 *   1) /api/student/session-end (sendBeacon no beforeunload da aba lider)
 *   2) cron close-stale-sessions (TIME-03) — fecha onde last_ping_at > 3min
 *   3) implicito quando o aluno volta apos > 30min — backend rejeita o
 *      ping antigo, cron fecha depois.
 *
 * Multi-tab: o JS usa BroadcastChannel pra eleger UMA aba lider; backend
 * apenas armazena o que chegar (idempotente por UUID).
 */
final class StudentSession
{
    /** Janela maxima entre pings sem considerar a sessao obsoleta (em minutos). */
    public const STALE_GAP_MINUTES = 30;

    /** Limite default usado pelo cron pra fechar sessoes orfas (em minutos). */
    public const CRON_CLOSE_AFTER_MINUTES = 3;

    /**
     * Registra um ping. Se UUID e novo, cria a sessao; se ja existe e esta
     * dentro da janela de 30 min, atualiza last_ping_at. Retorna o status
     * da operacao (acoes posteriores do cliente dependem disso).
     *
     * Status possiveis:
     *   - 'created'         — nova sessao iniciada
     *   - 'updated'         — last_ping_at atualizado
     *   - 'rejected_stale'  — UUID existe mas gap > 30 min; cliente deve
     *                         gerar novo UUID e re-tentar
     *   - 'rejected_closed' — sessao ja foi fechada (ended_at != NULL)
     *   - 'rejected_owner'  — UUID pertence a outro user (UUID v4 colidiu
     *                         ou cliente mandou UUID alheio)
     */
    public static function recordPing(
        string $uuid,
        int $userId,
        int $tenantId,
        string $ip,
        ?string $userAgent
    ): string {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'SELECT user_id, ended_at,
                    (last_ping_at < NOW() - INTERVAL ' . self::STALE_GAP_MINUTES . ' MINUTE) AS is_stale
               FROM student_sessions
              WHERE session_uuid = ?
              LIMIT 1'
        );
        $stmt->execute([$uuid]);
        $existing = $stmt->fetch();

        if ($existing === false) {
            $ins = $pdo->prepare(
                'INSERT INTO student_sessions
                    (tenant_id, user_id, session_uuid, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $tenantId,
                $userId,
                $uuid,
                $ip,
                $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
            ]);
            return 'created';
        }

        if ((int) $existing['user_id'] !== $userId) {
            return 'rejected_owner';
        }
        if ($existing['ended_at'] !== null) {
            return 'rejected_closed';
        }
        if ((int) $existing['is_stale'] === 1) {
            return 'rejected_stale';
        }

        $upd = $pdo->prepare(
            'UPDATE student_sessions
                SET last_ping_at = NOW()
              WHERE session_uuid = ?'
        );
        $upd->execute([$uuid]);
        return 'updated';
    }

    /**
     * Fecha explicitamente a sessao (sendBeacon do beforeunload). Idempotente:
     * sessao ja fechada retorna false sem erro. Valida ownership por user_id
     * pra impedir que aluno A feche sessao do aluno B (UUID v4 e basicamente
     * unguessable, mas a defesa nao custa).
     */
    public static function endSession(string $uuid, int $userId): bool
    {
        $upd = Database::pdo()->prepare(
            'UPDATE student_sessions
                SET ended_at = NOW(),
                    duration_seconds = TIMESTAMPDIFF(SECOND, started_at, NOW())
              WHERE session_uuid = ?
                AND user_id = ?
                AND ended_at IS NULL'
        );
        $upd->execute([$uuid, $userId]);
        return $upd->rowCount() > 0;
    }

    /**
     * Fecha sessoes ativas cujo ultimo ping foi ha mais de $minutes.
     * Usa last_ping_at como ended_at (presume que o aluno saiu nesse momento).
     * Idempotente: rodar 2x no mesmo minuto so fecha o que sobrou. Retorna o
     * numero de sessoes fechadas. Indexado por (ended_at, last_ping_at).
     */
    public static function closeStaleSessions(int $minutes = self::CRON_CLOSE_AFTER_MINUTES): int
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE student_sessions
                SET ended_at = last_ping_at,
                    duration_seconds = TIMESTAMPDIFF(SECOND, started_at, last_ping_at)
              WHERE ended_at IS NULL
                AND last_ping_at < (NOW() - INTERVAL ? MINUTE)'
        );
        $stmt->bindValue(1, max(1, $minutes), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }
}
