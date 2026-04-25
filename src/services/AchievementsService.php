<?php
declare(strict_types=1);

/**
 * Engine de conquistas (E18-02).
 *
 * Avalia desbloqueios baseado em eventos disparados pelos hooks (E18-04).
 * Encontra todas as conquistas que o aluno passou a satisfazer agora,
 * filtra as já desbloqueadas e faz INSERT IGNORE bulk em
 * `student_achievements` — idempotente.
 *
 * Spec: `doc/15-roadmap-pos-mvp.md` F5. Schema: E18-01 (#227).
 *
 * **Não dá XP** — conquistas são medalhas paralelas ao XP (ADR-002).
 *
 * Eventos suportados:
 *   - `activity_submitted`              → família activity_submitted_*
 *   - `evaluation_submitted`            → família evaluation_submitted_*
 *   - `evaluation_graded` (context.grade) → eval_grade_*_percent
 *   - `uc_completed` (context.max_grade?) → família uc_completed_* + uc_max_grade
 *   - `cc_completed` (context.max_grade?) → família cc_completed_* + cc_max_grade
 *   - `course_completed` (context.max_grade?) → família course_completed_* + course_max_grade
 *   - `notification_read`               → notification_read
 *   - `rank_first_promotion`            → rank_first_promotion
 *   - `course_started`                  → course_started
 *
 * `evaluateAll` defensivo + `availableForTenant` ficam em E18-03 (#229).
 * Hooks reais ficam em E18-04 (#230).
 */
final class AchievementsService
{
    /**
     * Avalia conquistas pra um evento específico. Retorna ids dos
     * unlocks novos (já desbloqueados são filtrados). Engole eventos
     * desconhecidos silenciosamente.
     *
     * @param array<string,mixed> $context
     * @return list<int>
     */
    public static function evaluateForEvent(int $studentId, int $tenantId, string $eventCode, array $context = []): array
    {
        if ($studentId <= 0 || $tenantId <= 0) {
            return [];
        }
        $candidates = self::candidatesForEvent($studentId, $tenantId, $eventCode, $context);
        return self::insertNewUnlocks($studentId, $tenantId, $candidates);
    }

    // ============================================================
    // Mapeamento evento → candidatos
    // ============================================================

    /**
     * Calcula os ids de conquistas potencialmente desbloqueáveis pelo evento.
     * Pode retornar ids já desbloqueados — `insertNewUnlocks` filtra.
     *
     * @param array<string,mixed> $context
     * @return list<int>
     */
    private static function candidatesForEvent(int $studentId, int $tenantId, string $eventCode, array $context): array
    {
        switch ($eventCode) {
            case 'activity_submitted':
                $count = self::countActivitySubmissions($studentId);
                return self::familyIdsUpToThreshold('activity_submitted', $count);

            case 'evaluation_submitted':
                $count = self::countEvaluationSubmissions($studentId);
                return self::familyIdsUpToThreshold('evaluation_submitted', $count);

            case 'evaluation_graded':
                $grade = (float) ($context['grade'] ?? 0); // 0..10
                $percent = (int) round($grade * 10);       // 0..100
                return self::evalPercentIds($percent);

            case 'uc_completed':
                $count = self::countCompletedUCs($studentId, $tenantId);
                $ids = self::familyIdsUpToThreshold('uc_completed', $count);
                if (!empty($context['max_grade'])) {
                    $ids = array_merge($ids, self::pontualIds(['uc_max_grade']));
                }
                return $ids;

            case 'cc_completed':
                $count = self::countCompletedCCs($studentId, $tenantId);
                $ids = self::familyIdsUpToThreshold('cc_completed', $count);
                if (!empty($context['max_grade'])) {
                    $ids = array_merge($ids, self::pontualIds(['cc_max_grade']));
                }
                return $ids;

            case 'course_completed':
                $count = self::countCompletedCourses($studentId, $tenantId);
                $ids = self::familyIdsUpToThreshold('course_completed', $count);
                if (!empty($context['max_grade'])) {
                    $ids = array_merge($ids, self::pontualIds(['course_max_grade']));
                }
                return $ids;

            case 'notification_read':
                return self::pontualIds(['notification_read']);

            case 'rank_first_promotion':
                return self::pontualIds(['rank_first_promotion']);

            case 'course_started':
                return self::pontualIds(['course_started']);

            default:
                return []; // evento desconhecido — no-op silencioso
        }
    }

    /**
     * Todas as conquistas de uma família com `threshold <= count`.
     *
     * @return list<int>
     */
    private static function familyIdsUpToThreshold(string $family, int $count): array
    {
        if ($count <= 0) {
            return [];
        }
        $stmt = Database::pdo()->prepare(
            'SELECT id FROM achievements
              WHERE family = ? AND threshold IS NOT NULL AND threshold <= ?'
        );
        $stmt->execute([$family, $count]);
        return array_map(static fn (array $r): int => (int) $r['id'], $stmt->fetchAll());
    }

    /**
     * Mapeia percentual (0..100) pros codes `eval_grade_*_percent` que
     * o aluno passou a satisfazer.
     *
     * @return list<int>
     */
    private static function evalPercentIds(int $percent): array
    {
        $codes = [];
        if ($percent >= 60)  { $codes[] = 'eval_grade_60_percent'; }
        if ($percent >= 80)  { $codes[] = 'eval_grade_80_percent'; }
        if ($percent >= 100) { $codes[] = 'eval_grade_100_percent'; }
        return $codes === [] ? [] : self::pontualIds($codes);
    }

    /**
     * Resolve uma lista de codes (ex.: pontuais ou avulsos) em ids.
     *
     * @param list<string> $codes
     * @return list<int>
     */
    private static function pontualIds(array $codes): array
    {
        if ($codes === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $stmt = Database::pdo()->prepare(
            "SELECT id FROM achievements WHERE code IN ($placeholders)"
        );
        $stmt->execute($codes);
        return array_map(static fn (array $r): int => (int) $r['id'], $stmt->fetchAll());
    }

    // ============================================================
    // INSERT IGNORE bulk com filtro de já-desbloqueados
    // ============================================================

    /**
     * Filtra `$candidates` removendo os já desbloqueados e faz INSERT IGNORE
     * dos restantes. Retorna ids efetivamente inseridos. Idempotente
     * (re-aplicar mesmo evento = no-op + retorno vazio).
     *
     * @param list<int> $candidates
     * @return list<int>
     */
    private static function insertNewUnlocks(int $studentId, int $tenantId, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }
        $candidates = array_values(array_unique($candidates));

        // Filtra já-desbloqueados
        $placeholders = implode(',', array_fill(0, count($candidates), '?'));
        $stmt = Database::pdo()->prepare(
            "SELECT achievement_id FROM student_achievements
              WHERE student_user_id = ? AND tenant_id = ?
                AND achievement_id IN ($placeholders)"
        );
        $stmt->execute(array_merge([$studentId, $tenantId], $candidates));
        $alreadyUnlocked = array_map(static fn (array $r): int => (int) $r['achievement_id'], $stmt->fetchAll());

        $toInsert = array_values(array_diff($candidates, $alreadyUnlocked));
        if ($toInsert === []) {
            return [];
        }

        // Bulk INSERT IGNORE — UK composta absorve race se 2 hooks dispararem em paralelo
        $rows   = [];
        $params = [];
        foreach ($toInsert as $aid) {
            $rows[]   = '(?, ?, ?)';
            $params[] = $studentId;
            $params[] = $tenantId;
            $params[] = $aid;
        }
        $sql = 'INSERT IGNORE INTO student_achievements
                    (student_user_id, tenant_id, achievement_id)
                VALUES ' . implode(', ', $rows);
        Database::pdo()->prepare($sql)->execute($params);

        return $toInsert;
    }

    // ============================================================
    // Helpers de count — estado atual do aluno por escopo
    // ============================================================

    /**
     * Aluno é exclusivo do tenant (ADR-026), então `student_user_id` já
     * implica tenant. Não filtra por tenant_id explícito (não existe nessa
     * tabela; redundante).
     */
    private static function countActivitySubmissions(int $studentId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM activity_submissions
              WHERE student_user_id = ?'
        );
        $stmt->execute([$studentId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Conta apenas submissions correntes (`is_current = 1`) — o aluno pode
     * ter múltiplas tentativas mas conceitualmente é "1 avaliação enviada".
     * `evaluation_submissions.tenant_id` existe (E7-00); usado como defesa
     * em profundidade.
     */
    private static function countEvaluationSubmissions(int $studentId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM evaluation_submissions
              WHERE student_user_id = ? AND is_current = 1'
        );
        $stmt->execute([$studentId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * UCs concluídas: itera sobre as UCs em cursos matriculados do aluno
     * e chama `StudentProgress::cuStatus`. Volume baixo no MVP (ex.: 30
     * UCs × 1 chamada = 30 queries por evaluateForEvent — aceitável).
     * Otimização possível: SQL único com fórmula inline (defer pra depois).
     */
    private static function countCompletedUCs(int $studentId, int $tenantId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT cu.id
               FROM competence_units cu
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
               JOIN courses c            ON c.id  = cc.course_id
               JOIN enrollments e        ON e.course_id = c.id AND e.student_user_id = ?
              WHERE c.tenant_id = ?'
        );
        $stmt->execute([$studentId, $tenantId]);
        $cuIds = array_map(static fn (array $r): int => (int) $r['id'], $stmt->fetchAll());

        $count = 0;
        foreach ($cuIds as $cuId) {
            $status = StudentProgress::cuStatus($cuId, $studentId);
            if (($status['status'] ?? '') === 'completed') {
                $count++;
            }
        }
        return $count;
    }

    /**
     * CCs concluídas: CC completa quando todas as UCs dela estão `completed`.
     * CC sem UCs (caso degenerado) não conta.
     */
    private static function countCompletedCCs(int $studentId, int $tenantId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT cc.id
               FROM core_competencies cc
               JOIN courses c     ON c.id = cc.course_id
               JOIN enrollments e ON e.course_id = c.id AND e.student_user_id = ?
              WHERE c.tenant_id = ?'
        );
        $stmt->execute([$studentId, $tenantId]);
        $ccIds = array_map(static fn (array $r): int => (int) $r['id'], $stmt->fetchAll());

        $count = 0;
        foreach ($ccIds as $ccId) {
            $cuStmt = Database::pdo()->prepare(
                'SELECT id FROM competence_units WHERE core_competency_id = ?'
            );
            $cuStmt->execute([$ccId]);
            $cuIds = array_map(static fn (array $r): int => (int) $r['id'], $cuStmt->fetchAll());
            if ($cuIds === []) {
                continue;
            }
            $allComplete = true;
            foreach ($cuIds as $cuId) {
                $status = StudentProgress::cuStatus($cuId, $studentId);
                if (($status['status'] ?? '') !== 'completed') {
                    $allComplete = false;
                    break;
                }
            }
            if ($allComplete) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Cursos concluídos: delega pra `StudentProgress::courseStatus` que
     * implementa a fórmula do `doc/10`.
     */
    private static function countCompletedCourses(int $studentId, int $tenantId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT c.id
               FROM courses c
               JOIN enrollments e ON e.course_id = c.id AND e.student_user_id = ?
              WHERE c.tenant_id = ?'
        );
        $stmt->execute([$studentId, $tenantId]);
        $courseIds = array_map(static fn (array $r): int => (int) $r['id'], $stmt->fetchAll());

        $count = 0;
        foreach ($courseIds as $courseId) {
            $status = StudentProgress::courseStatus($courseId, $studentId);
            if (($status['status'] ?? '') === 'completed') {
                $count++;
            }
        }
        return $count;
    }
}
