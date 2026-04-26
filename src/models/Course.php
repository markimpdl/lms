<?php
declare(strict_types=1);

/**
 * Model de Curso (E3-01).
 *
 * Todas as queries filtram por `tenant_id` — o professor logado nunca consulta
 * dados de outro tenant. `findForTenant()` devolve `null` se o curso não pertence
 * ao tenant informado, para o caller traduzir em 404 amigável.
 */
final class Course
{
    public const PER_PAGE = 20;

    /** Whitelist de colunas de ORDER BY: chave pública → expressão SQL. */
    private const SORT_COLUMNS = [
        'name'       => 'c.name',
        'year'       => 'c.year',
        'created_at' => 'c.created_at',
    ];

    /**
     * @param array<string,string> $filters Chaves: q, status ('active'|'archived'|'all'), sort, dir.
     * @return array{
     *   rows: list<array<string,mixed>>,
     *   total: int,
     *   page: int,
     *   total_pages: int,
     *   per_page: int,
     * }
     */
    public static function listByTenant(int $tenantId, array $filters, int $page): array
    {
        $q       = trim((string) ($filters['q']      ?? ''));
        $status  = (string)      ($filters['status'] ?? 'active');
        $sortKey = (string)      ($filters['sort']   ?? 'created_at');
        $sortCol = self::SORT_COLUMNS[$sortKey] ?? self::SORT_COLUMNS['created_at'];
        $dir     = strtoupper((string) ($filters['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $where  = ['c.tenant_id = :tid'];
        $params = [':tid' => $tenantId];

        if ($status === 'active') {
            $where[] = 'c.archived = 0';
        } elseif ($status === 'archived') {
            $where[] = 'c.archived = 1';
        }
        // 'all' não adiciona filtro.

        if ($q !== '') {
            $where[] = '(c.name LIKE :q OR c.description LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }
        $whereSql = implode(' AND ', $where);

        $pdo = Database::pdo();

        $stmtTotal = $pdo->prepare('SELECT COUNT(*) FROM courses c WHERE ' . $whereSql);
        $stmtTotal->execute($params);
        $total = (int) $stmtTotal->fetchColumn();

        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page       = max(1, min($page, $totalPages));
        $offset     = ($page - 1) * self::PER_PAGE;

        $sql = <<<SQL
            SELECT
                c.id, c.name, c.description, c.year, c.language,
                c.archived, c.archived_at, c.created_at, c.updated_at,
                COUNT(DISTINCT cc.id) AS cc_count,
                COUNT(DISTINCT cu.id) AS cu_count
            FROM courses c
            LEFT JOIN core_competencies cc ON cc.course_id = c.id
            LEFT JOIN competence_units  cu ON cu.core_competency_id = cc.id
            WHERE {$whereSql}
            GROUP BY c.id, c.name, c.description, c.year, c.language,
                     c.archived, c.archived_at, c.created_at, c.updated_at
            ORDER BY {$sortCol} {$dir}, c.id DESC
            LIMIT :limit OFFSET :offset
            SQL;

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit',  self::PER_PAGE, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,        PDO::PARAM_INT);
        $stmt->execute();

        return [
            'rows'        => $stmt->fetchAll(),
            'total'       => $total,
            'page'        => $page,
            'total_pages' => $totalPages,
            'per_page'    => self::PER_PAGE,
        ];
    }

    /**
     * Retorna curso do tenant (com contagens), ou null se não pertence.
     * @return array<string,mixed>|null
     */
    public static function findForTenant(int $courseId, int $tenantId): ?array
    {
        $sql = <<<SQL
            SELECT
                c.id, c.tenant_id, c.name, c.description, c.year, c.language,
                c.archived, c.archived_at, c.created_at, c.updated_at,
                c.cc_mode, c.activity_mode, c.eval_after_activities, c.grading_mode,
                COUNT(DISTINCT cc.id) AS cc_count,
                COUNT(DISTINCT cu.id) AS cu_count
            FROM courses c
            LEFT JOIN core_competencies cc ON cc.course_id = c.id
            LEFT JOIN competence_units  cu ON cu.core_competency_id = cc.id
            WHERE c.id = :id AND c.tenant_id = :tid
            GROUP BY c.id, c.tenant_id, c.name, c.description, c.year, c.language,
                     c.archived, c.archived_at, c.created_at, c.updated_at,
                     c.cc_mode, c.activity_mode, c.eval_after_activities, c.grading_mode
            LIMIT 1
            SQL;
        $stmt = Database::pdo()->prepare($sql);
        $stmt->bindValue(':id',  $courseId, PDO::PARAM_INT);
        $stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Lista enxuta de cursos ativos do tenant — apenas id, name e year —
     * para popular multi-selects (ex.: matricular aluno, E4-02). Ordenada
     * por ano desc + nome asc para o curso "mais recente" aparecer primeiro.
     *
     * @return list<array{id:int,name:string,year:int}>
     */
    public static function listActiveForSelect(int $tenantId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, name, year
               FROM courses
              WHERE tenant_id = ? AND archived = 0
              ORDER BY year DESC, name ASC'
        );
        $stmt->execute([$tenantId]);
        $rows = $stmt->fetchAll();
        return array_map(static fn(array $r): array => [
            'id'   => (int) $r['id'],
            'name' => (string) $r['name'],
            'year' => (int) $r['year'],
        ], $rows);
    }

    /**
     * @param array{name:string, description:?string, year:int, language:string, cc_mode:string, activity_mode:string, eval_after_activities:int, grading_mode:string} $data
     */
    public static function create(int $tenantId, array $data): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO courses
                (tenant_id, name, description, year, language, cc_mode, activity_mode, eval_after_activities, grading_mode)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $tenantId,
            $data['name'],
            $data['description'] !== '' ? $data['description'] : null,
            $data['year'],
            $data['language'],
            $data['cc_mode'],
            $data['activity_mode'],
            $data['eval_after_activities'],
            $data['grading_mode'],
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * @param array{name:string, description:?string, year:int, language:string, cc_mode:string, activity_mode:string, eval_after_activities:int, grading_mode:string} $data
     */
    public static function update(int $courseId, int $tenantId, array $data): bool
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE courses SET
                name = ?, description = ?, year = ?, language = ?,
                cc_mode = ?, activity_mode = ?, eval_after_activities = ?,
                grading_mode = ?
              WHERE id = ? AND tenant_id = ?'
        );
        $stmt->execute([
            $data['name'],
            $data['description'] !== '' ? $data['description'] : null,
            $data['year'],
            $data['language'],
            $data['cc_mode'],
            $data['activity_mode'],
            $data['eval_after_activities'],
            $data['grading_mode'],
            $courseId,
            $tenantId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Arquiva (archived=1, archived_at=NOW). No-op se já arquivado.
     * Usa timestamp PHP para consistência com o padrão do projeto.
     */
    public static function archive(int $courseId, int $tenantId): bool
    {
        $now = date('Y-m-d H:i:s');
        $stmt = Database::pdo()->prepare(
            'UPDATE courses SET archived = 1, archived_at = ?
              WHERE id = ? AND tenant_id = ? AND archived = 0'
        );
        $stmt->execute([$now, $courseId, $tenantId]);
        return $stmt->rowCount() > 0;
    }

    public static function restore(int $courseId, int $tenantId): bool
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE courses SET archived = 0, archived_at = NULL
              WHERE id = ? AND tenant_id = ? AND archived = 1'
        );
        $stmt->execute([$courseId, $tenantId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Conta descendentes do curso em uma query só. Retorna zeros quando o
     * curso não pertence ao tenant (caller precisa validar antes).
     *
     * @return array{ccs:int, cus:int, activities:int, evaluations:int, enrollments:int}
     */
    public static function countDescendants(int $courseId, int $tenantId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT
                (SELECT COUNT(*) FROM core_competencies WHERE course_id = ?) AS ccs,
                (SELECT COUNT(*) FROM competence_units cu
                    JOIN core_competencies cc ON cc.id = cu.core_competency_id
                    WHERE cc.course_id = ?) AS cus,
                (SELECT COUNT(*) FROM activities a
                    JOIN competence_units cu ON cu.id = a.competence_unit_id
                    JOIN core_competencies cc ON cc.id = cu.core_competency_id
                    WHERE cc.course_id = ?) AS activities,
                (SELECT COUNT(*) FROM evaluations ev
                    JOIN competence_units cu ON cu.id = ev.competence_unit_id
                    JOIN core_competencies cc ON cc.id = cu.core_competency_id
                    WHERE cc.course_id = ?) AS evaluations,
                (SELECT COUNT(*) FROM enrollments WHERE course_id = ?) AS enrollments
              FROM courses WHERE id = ? AND tenant_id = ? LIMIT 1'
        );
        $stmt->execute([$courseId, $courseId, $courseId, $courseId, $courseId, $courseId, $tenantId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return ['ccs' => 0, 'cus' => 0, 'activities' => 0, 'evaluations' => 0, 'enrollments' => 0];
        }
        return [
            'ccs'         => (int) $row['ccs'],
            'cus'         => (int) $row['cus'],
            'activities'  => (int) $row['activities'],
            'evaluations' => (int) $row['evaluations'],
            'enrollments' => (int) $row['enrollments'],
        ];
    }

    /**
     * Exclui curso do tenant após revalidar o nome enviado pelo usuário.
     * Cascade do schema cuida de CCs, CUs, conteúdo, atividades, avaliações,
     * submissões e enrollments.
     *
     * Retorna:
     *  - 'ok'           se deletou
     *  - 'not_found'    se curso não existe ou não pertence ao tenant
     *  - 'name_mismatch' se o nome digitado não bate (case-sensitive)
     */
    public static function delete(int $courseId, int $tenantId, string $expectedName): string
    {
        $course = self::findForTenant($courseId, $tenantId);
        if ($course === null) {
            return 'not_found';
        }
        if ((string) $course['name'] !== $expectedName) {
            return 'name_mismatch';
        }
        Database::pdo()
            ->prepare('DELETE FROM courses WHERE id = ? AND tenant_id = ?')
            ->execute([$courseId, $tenantId]);
        return 'ok';
    }

    /**
     * Agregação pra tela "Meus cursos" do aluno (E14-02). Junta em uma só
     * passagem:
     *  - dados do curso (id, name, language, archived)
     *  - `last_access_at` da matrícula e `enrolled_at`
     *  - nome do instrutor (owner do tenant — ADR-025)
     *  - totais: CUs do curso, horas somadas (workload), XP teórico
     *
     * O status/percent do aluno é on-the-fly via `StudentProgress` — não
     * cabe nesta query. O caller itera e enriquece com `student_course_status`.
     *
     * Ordena por `last_access_at DESC NULLS LAST, course.name ASC` pra
     * colocar cursos ativos no topo.
     *
     * @return list<array<string,mixed>>
     */
    public static function listForStudentWithProgress(int $studentId): array
    {
        $sql = <<<SQL
            SELECT
                c.id                AS course_id,
                c.name              AS course_name,
                c.language          AS course_language,
                c.archived          AS course_archived,
                e.enrolled_at       AS enrolled_at,
                e.last_access_at    AS last_access_at,
                e.access_starts_at  AS access_starts_at,
                e.access_ends_at    AS access_ends_at,
                e.blocked_at        AS blocked_at,
                u.name              AS instructor_name,
                (SELECT COUNT(*)
                   FROM competence_units cu
                   JOIN core_competencies cc ON cc.id = cu.core_competency_id
                  WHERE cc.course_id = c.id)                    AS units_total,
                (SELECT COALESCE(SUM(cu.workload_hours), 0)
                   FROM competence_units cu
                   JOIN core_competencies cc ON cc.id = cu.core_competency_id
                  WHERE cc.course_id = c.id)                    AS total_hours
              FROM enrollments e
              JOIN courses c       ON c.id        = e.course_id
              JOIN tenants t       ON t.id        = c.tenant_id
              JOIN users   u       ON u.id        = t.owner_user_id
             WHERE e.student_user_id = ?
             ORDER BY e.last_access_at IS NULL ASC,
                      e.last_access_at DESC,
                      c.name ASC, c.id ASC
            SQL;

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([$studentId]);
        return $stmt->fetchAll();
    }

    /**
     * Deriva um par de cores (hex) pro gradient do cover do CourseCard a
     * partir do `courseId` (E14-02). Palette fixa de 6 combinações do design
     * handoff — determinística, mesma cor sempre pro mesmo curso, zero
     * schema change.
     *
     * @return array{0:string, 1:string} `[color_start, color_end]`
     */
    public static function coverGradient(int $courseId): array
    {
        static $palette = [
            ['#6366F1', '#8B5CF6'], // indigo → violet
            ['#EC4899', '#F59E0B'], // pink → amber
            ['#06B6D4', '#3B82F6'], // cyan → blue
            ['#10B981', '#34D399'], // emerald → green
            ['#F59E0B', '#EF4444'], // amber → red
            ['#8B5CF6', '#EC4899'], // violet → pink
        ];
        $idx = abs(crc32((string) $courseId)) % count($palette);
        return $palette[$idx];
    }
}
