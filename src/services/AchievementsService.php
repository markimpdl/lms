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
                // Hook (E18-04) chama após qualquer XP award — só desbloqueia
                // se aluno realmente já saiu da patente inicial. Defensivo
                // contra hooks que disparem sem verificar antes.
                if (!self::hasBeenPromoted($studentId, $tenantId)) {
                    return [];
                }
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
     * Conta avaliações enviadas pelo aluno. Desde v0.29.0 a UK
     * (evaluation_id, student_user_id, attempt) garante 1 linha por avaliação
     * por aluno (sem mais histórico de tentativas reprovadas) — então
     * COUNT(*) reflete diretamente "quantas avaliações distintas o aluno
     * enviou".
     */
    private static function countEvaluationSubmissions(int $studentId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM evaluation_submissions
              WHERE student_user_id = ?'
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

    // ============================================================
    // Engine on-demand defensivo (E18-03)
    // ============================================================

    /**
     * Recomputa todas as conquistas do aluno do estado atual — slow path
     * defensivo. Roda no carregamento de `/student/achievements` (E18-05)
     * pra garantir que conquistas não foram perdidas por hooks (E18-04)
     * que falharam silenciosamente.
     *
     * Cobre as 9 origens: as 3 count-based (atividade/avaliação/UC/CC/curso)
     * + eval_grade via melhor nota + max_grade via existência + os 3 pontuais
     * inferidos do estado atual (notif lida, curso aberto, patente >
     * inicial).
     *
     * @return list<int> ids de conquistas desbloqueadas nesta execução
     */
    public static function evaluateAll(int $studentId, int $tenantId): array
    {
        if ($studentId <= 0 || $tenantId <= 0) {
            return [];
        }

        $allUnlocks = [];

        // Eventos count-based (com max_grade context inferido por consulta).
        $countEvents = [
            'activity_submitted'   => [],
            'evaluation_submitted' => [],
            'uc_completed'         => ['max_grade' => self::hasUCWithMaxGrade($studentId, $tenantId)],
            'cc_completed'         => ['max_grade' => self::hasCCWithMaxGrade($studentId, $tenantId)],
            'course_completed'     => ['max_grade' => self::hasCourseWithMaxGrade($studentId, $tenantId)],
        ];
        foreach ($countEvents as $event => $context) {
            $allUnlocks = array_merge(
                $allUnlocks,
                self::evaluateForEvent($studentId, $tenantId, $event, $context)
            );
        }

        // eval_grade_X_percent — usa a melhor nota histórica do aluno
        $bestGrade = self::bestEvaluationGrade($studentId);
        if ($bestGrade !== null) {
            $allUnlocks = array_merge(
                $allUnlocks,
                self::evaluateForEvent($studentId, $tenantId, 'evaluation_graded', ['grade' => $bestGrade])
            );
        }

        // Pontuais inferidos do estado atual
        if (self::hasReadAnyNotification($studentId)) {
            $allUnlocks = array_merge(
                $allUnlocks,
                self::evaluateForEvent($studentId, $tenantId, 'notification_read')
            );
        }
        if (self::hasOpenedAnyCourse($studentId)) {
            $allUnlocks = array_merge(
                $allUnlocks,
                self::evaluateForEvent($studentId, $tenantId, 'course_started')
            );
        }
        if (self::hasBeenPromoted($studentId, $tenantId)) {
            $allUnlocks = array_merge(
                $allUnlocks,
                self::evaluateForEvent($studentId, $tenantId, 'rank_first_promotion')
            );
        }

        return $allUnlocks;
    }

    /**
     * Catálogo do tenant filtrado pelo que é alcançável dado o estado
     * atual (cursos/CCs/UCs/atividades/avaliações totais). Usado pela
     * tela `/student/achievements` (E18-05) pra **não exibir** conquistas
     * impossíveis (ex.: tenant com 1 curso não mostra "Concluiu 5 cursos").
     *
     * Conquistas pontuais (`notification_read`, `course_started`,
     * `rank_first_promotion`, `*_max_grade`) sempre voltam — assumindo que
     * o tenant tem pelo menos 1 curso/avaliação/etc. Filtros mínimos:
     *   - `rank_first_promotion`: tenant precisa ter ≥ 2 patentes
     *   - `*_max_grade`: respectivo escopo precisa ter ≥ 1 avaliação
     *
     * @return list<array<string,mixed>>
     */
    public static function availableForTenant(int $tenantId): array
    {
        if ($tenantId <= 0) {
            return [];
        }
        $stats = self::tenantStats($tenantId);

        $stmt = Database::pdo()->prepare(
            'SELECT id, code, family, threshold, icon_key, name_pt, name_en, sort_order
               FROM achievements ORDER BY sort_order ASC'
        );
        $stmt->execute();
        $catalog = $stmt->fetchAll();

        $maxByFamily = [
            'uc_completed'         => $stats['ucs'],
            'cc_completed'         => $stats['ccs'],
            'course_completed'     => $stats['courses'],
            'activity_submitted'   => $stats['activities'],
            'evaluation_submitted' => $stats['evaluations'],
        ];

        $available = [];
        foreach ($catalog as $row) {
            $family    = (string) $row['family'];
            $threshold = $row['threshold'] !== null ? (int) $row['threshold'] : null;

            if (isset($maxByFamily[$family])) {
                if ($threshold !== null && $threshold > $maxByFamily[$family]) {
                    continue; // impossível alcançar
                }
                $available[] = $row;
                continue;
            }

            switch ($family) {
                case 'uc_max_grade':
                case 'eval_grade_60_percent':
                case 'eval_grade_80_percent':
                case 'eval_grade_100_percent':
                    if ($stats['evaluations'] === 0) {
                        continue 2; // nenhuma avaliação no tenant — impossível
                    }
                    break;
                case 'cc_max_grade':
                    if ($stats['ccs'] === 0 || $stats['evaluations'] === 0) {
                        continue 2;
                    }
                    break;
                case 'course_max_grade':
                    if ($stats['courses'] === 0 || $stats['evaluations'] === 0) {
                        continue 2;
                    }
                    break;
                case 'rank_first_promotion':
                    if ($stats['ranks'] < 2) {
                        continue 2;
                    }
                    break;
                case 'course_started':
                    if ($stats['courses'] === 0) {
                        continue 2;
                    }
                    break;
                // notification_read sempre disponível (toda plataforma tem notif)
            }
            $available[] = $row;
        }
        return $available;
    }

    /**
     * Retorna mapa `[achievement_id => unlocked_at]` (datetime MySQL) das
     * conquistas que o aluno já desbloqueou no tenant. Usado pela tela
     * `/student/achievements` (E18-05) pra colorir cards e exibir data.
     *
     * @return array<int, string>
     */
    public static function unlockedForStudent(int $studentId, int $tenantId): array
    {
        if ($studentId <= 0 || $tenantId <= 0) {
            return [];
        }
        $stmt = Database::pdo()->prepare(
            'SELECT achievement_id, unlocked_at
               FROM student_achievements
              WHERE student_user_id = ? AND tenant_id = ?'
        );
        $stmt->execute([$studentId, $tenantId]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['achievement_id']] = (string) $row['unlocked_at'];
        }
        return $map;
    }

    // ============================================================
    // Helpers do estado do tenant + detectores pontuais
    // ============================================================

    /**
     * @return array{ucs:int, ccs:int, courses:int, activities:int, evaluations:int, ranks:int}
     */
    private static function tenantStats(int $tenantId): array
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM courses WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
        $coursesCount = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM core_competencies cc
               JOIN courses c ON c.id = cc.course_id
              WHERE c.tenant_id = ?'
        );
        $stmt->execute([$tenantId]);
        $ccsCount = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM competence_units cu
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
               JOIN courses c ON c.id = cc.course_id
              WHERE c.tenant_id = ?'
        );
        $stmt->execute([$tenantId]);
        $ucsCount = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM activities a
               JOIN competence_units cu  ON cu.id = a.competence_unit_id
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
               JOIN courses c            ON c.id  = cc.course_id
              WHERE c.tenant_id = ?'
        );
        $stmt->execute([$tenantId]);
        $activitiesCount = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM evaluations WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
        $evaluationsCount = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ranks WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
        $ranksCount = (int) $stmt->fetchColumn();

        return [
            'ucs'         => $ucsCount,
            'ccs'         => $ccsCount,
            'courses'     => $coursesCount,
            'activities'  => $activitiesCount,
            'evaluations' => $evaluationsCount,
            'ranks'       => $ranksCount,
        ];
    }

    /**
     * Melhor nota histórica do aluno em qualquer avaliação (current ou
     * tentativas anteriores). NULL se aluno nunca foi corrigido.
     */
    private static function bestEvaluationGrade(int $studentId): ?float
    {
        $stmt = Database::pdo()->prepare(
            'SELECT MAX(grade) FROM evaluation_submissions
              WHERE student_user_id = ? AND grade IS NOT NULL'
        );
        $stmt->execute([$studentId]);
        $val = $stmt->fetchColumn();
        return $val !== false && $val !== null ? (float) $val : null;
    }

    /**
     * Existe alguma UC do tenant onde o aluno fez todas as atividades +
     * avaliação corrente com nota = 10? Short-circuit no primeiro hit.
     */
    private static function hasUCWithMaxGrade(int $studentId, int $tenantId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT EXISTS (
                SELECT 1
                  FROM competence_units cu
                  JOIN core_competencies cc ON cc.id = cu.core_competency_id
                  JOIN courses c            ON c.id  = cc.course_id
                  JOIN enrollments e        ON e.course_id = c.id AND e.student_user_id = ?
                  JOIN evaluations ev       ON ev.competence_unit_id = cu.id
                  JOIN evaluation_submissions es ON es.evaluation_id = ev.id
                                                AND es.student_user_id = ?
                                                AND es.grade IS NOT NULL
                                                AND es.grade >= 10.0
                 WHERE c.tenant_id = ?
                   AND (SELECT COUNT(*) FROM activities a WHERE a.competence_unit_id = cu.id) =
                       (SELECT COUNT(*) FROM activity_submissions asub
                          JOIN activities a ON a.id = asub.activity_id
                         WHERE a.competence_unit_id = cu.id
                           AND asub.student_user_id = ?)
             ) AS has_max'
        );
        $stmt->execute([$studentId, $studentId, $tenantId, $studentId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Existe alguma CC onde **todas as UCs** satisfazem `isUCMaxGrade`.
     * Iteração em PHP — volume baixo no MVP.
     */
    private static function hasCCWithMaxGrade(int $studentId, int $tenantId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT cc.id FROM core_competencies cc
               JOIN courses c     ON c.id = cc.course_id
               JOIN enrollments e ON e.course_id = c.id AND e.student_user_id = ?
              WHERE c.tenant_id = ?'
        );
        $stmt->execute([$studentId, $tenantId]);
        $ccIds = array_map(static fn (array $r): int => (int) $r['id'], $stmt->fetchAll());

        foreach ($ccIds as $ccId) {
            if (self::isCCMaxGrade($ccId, $studentId)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Existe algum curso onde **todas as CCs** satisfazem `isCCMaxGrade`.
     */
    private static function hasCourseWithMaxGrade(int $studentId, int $tenantId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT c.id FROM courses c
               JOIN enrollments e ON e.course_id = c.id AND e.student_user_id = ?
              WHERE c.tenant_id = ?'
        );
        $stmt->execute([$studentId, $tenantId]);
        $courseIds = array_map(static fn (array $r): int => (int) $r['id'], $stmt->fetchAll());

        foreach ($courseIds as $courseId) {
            if (self::isCourseMaxGrade($courseId, $studentId)) {
                return true;
            }
        }
        return false;
    }

    private static function isCCMaxGrade(int $ccId, int $studentId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id FROM competence_units WHERE core_competency_id = ?'
        );
        $stmt->execute([$ccId]);
        $cuIds = array_map(static fn (array $r): int => (int) $r['id'], $stmt->fetchAll());
        if ($cuIds === []) {
            return false; // CC sem UCs não conta como max
        }
        foreach ($cuIds as $cuId) {
            if (!self::isUCMaxGradeById($cuId, $studentId)) {
                return false;
            }
        }
        return true;
    }

    private static function isCourseMaxGrade(int $courseId, int $studentId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id FROM core_competencies WHERE course_id = ?'
        );
        $stmt->execute([$courseId]);
        $ccIds = array_map(static fn (array $r): int => (int) $r['id'], $stmt->fetchAll());
        if ($ccIds === []) {
            return false;
        }
        foreach ($ccIds as $ccId) {
            if (!self::isCCMaxGrade($ccId, $studentId)) {
                return false;
            }
        }
        return true;
    }

    /**
     * UC tem nota máxima pro aluno: tem avaliação + nota corrente >= 10
     * + todas as atividades entregues.
     */
    private static function isUCMaxGradeById(int $cuId, int $studentId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT EXISTS (
                SELECT 1 FROM evaluations ev
                  JOIN evaluation_submissions es ON es.evaluation_id = ev.id
                                                AND es.student_user_id = ?
                                                AND es.grade IS NOT NULL
                                                AND es.grade >= 10.0
                 WHERE ev.competence_unit_id = ?
             )
             AND
             (SELECT COUNT(*) FROM activities a WHERE a.competence_unit_id = ?) =
             (SELECT COUNT(*) FROM activity_submissions asub
                JOIN activities a ON a.id = asub.activity_id
               WHERE a.competence_unit_id = ?
                 AND asub.student_user_id = ?) AS is_max'
        );
        $stmt->execute([$studentId, $cuId, $cuId, $cuId, $studentId]);
        return (bool) $stmt->fetchColumn();
    }

    private static function hasReadAnyNotification(int $studentId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT EXISTS (
                SELECT 1 FROM notifications WHERE user_id = ? AND read_at IS NOT NULL
             )'
        );
        $stmt->execute([$studentId]);
        return (bool) $stmt->fetchColumn();
    }

    private static function hasOpenedAnyCourse(int $studentId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT EXISTS (
                SELECT 1 FROM enrollments
                 WHERE student_user_id = ? AND last_access_at IS NOT NULL
             )'
        );
        $stmt->execute([$studentId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Aluno saiu da patente inicial. Definição: total_xp >= xp_min da
     * 2ª patente do tenant (ordenada por xp_min ASC). Sem 2 patentes,
     * não é possível ter sido promovido.
     */
    private static function hasBeenPromoted(int $studentId, int $tenantId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT xp_min FROM ranks
              WHERE tenant_id = ?
              ORDER BY xp_min ASC
              LIMIT 1 OFFSET 1'
        );
        $stmt->execute([$tenantId]);
        $secondMin = $stmt->fetchColumn();
        if ($secondMin === false) {
            return false; // tenant tem < 2 patentes
        }

        $stmt = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(value), 0) FROM xp_events WHERE student_user_id = ?'
        );
        $stmt->execute([$studentId]);
        $totalXp = (int) $stmt->fetchColumn();

        return $totalXp >= (int) $secondMin;
    }
}
