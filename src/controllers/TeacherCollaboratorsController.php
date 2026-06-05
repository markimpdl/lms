<?php
declare(strict_types=1);

/**
 * Gestão de colaboradores de curso (E32-03 / F23 — ADR-033).
 *
 * Apenas o DONO do curso (owner do tenant) adiciona/remove colaboradores. O
 * dono é, por construção, o único professor cujo `current_tenant_id()` é igual
 * ao `tenant_id` do curso — então validar a posse via `Course::findForTenant`
 * com o tenant atual já garante "só o dono". Colaboradores (tenant diferente)
 * recebem 403.
 */
final class TeacherCollaboratorsController
{
    /** POST /teacher/courses/{id}/collaborators — adiciona por email. */
    public static function add(int $courseId, string $email): void
    {
        $tenantId = current_tenant_id();
        if ($tenantId === null) {
            http_response_code(403);
            require LMS_ROOT . '/src/templates/errors/403.php';
            exit;
        }

        $invitedBy = (int) (current_user()['id'] ?? 0);
        $email     = trim($email);
        $back      = '/teacher/courses/' . $courseId;

        $result = CourseCollaborator::add($courseId, $tenantId, $invitedBy, $email);

        if ($result === 'course_not_owned') {
            // Curso não pertence ao tenant do solicitante (não é o dono).
            http_response_code(403);
            require LMS_ROOT . '/src/templates/errors/403.php';
            exit;
        }
        if ($result === 'not_a_teacher') {
            flash('danger', __t('collab.err.not_teacher'));
            header('Location: ' . $back, true, 303);
            exit;
        }
        if ($result === 'self') {
            flash('danger', __t('collab.err.self'));
            header('Location: ' . $back, true, 303);
            exit;
        }
        if ($result === 'already') {
            flash('warning', __t('collab.err.already'));
            header('Location: ' . $back, true, 303);
            exit;
        }

        // 'ok' — notifica o professor convidado (sino + email no idioma dele).
        $teacher = CourseCollaborator::findActiveTeacherByEmail($email);
        $course  = Course::findForTenant($courseId, $tenantId);
        if ($teacher !== null && $course !== null) {
            NotificationService::fanout(
                NotificationService::EVENT_COURSE_SHARED,
                [$teacher['id']],
                (string) $course['name'],
                (string) (current_user()['name'] ?? ''),
                '/teacher/courses/' . $courseId,
                null,
                true
            );
        }
        flash('success', __t('collab.added', ['name' => $teacher['name'] ?? $email]));
        header('Location: ' . $back, true, 303);
        exit;
    }

    /** POST /teacher/courses/{id}/collaborators/{userId}/remove — remove (reversível). */
    public static function remove(int $courseId, int $userId): void
    {
        $tenantId = current_tenant_id();
        if ($tenantId === null) {
            http_response_code(403);
            require LMS_ROOT . '/src/templates/errors/403.php';
            exit;
        }

        // Só o dono: o curso tem de pertencer ao tenant atual.
        $course = Course::findForTenant($courseId, $tenantId);
        if ($course === null) {
            http_response_code(403);
            require LMS_ROOT . '/src/templates/errors/403.php';
            exit;
        }

        $back = '/teacher/courses/' . $courseId;
        $collab = CourseCollaborator::findCollaboratorUser($courseId, $userId);
        if ($collab === null) {
            // Já não é colaborador — no-op gracioso.
            header('Location: ' . $back, true, 303);
            exit;
        }

        CourseCollaborator::remove($courseId, $userId);

        // "Desfazer": guarda o email pra re-adicionar num clique (one-shot).
        $_SESSION['collab_undo'] = [
            'course_id' => $courseId,
            'email'     => $collab['email'],
            'name'      => $collab['name'],
        ];
        flash('success', __t('collab.removed', ['name' => $collab['name']]));
        header('Location: ' . $back, true, 303);
        exit;
    }
}
