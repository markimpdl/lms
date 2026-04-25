<?php
declare(strict_types=1);

/**
 * Modelo de `notification_settings` (E21-01). Config por tenant da matriz
 * evento × canal usada pelo NotificationService::fanout (E21-02).
 *
 * Linha ausente da tabela = enabled (default ON). Save sempre persiste
 * 16 rows (8 eventos × 2 canais) pra evitar ambiguidade entre "não
 * configurado" e "desligado intencional".
 */
final class NotificationSetting
{
    /**
     * Mapa `[event => [bell => bool, email => bool]]` das configs do tenant.
     * Eventos sem row são preenchidos com default ON. Eventos fora da
     * whitelist `NotificationService::EVENTS` são ignorados.
     *
     * @return array<string, array{bell: bool, email: bool}>
     */
    public static function getAllForTenant(int $tenantId): array
    {
        $result = [];
        foreach (NotificationService::EVENTS as $event) {
            $result[$event] = ['bell' => true, 'email' => true];
        }

        if ($tenantId <= 0) {
            return $result;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT event, channel, enabled
               FROM notification_settings
              WHERE tenant_id = ?'
        );
        $stmt->execute([$tenantId]);

        foreach ($stmt->fetchAll() as $row) {
            $event   = (string) $row['event'];
            $channel = (string) $row['channel'];
            if (isset($result[$event]) && in_array($channel, NotificationService::CHANNELS, true)) {
                $result[$event][$channel] = ((int) $row['enabled']) === 1;
            }
        }
        return $result;
    }

    /**
     * Bulk upsert via REPLACE INTO. `$payload = [event => [bell => bool,
     * email => bool]]`. Eventos fora de `NotificationService::EVENTS` são
     * ignorados (defesa contra POST manual com keys arbitrárias).
     *
     * REPLACE atualiza `updated_at` (DEFAULT ON UPDATE CURRENT_TIMESTAMP) —
     * comportamento desejado: rastrear "última vez que o professor salvou".
     *
     * @param array<string, array{bell?: bool, email?: bool}> $payload
     */
    public static function saveBulk(int $tenantId, array $payload): void
    {
        if ($tenantId <= 0 || $payload === []) {
            return;
        }

        $rows   = [];
        $params = [];

        foreach (NotificationService::EVENTS as $event) {
            if (!isset($payload[$event])) {
                continue;
            }
            foreach (NotificationService::CHANNELS as $channel) {
                $enabled  = !empty($payload[$event][$channel]);
                $rows[]   = '(?, ?, ?, ?)';
                $params[] = $tenantId;
                $params[] = $event;
                $params[] = $channel;
                $params[] = $enabled ? 1 : 0;
            }
        }

        if ($rows === []) {
            return;
        }

        $sql = 'REPLACE INTO notification_settings (tenant_id, event, channel, enabled) VALUES '
             . implode(', ', $rows);
        Database::pdo()->prepare($sql)->execute($params);
    }
}
