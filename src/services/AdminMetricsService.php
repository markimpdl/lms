<?php
declare(strict_types=1);

/**
 * Métricas agregadas globais para o painel do super-admin (E2-06).
 *
 * Cada valor sai de uma query separada — volume baixo, SQL mais legível e
 * fácil de cachear. Zero detalhamento por tenant/aluno/curso: apenas
 * contagens e totais (ADR-030 — sem audit log).
 *
 * Email metrics ficam `null` até E10 materializar a tabela `email_failures`;
 * a tela renderiza "—" nesse caso.
 */
final class AdminMetricsService
{
    public const DAILY_WINDOW_DAYS = 30;

    /**
     * @return array{
     *   teachers_active:   int,
     *   teachers_inactive: int,
     *   students_active:   int,
     *   courses_active:    int,
     *   submissions_30d:   int,
     *   last_login_at:     ?string,
     *   emails_sent_30d:   ?int,
     *   emails_failed_30d: ?int,
     *   daily:             list<array{date: string, count: int}>,
     * }
     */
    public static function snapshot(): array
    {
        $pdo = Database::pdo();

        return [
            'teachers_active'   => self::count($pdo, "SELECT COUNT(*) FROM users WHERE role = 'teacher' AND active = 1"),
            'teachers_inactive' => self::count($pdo, "SELECT COUNT(*) FROM users WHERE role = 'teacher' AND active = 0"),
            'students_active'   => self::count($pdo, "SELECT COUNT(*) FROM users WHERE role = 'student' AND active = 1"),
            'courses_active'    => self::count($pdo, 'SELECT COUNT(*) FROM courses WHERE archived = 0'),
            'submissions_30d'   => self::submissionsLastDays($pdo, self::DAILY_WINDOW_DAYS),
            'last_login_at'     => self::scalarOrNull($pdo, 'SELECT MAX(last_login_at) FROM users'),
            'emails_sent_30d'   => null, // dep. E10 (tabela `email_failures`)
            'emails_failed_30d' => null,
            'daily'             => self::submissionsDaily($pdo, self::DAILY_WINDOW_DAYS),
        ];
    }

    private static function count(PDO $pdo, string $sql): int
    {
        return (int) $pdo->query($sql)->fetchColumn();
    }

    private static function scalarOrNull(PDO $pdo, string $sql): ?string
    {
        $v = $pdo->query($sql)->fetchColumn();
        return $v === false || $v === null ? null : (string) $v;
    }

    private static function submissionsLastDays(PDO $pdo, int $days): int
    {
        $a = self::count(
            $pdo,
            'SELECT COUNT(*) FROM activity_submissions WHERE created_at >= (NOW() - INTERVAL ' . $days . ' DAY)'
        );
        $e = self::count(
            $pdo,
            'SELECT COUNT(*) FROM evaluation_submissions WHERE created_at >= (NOW() - INTERVAL ' . $days . ' DAY)'
        );
        return $a + $e;
    }

    /**
     * Série diária dos últimos $days dias, fechada à esquerda e à direita
     * (inclui o dia atual). Dias sem submissões aparecem com `count = 0`
     * — a tela precisa deles para renderizar o gráfico com escala estável.
     *
     * @return list<array{date: string, count: int}>
     */
    private static function submissionsDaily(PDO $pdo, int $days): array
    {
        $sql = <<<SQL
            SELECT d, COALESCE(SUM(n), 0) AS n FROM (
                SELECT DATE(created_at) AS d, COUNT(*) AS n
                  FROM activity_submissions
                 WHERE created_at >= (NOW() - INTERVAL :days DAY)
                 GROUP BY DATE(created_at)
                UNION ALL
                SELECT DATE(created_at) AS d, COUNT(*) AS n
                  FROM evaluation_submissions
                 WHERE created_at >= (NOW() - INTERVAL :days2 DAY)
                 GROUP BY DATE(created_at)
            ) u
            GROUP BY d
            SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':days',  $days, PDO::PARAM_INT);
        $stmt->bindValue(':days2', $days, PDO::PARAM_INT);
        $stmt->execute();
        $byDate = [];
        foreach ($stmt->fetchAll() as $row) {
            $byDate[(string) $row['d']] = (int) $row['n'];
        }

        // Preenche os $days dias, do mais antigo ao de hoje (ordem estável).
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime('-' . $i . ' days'));
            $out[] = ['date' => $date, 'count' => $byDate[$date] ?? 0];
        }
        return $out;
    }
}
