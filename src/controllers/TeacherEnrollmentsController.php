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
    public static function enrollMany(
        int $studentId,
        array $courseIds,
        ?string $accessStartsAt = null,
        ?string $accessEndsAt = null
    ): void {
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

        // Período (E17-01) — quando o professor preenche, vale pra TODOS os
        // cursos selecionados nesse POST. Pra editar individualmente depois,
        // usar o endpoint dedicado em /teacher/courses/{id}/enrollment/.../period.
        $stats = self::enrollBulk($studentId, $courseIds, $tenantId, $accessStartsAt, $accessEndsAt);

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
    public static function enrollBulk(
        int $studentId,
        array $courseIds,
        int $tenantId,
        ?string $accessStartsAt = null,
        ?string $accessEndsAt = null
    ): array {
        $stats = ['ok' => 0, 'course_archived' => 0, 'student_inactive' => 0, 'wrong_tenant' => 0];
        foreach ($courseIds as $cid) {
            $cid = (int) $cid;
            if ($cid <= 0) {
                continue;
            }
            // Checa antes pra distinguir "nova matrícula" de "já existia" — só
            // notifica no primeiro caso (E10-05). `Enrollment::create` usa
            // INSERT IGNORE e retorna 'ok' pros dois.
            $wasEnrolled = Enrollment::isEnrolled($studentId, $cid);
            $result      = Enrollment::create($studentId, $cid, $tenantId, $accessStartsAt, $accessEndsAt);
            $stats[$result] = ($stats[$result] ?? 0) + 1;

            if ($result === 'ok' && !$wasEnrolled) {
                self::fanoutEnrollment($studentId, $cid, $tenantId);
            }
        }
        return $stats;
    }

    /**
     * Fanout `enrollment` (email + sino, E10-05). Separado pra manter
     * `enrollBulk` legível. Idioma do email via `courses.language` pelo
     * courseId. Noop se curso sumir entre create e fanout.
     */
    private static function fanoutEnrollment(int $studentId, int $courseId, int $tenantId): void
    {
        $course = Course::findForTenant($courseId, $tenantId);
        if ($course === null) {
            return;
        }
        NotificationService::fanout(
            NotificationService::EVENT_ENROLLMENT,
            [$studentId],
            (string) $course['name'],
            null,
            '/student/course/' . $courseId,
            $courseId
        );
    }
}
