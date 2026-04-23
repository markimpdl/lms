<?php
declare(strict_types=1);

/**
 * Matrícula de aluno em curso (E4-02).
 *
 * Todas as operações resolvem o tenant via `current_tenant_id()` e validam
 * que aluno e curso pertencem a esse tenant. Erros de tenant se confundem
 * com 404 amigável (não vazam presença cross-tenant).
 *
 * Os endpoints são orquestradores finos — a regra de validação (aluno ativo,
 * curso não arquivado, mesma tenant) mora no `Enrollment::create`. Este
 * controller só traduz códigos de retorno em flashes i18n + 303 PRG.
 */
final class TeacherEnrollmentsController
{
    /**
     * POST /teacher/students/{id}/enroll — matricular em 1+ cursos.
     * Aceita `course_ids[]` como array de inteiros; cada curso é validado
     * individualmente (um erro não aborta os demais).
     */
    public static function enrollMany(int $studentId, array $courseIds): void
    {
        $tenantId = current_tenant_id();
        if ($tenantId === null) {
            http_response_code(403);
            require LMS_ROOT . '/src/templates/errors/403.php';
            exit;
        }

        $student = Student::findForTenant($studentId, $tenantId);
        if ($student === null) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }

        $stats = self::enrollBulk($studentId, $courseIds, $tenantId);

        if ($stats['ok'] > 0) {
            flash('success', __t('enrollments.created_count', ['count' => $stats['ok']]));
        }
        if ($stats['course_archived'] > 0) {
            flash('warning', __t('enrollments.skipped_archived', ['count' => $stats['course_archived']]));
        }
        if ($stats['student_inactive'] > 0) {
            flash('warning', __t('enrollments.skipped_student_inactive'));
        }
        if ($stats['wrong_tenant'] > 0) {
            flash('warning', __t('enrollments.skipped_wrong_tenant', ['count' => $stats['wrong_tenant']]));
        }
        if ($stats['ok'] === 0 && $stats['course_archived'] === 0
            && $stats['student_inactive'] === 0 && $stats['wrong_tenant'] === 0) {
            flash('info', __t('enrollments.none_selected'));
        }

        header('Location: /teacher/students/' . $studentId, true, 303);
        exit;
    }

    /**
     * POST /teacher/students/{id}/unenroll/{course_id} — remove matrícula.
     */
    public static function unenroll(int $studentId, int $courseId): void
    {
        $tenantId = current_tenant_id();
        if ($tenantId === null) {
            http_response_code(403);
            require LMS_ROOT . '/src/templates/errors/403.php';
            exit;
        }

        $student = Student::findForTenant($studentId, $tenantId);
        if ($student === null) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }

        $removed = Enrollment::delete($studentId, $courseId, $tenantId);
        if ($removed) {
            flash('success', __t('enrollments.removed'));
        } else {
            flash('info', __t('enrollments.not_found'));
        }

        header('Location: /teacher/students/' . $studentId, true, 303);
        exit;
    }

    /**
     * Matricula aluno em uma lista de cursos; agrega contadores por resultado.
     * Também usado pelo fluxo de cadastro de aluno (new.php) para matricular
     * já no mesmo POST. Não emite flash — quem chama decide.
     *
     * @return array{ok:int,course_archived:int,student_inactive:int,wrong_tenant:int}
     */
    public static function enrollBulk(int $studentId, array $courseIds, int $tenantId): array
    {
        $stats = ['ok' => 0, 'course_archived' => 0, 'student_inactive' => 0, 'wrong_tenant' => 0];
        foreach ($courseIds as $cid) {
            $cid = (int) $cid;
            if ($cid <= 0) {
                continue;
            }
            $result = Enrollment::create($studentId, $cid, $tenantId);
            $stats[$result] = ($stats[$result] ?? 0) + 1;
        }
        return $stats;
    }
}
