<?php
declare(strict_types=1);

/**
 * Model de Matrícula (E4-02).
 *
 * Matrícula liga `users.role='student'` a `courses.id` por meio da tabela
 * `enrollments`, com UK `(student_user_id, course_id)` garantindo que a
 * mesma pessoa só pode ser matriculada uma vez no mesmo curso.
 *
 * Toda operação valida tenant: aluno e curso precisam pertencer ao mesmo
 * tenant do professor logado. Cross-tenant é bloqueado a seco (retorno
 * 'wrong_tenant') — nunca vaza a informação "aluno/curso existe em outro
 * tenant".
 */
final class Enrollment
{
    public const PER_PAGE = 20;

    /**
     * Lista cursos em que o aluno está matriculado, ordenados por data de
     * matrícula descendente. Limita ao tenant do professor.
     *
     * @return list<array<string,mixed>>
     */
    public static function listByStudent(int $studentId, int $tenantId): array
    {
        $sql = <<<SQL
            SELECT e.id AS enrollment_id, e.enrolled_at,
                   e.access_starts_at, e.access_ends_at,
                   c.id AS course_id, c.name, c.year, c.language,
                   c.archived, c.archived_at
              FROM enrollments e
              JOIN courses c ON c.id = e.course_id
              JOIN users   u ON u.id = e.student_user_id
             WHERE e.student_user_id = ?
               AND u.tenant_id = ?
               AND c.tenant_id = ?
               AND u.role = 'student'
             ORDER BY e.enrolled_at DESC, e.id DESC
            SQL;
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([$studentId, $tenantId, $tenantId]);
        return $stmt->fetchAll();
    }

    /**
     * Lista alunos matriculados em um curso, paginado. Só retorna quando o
     * curso pertence ao tenant do professor.
     *
     * @return array{
     *   rows: list<array<string,mixed>>,
     *   total: int,
     *   page: int,
     *   total_pages: int,
     *   per_page: int,
     * }
     */
    public static function listByCourse(int $courseId, int $tenantId, int $page): array
    {
        $pdo = Database::pdo();

        $stmtTotal = $pdo->prepare(
            'SELECT COUNT(*)
               FROM enrollments e
               JOIN users   u ON u.id = e.student_user_id
               JOIN courses c ON c.id = e.course_id
              WHERE e.course_id = ?
                AND c.tenant_id = ?
                AND u.tenant_id = ?
                AND u.role = "student"'
        );
        $stmtTotal->execute([$courseId, $tenantId, $tenantId]);
        $total = (int) $stmtTotal->fetchColumn();

        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page       = max(1, min($page, $totalPages));
        $offset     = ($page - 1) * self::PER_PAGE;

        // Placeholders posicionais em tudo: PDO (com emulação off) não aceita
        // mistura de `?` e `:nome` no mesmo statement. Mantemos o mesmo estilo
        // da query de COUNT acima, repetindo $tenantId nas duas posições.
        $sql = <<<SQL
            SELECT u.id AS student_id, u.name, u.email, u.language, u.active,
                   e.enrolled_at, e.status,
                   e.access_starts_at, e.access_ends_at, e.blocked_at
              FROM enrollments e
              JOIN users   u ON u.id = e.student_user_id
              JOIN courses c ON c.id = e.course_id
             WHERE e.course_id = ?
               AND c.tenant_id = ?
               AND u.tenant_id = ?
               AND u.role = "student"
             ORDER BY u.name ASC, u.id ASC
             LIMIT ? OFFSET ?
            SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1, $courseId,      PDO::PARAM_INT);
        $stmt->bindValue(2, $tenantId,      PDO::PARAM_INT);
        $stmt->bindValue(3, $tenantId,      PDO::PARAM_INT);
        $stmt->bindValue(4, self::PER_PAGE, PDO::PARAM_INT);
        $stmt->bindValue(5, $offset,        PDO::PARAM_INT);
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
     * Cria matrícula após validações de tenant e estado. Retorna:
     *   'ok'           — criada (ou já existia: UK absorve o INSERT IGNORE)
     *   'wrong_tenant' — aluno ou curso não pertence ao tenant informado
     *   'student_inactive' — aluno existe no tenant mas está desativado
     *   'course_archived'  — curso existe no tenant mas está arquivado
     */
    public static function create(
        int $studentId,
        int $courseId,
        int $tenantId,
        ?string $accessStartsAt = null,
        ?string $accessEndsAt = null
    ): string {
        $pdo = Database::pdo();

        // Validação composta em uma query só — evita race.
        $stmt = $pdo->prepare(
            'SELECT u.active AS s_active, c.archived AS c_archived
               FROM users u
               JOIN courses c ON c.tenant_id = u.tenant_id
              WHERE u.id = ?
                AND c.id = ?
                AND u.tenant_id = ?
                AND u.role = "student"
              LIMIT 1'
        );
        $stmt->execute([$studentId, $courseId, $tenantId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return 'wrong_tenant';
        }
        if ((int) $row['s_active'] === 0) {
            return 'student_inactive';
        }
        if ((int) $row['c_archived'] === 1) {
            return 'course_archived';
        }

        // INSERT IGNORE absorve a UK (student_user_id, course_id) e torna a
        // operação idempotente: re-matricular o mesmo aluno no mesmo curso
        // é no-op silencioso, sem SQLSTATE 23000. Período (E17-01) entra na
        // primeira matrícula; pra alterar depois usar `updatePeriod`.
        $pdo->prepare(
            'INSERT IGNORE INTO enrollments
                (student_user_id, course_id, access_starts_at, access_ends_at)
             VALUES (?, ?, ?, ?)'
        )->execute([$studentId, $courseId, $accessStartsAt, $accessEndsAt]);

        return 'ok';
    }

    /**
     * Toggle do bloqueio de acesso (E17-02). Se `blocked_at IS NULL`, seta
     * `NOW()`; senão, volta a NULL. Reversível. Preserva XP, progresso,
     * histórico — só impede acesso enquanto setado. Ownership via JOIN duplo.
     *
     * @return bool|null true=blocked, false=unblocked, null=cross-tenant ou
     *                   matrícula inexistente.
     */
    public static function toggleBlock(int $studentId, int $courseId, int $tenantId): ?bool
    {
        $pdo = Database::pdo();

        // Lê estado atual com ownership check no SELECT
        $stmt = $pdo->prepare(
            'SELECT e.blocked_at
               FROM enrollments e
               JOIN users   u ON u.id = e.student_user_id
               JOIN courses c ON c.id = e.course_id
              WHERE e.student_user_id = ?
                AND e.course_id = ?
                AND u.tenant_id = ?
                AND c.tenant_id = ?
                AND u.role = "student"
              LIMIT 1'
        );
        $stmt->execute([$studentId, $courseId, $tenantId, $tenantId]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            return null;
        }

        $newValue = $current === null ? date('Y-m-d H:i:s') : null;
        $update = $pdo->prepare(
            'UPDATE enrollments e
                JOIN users   u ON u.id = e.student_user_id
                JOIN courses c ON c.id = e.course_id
                SET e.blocked_at = ?
              WHERE e.student_user_id = ?
                AND e.course_id = ?
                AND u.tenant_id = ?
                AND c.tenant_id = ?'
        );
        $update->execute([$newValue, $studentId, $courseId, $tenantId, $tenantId]);

        return $newValue !== null;
    }

    /**
     * Remove matrícula com confirmação por digitação (E17-02). Valida que o
     * `expectedEmail` bate com o email do aluno antes do DELETE — padrão E3-05.
     * NÃO apaga `xp_events` daquele curso (preserva histórico).
     *
     * @return string 'ok' | 'not_found' | 'email_mismatch'
     */
    public static function deleteWithConfirm(
        int $studentId,
        int $courseId,
        int $tenantId,
        string $expectedEmail
    ): string {
        $pdo = Database::pdo();

        // Lookup do email com ownership check
        $stmt = $pdo->prepare(
            'SELECT u.email
               FROM enrollments e
               JOIN users   u ON u.id = e.student_user_id
               JOIN courses c ON c.id = e.course_id
              WHERE e.student_user_id = ?
                AND e.course_id = ?
                AND u.tenant_id = ?
                AND c.tenant_id = ?
                AND u.role = "student"
              LIMIT 1'
        );
        $stmt->execute([$studentId, $courseId, $tenantId, $tenantId]);
        $email = $stmt->fetchColumn();
        if ($email === false) {
            return 'not_found';
        }
        if ((string) $email !== $expectedEmail) {
            return 'email_mismatch';
        }

        // DELETE limpo — `xp_events.course_id` permanece intacto (FK não cascateia
        // via enrollment; cascade vem por user/course em outras hipóteses).
        $del = $pdo->prepare(
            'DELETE e FROM enrollments e
               JOIN users   u ON u.id = e.student_user_id
               JOIN courses c ON c.id = e.course_id
              WHERE e.student_user_id = ?
                AND e.course_id = ?
                AND u.tenant_id = ?
                AND c.tenant_id = ?'
        );
        $del->execute([$studentId, $courseId, $tenantId, $tenantId]);

        return 'ok';
    }

    /**
     * Atualiza período de acesso do aluno no curso (E17-01). Aceita NULL pra
     * limpar (volta a "imediato/ilimitado"). Validação `start < end` quando
     * ambos preenchidos é responsabilidade do caller. Ownership via JOIN duplo.
     *
     * @return bool true se alguma linha foi atualizada.
     */
    public static function updatePeriod(
        int $studentId,
        int $courseId,
        int $tenantId,
        ?string $accessStartsAt,
        ?string $accessEndsAt
    ): bool {
        $stmt = Database::pdo()->prepare(
            'UPDATE enrollments e
                JOIN users   u ON u.id = e.student_user_id
                JOIN courses c ON c.id = e.course_id
                SET e.access_starts_at = ?, e.access_ends_at = ?
              WHERE e.student_user_id = ?
                AND e.course_id = ?
                AND u.tenant_id = ?
                AND c.tenant_id = ?
                AND u.role = "student"'
        );
        $stmt->execute([
            $accessStartsAt,
            $accessEndsAt,
            $studentId,
            $courseId,
            $tenantId,
            $tenantId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Atualiza o status da matrícula (E16-03). Manual sempre, indicativo —
     * não muda regras de negócio (XP, progresso, ranking). Visível só pro
     * professor. Valida ownership do curso + aluno via JOIN.
     *
     * @param string $status Whitelist: 'active' | 'absent' | 'completed'.
     * @return bool true se alguma linha foi atualizada.
     */
    public static function updateStatus(int $studentId, int $courseId, int $tenantId, string $status): bool
    {
        if (!in_array($status, ['active', 'absent', 'completed'], true)) {
            return false;
        }
        $stmt = Database::pdo()->prepare(
            'UPDATE enrollments e
                JOIN users   u ON u.id = e.student_user_id
                JOIN courses c ON c.id = e.course_id
                SET e.status = ?
              WHERE e.student_user_id = ?
                AND e.course_id = ?
                AND u.tenant_id = ?
                AND c.tenant_id = ?
                AND u.role = "student"'
        );
        $stmt->execute([$status, $studentId, $courseId, $tenantId, $tenantId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Remove matrícula. Permitida mesmo em curso arquivado (o professor
     * pode limpar restos). Valida apenas que aluno e curso pertencem ao
     * tenant informado. Retorna true se alguma linha foi removida.
     */
    public static function delete(int $studentId, int $courseId, int $tenantId): bool
    {
        $stmt = Database::pdo()->prepare(
            'DELETE e FROM enrollments e
               JOIN users   u ON u.id = e.student_user_id
               JOIN courses c ON c.id = e.course_id
              WHERE e.student_user_id = ?
                AND e.course_id = ?
                AND u.tenant_id = ?
                AND c.tenant_id = ?
                AND u.role = "student"'
        );
        $stmt->execute([$studentId, $courseId, $tenantId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Ids de cursos em que o aluno JÁ está matriculado — usado para esconder
     * opções repetidas no multi-select de matrícula adicional.
     *
     * @return list<int>
     */
    public static function enrolledCourseIds(int $studentId, int $tenantId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT e.course_id
               FROM enrollments e
               JOIN users   u ON u.id = e.student_user_id
               JOIN courses c ON c.id = e.course_id
              WHERE e.student_user_id = ?
                AND u.tenant_id = ?
                AND c.tenant_id = ?
                AND u.role = "student"'
        );
        $stmt->execute([$studentId, $tenantId, $tenantId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Atualiza `last_access_at = NOW()` na matrícula do aluno no curso
     * (E14-00). Idempotente: múltiplos reloads só reescrevem o timestamp.
     * Filtra por `student_user_id + course_id` — não toca matrícula alheia.
     * Silencioso em erro (aluno sem matrícula, course_id inválido): retorna
     * void porque não vale travar a página só porque o tracking falhou.
     */
    public static function touchLastAccess(int $studentId, int $courseId): void
    {
        try {
            Database::pdo()
                ->prepare(
                    'UPDATE enrollments
                        SET last_access_at = NOW()
                      WHERE student_user_id = ? AND course_id = ?'
                )
                ->execute([$studentId, $courseId]);
        } catch (\Throwable) {
            // Loga? Por ora só swallow — o tracking é melhoria, não essencial.
        }
    }

    /**
     * Checa se o aluno já está matriculado no curso. Usado pra saber se
     * `Enrollment::create` vai criar nova linha ou absorver pelo UK
     * (INSERT IGNORE é idempotente). Assim `enrollBulk` só dispara
     * fanout de `enrollment` em matrícula realmente nova — re-enroll
     * não spamma o aluno (E10-05).
     */
    public static function isEnrolled(int $studentId, int $courseId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT 1 FROM enrollments
              WHERE student_user_id = ? AND course_id = ?
              LIMIT 1'
        );
        $stmt->execute([$studentId, $courseId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Ids de alunos ativos matriculados num curso — usado pra fanout de
     * notificações (E10-04 new_evaluation; E10-05 activity_new / submission_closed).
     * Valida que o curso pertence ao tenant e que os alunos são do mesmo.
     *
     * @return list<int>
     */
    public static function activeStudentIdsForCourse(int $courseId, int $tenantId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT e.student_user_id
               FROM enrollments e
               JOIN users   u ON u.id = e.student_user_id
               JOIN courses c ON c.id = e.course_id
              WHERE e.course_id = ?
                AND c.tenant_id = ?
                AND u.tenant_id = ?
                AND u.role   = "student"
                AND u.active = 1'
        );
        $stmt->execute([$courseId, $tenantId, $tenantId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
