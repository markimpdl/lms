<?php
declare(strict_types=1);

/**
 * Exclusão explícita da árvore de conteúdo: curso → CC → CU → capa, lição,
 * atividade, avaliação → entregas, arquivos, quizzes, XP.
 *
 * Por que existe: o banco de produção NÃO tem as FKs da hierarquia
 * (courses/core_competencies/competence_units/contents/activities, e
 * evaluations só tem a do tenant). Os DELETEs antigos confiavam no
 * ON DELETE CASCADE do schema.sql e deixavam tudo abaixo para trás. Como o
 * MariaDB reaproveita o id apagado (AUTO_INCREMENT volta a MAX+1 no restart),
 * o órfão grudava no registro novo — a CC 72 do curso 19 nasceu com a CU de
 * outubro que pertencia à CC 72 apagada do curso 11.
 *
 * Regras:
 *  - Apaga de baixo para cima, então funciona com ou sem FK.
 *  - Não abre transação: o chamador envolve em Database::tx().
 *  - Não valida tenant: o chamador já validou o dono da raiz.
 *  - Devolve os caminhos físicos; o chamador chama unlinkAll() DEPOIS do
 *    commit (arquivo apagado não volta com rollback).
 *  - XP ligado a atividade/avaliação/lição/CU apagada sai junto, como já fazia
 *    Activity::delete — senão a UK (aluno, source_type, source_id) bloqueia o
 *    XP do aluno na atividade nova que herdar o id.
 */
final class ContentTreeDeleter
{
    /** @return list<string> caminhos relativos a apagar depois do commit */
    public static function course(PDO $pdo, int $courseId, int $tenantId): array
    {
        // Raiz sempre filtrada pelo tenant (o chamador já validou, mas a árvore
        // só desce se o curso for mesmo deste professor).
        $own = $pdo->prepare('SELECT 1 FROM courses WHERE id = ? AND tenant_id = ?');
        $own->execute([$courseId, $tenantId]);
        if ($own->fetchColumn() === false) {
            return [];
        }

        $ccIds = self::ids($pdo, 'SELECT id FROM core_competencies WHERE course_id = ?', [$courseId]);
        $paths = self::coreCompetencies($pdo, $ccIds);

        $pdo->prepare('DELETE FROM enrollments WHERE course_id = ?')->execute([$courseId]);
        $pdo->prepare('DELETE FROM course_collaborators WHERE course_id = ?')->execute([$courseId]);
        $pdo->prepare('DELETE FROM course_audit_log WHERE course_id = ?')->execute([$courseId]);
        // Mesmo efeito do ON DELETE SET NULL do schema para o XP que sobrar.
        $pdo->prepare('UPDATE xp_events SET course_id = NULL WHERE course_id = ?')->execute([$courseId]);
        $pdo->prepare('DELETE FROM courses WHERE id = ? AND tenant_id = ?')->execute([$courseId, $tenantId]);

        return $paths;
    }

    /**
     * @param list<int> $ccIds
     * @return list<string>
     */
    public static function coreCompetencies(PDO $pdo, array $ccIds): array
    {
        if ($ccIds === []) {
            return [];
        }
        $in    = self::in($ccIds);
        $cuIds = self::ids($pdo, "SELECT id FROM competence_units WHERE core_competency_id IN ($in)", $ccIds);
        $paths = self::competenceUnits($pdo, $cuIds);

        $pdo->prepare("DELETE FROM core_competencies WHERE id IN ($in)")->execute($ccIds);

        return $paths;
    }

    /**
     * @param list<int> $cuIds
     * @return list<string>
     */
    public static function competenceUnits(PDO $pdo, array $cuIds): array
    {
        if ($cuIds === []) {
            return [];
        }
        $in = self::in($cuIds);

        $paths = self::activities(
            $pdo,
            self::ids($pdo, "SELECT id FROM activities WHERE competence_unit_id IN ($in)", $cuIds)
        );
        $paths = array_merge($paths, self::evaluations(
            $pdo,
            self::ids($pdo, "SELECT id FROM evaluations WHERE competence_unit_id IN ($in)", $cuIds)
        ));
        self::lessons(
            $pdo,
            self::ids($pdo, "SELECT id FROM lessons WHERE competence_unit_id IN ($in)", $cuIds)
        );

        // Capa da CU + anexos dela.
        $contentIds = self::ids($pdo, "SELECT id FROM contents WHERE competence_unit_id IN ($in)", $cuIds);
        if ($contentIds !== []) {
            $cin   = self::in($contentIds);
            $paths = array_merge($paths, self::column(
                $pdo,
                "SELECT stored_path FROM content_attachments WHERE content_id IN ($cin)",
                $contentIds
            ));
            $pdo->prepare("DELETE FROM content_attachments WHERE content_id IN ($cin)")->execute($contentIds);
            $pdo->prepare("DELETE FROM contents WHERE id IN ($cin)")->execute($contentIds);
        }

        $loIds = self::ids($pdo, "SELECT id FROM learning_outcomes WHERE cu_id IN ($in)", $cuIds);
        if ($loIds !== []) {
            $lin = self::in($loIds);
            $pdo->prepare("DELETE FROM evaluation_submission_lo_grades WHERE lo_id IN ($lin)")->execute($loIds);
            $pdo->prepare("DELETE FROM learning_outcomes WHERE id IN ($lin)")->execute($loIds);
        }

        $pdo->prepare("DELETE FROM cu_manual_completions WHERE cu_id IN ($in)")->execute($cuIds);
        $pdo->prepare("DELETE FROM student_cu_unlocks WHERE cu_id IN ($in)")->execute($cuIds);
        $pdo->prepare("DELETE FROM xp_events WHERE source_type = 'cu_manual' AND source_id IN ($in)")
            ->execute($cuIds);
        $pdo->prepare("DELETE FROM competence_units WHERE id IN ($in)")->execute($cuIds);

        return $paths;
    }

    /**
     * @param list<int> $activityIds
     * @return list<string>
     */
    public static function activities(PDO $pdo, array $activityIds): array
    {
        if ($activityIds === []) {
            return [];
        }
        $in    = self::in($activityIds);
        $subIn = "SELECT id FROM activity_submissions WHERE activity_id IN ($in)";

        $paths = array_merge(
            self::column($pdo, "SELECT stored_path FROM activity_submission_files WHERE submission_id IN ($subIn)", $activityIds),
            self::column($pdo, "SELECT stored_path FROM activity_submissions WHERE activity_id IN ($in)", $activityIds),
            self::column($pdo, "SELECT pdf_path FROM activities WHERE id IN ($in)", $activityIds)
        );

        $pdo->prepare("DELETE FROM activity_submission_files WHERE submission_id IN ($subIn)")->execute($activityIds);
        $pdo->prepare("DELETE FROM activity_submissions WHERE activity_id IN ($in)")->execute($activityIds);
        $pdo->prepare("DELETE FROM xp_events WHERE source_type = 'activity' AND source_id IN ($in)")
            ->execute($activityIds);
        self::quizzes($pdo, 'activity', $activityIds);
        $pdo->prepare("DELETE FROM activities WHERE id IN ($in)")->execute($activityIds);

        return $paths;
    }

    /**
     * @param list<int> $evaluationIds
     * @return list<string>
     */
    public static function evaluations(PDO $pdo, array $evaluationIds): array
    {
        if ($evaluationIds === []) {
            return [];
        }
        $in    = self::in($evaluationIds);
        $subIn = "SELECT id FROM evaluation_submissions WHERE evaluation_id IN ($in)";

        $paths = array_merge(
            self::column($pdo, "SELECT stored_path FROM evaluation_submission_files WHERE submission_id IN ($subIn)", $evaluationIds),
            self::column($pdo, "SELECT stored_path FROM evaluation_submissions WHERE evaluation_id IN ($in)", $evaluationIds),
            self::column($pdo, "SELECT report_pdf_path FROM evaluation_submissions WHERE evaluation_id IN ($in)", $evaluationIds),
            self::column($pdo, "SELECT pdf_path FROM evaluations WHERE id IN ($in)", $evaluationIds)
        );

        $pdo->prepare("DELETE FROM evaluation_submission_files WHERE submission_id IN ($subIn)")->execute($evaluationIds);
        $pdo->prepare("DELETE FROM evaluation_submission_lo_grades WHERE submission_id IN ($subIn)")->execute($evaluationIds);
        $pdo->prepare("DELETE FROM evaluation_submissions WHERE evaluation_id IN ($in)")->execute($evaluationIds);
        $pdo->prepare("DELETE FROM xp_events WHERE source_type = 'evaluation' AND source_id IN ($in)")
            ->execute($evaluationIds);
        self::quizzes($pdo, 'evaluation', $evaluationIds);
        $pdo->prepare("DELETE FROM evaluations WHERE id IN ($in)")->execute($evaluationIds);

        return $paths;
    }

    /** @param list<int> $lessonIds */
    public static function lessons(PDO $pdo, array $lessonIds): void
    {
        if ($lessonIds === []) {
            return;
        }
        $in = self::in($lessonIds);
        $pdo->prepare("DELETE FROM lesson_completions WHERE lesson_id IN ($in)")->execute($lessonIds);
        $pdo->prepare("DELETE FROM xp_events WHERE source_type = 'lesson' AND source_id IN ($in)")
            ->execute($lessonIds);
        $pdo->prepare("DELETE FROM lessons WHERE id IN ($in)")->execute($lessonIds);
    }

    /**
     * Quiz polimórfico + perguntas + alternativas (as FKs internas do quiz
     * também podem faltar em produção).
     *
     * @param 'activity'|'evaluation' $ownerType
     * @param list<int> $ownerIds
     */
    public static function quizzes(PDO $pdo, string $ownerType, array $ownerIds): void
    {
        if ($ownerIds === []) {
            return;
        }
        $params  = array_merge([$ownerType], $ownerIds);
        $quizIn  = 'SELECT id FROM quizzes WHERE owner_type = ? AND owner_id IN (' . self::in($ownerIds) . ')';
        $questIn = "SELECT id FROM quiz_questions WHERE quiz_id IN ($quizIn)";

        $pdo->prepare("DELETE FROM quiz_options WHERE question_id IN ($questIn)")->execute($params);
        $pdo->prepare("DELETE FROM quiz_questions WHERE quiz_id IN ($quizIn)")->execute($params);
        $pdo->prepare('DELETE FROM quizzes WHERE owner_type = ? AND owner_id IN (' . self::in($ownerIds) . ')')
            ->execute($params);
    }

    /**
     * Apaga os arquivos e, em seguida, as pastas que ficaram vazias
     * (submissions/{id}, activities/{id}...). rmdir só remove pasta vazia, e
     * nunca sobe acima de storage/uploads.
     *
     * @param list<string> $paths
     */
    public static function unlinkAll(array $paths): void
    {
        $base = @realpath(LMS_ROOT . '/storage/uploads');
        $dirs = [];
        foreach (array_unique($paths) as $p) {
            $dir = @realpath(dirname(LMS_ROOT . '/' . ltrim($p, '/')));
            safe_unlink_storage($p);
            if ($base !== false && $dir !== false && str_starts_with($dir, $base . '/')) {
                $dirs[$dir] = true;
            }
        }
        foreach (array_keys($dirs) as $dir) {
            @rmdir($dir);
        }
    }

    /**
     * @param list<int|string> $params
     * @return list<int>
     */
    private static function ids(PDO $pdo, string $sql, array $params): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Coluna de caminho, sem nulos/vazios.
     *
     * @param list<int|string> $params
     * @return list<string>
     */
    private static function column(PDO $pdo, string $sql, array $params): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $v) {
            if ($v !== null && trim((string) $v) !== '') {
                $out[] = (string) $v;
            }
        }
        return $out;
    }

    /** @param list<int> $ids */
    private static function in(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }
}
