<?php
declare(strict_types=1);

/**
 * Atribuição/remoção de alunos em grupos (E4-04).
 *
 * Todas as operações resolvem o tenant via `current_tenant_id()` e validam
 * que aluno e grupo pertencem a esse tenant. Erros de tenant se confundem
 * com 404 amigável (não vazam presença cross-tenant).
 *
 * Endpoints orquestradores finos — a regra de validação (aluno ativo, mesma
 * tenant) mora no `GroupMember::add`. Este controller traduz códigos de
 * retorno em flashes i18n + 303 PRG.
 */
final class TeacherGroupMembersController
{
    /**
     * POST /teacher/groups/{id}/add-member — adiciona 1+ alunos ao grupo.
     * Aceita `student_ids[]` como array de inteiros; cada aluno é validado
     * individualmente (um erro não aborta os demais).
     */
    public static function addMany(int $groupId, array $studentIds): void
    {
        $tenantId = current_tenant_id();
        if ($tenantId === null) {
            http_response_code(403);
            require LMS_ROOT . '/src/templates/errors/403.php';
            exit;
        }

        $group = Group::findForTenant($groupId, $tenantId);
        if ($group === null) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }

        $stats = self::addBulk($groupId, $studentIds, $tenantId);

        if ($stats['ok'] > 0) {
            flash('success', __t('group_members.added_count', ['count' => $stats['ok']]));
        }
        if ($stats['student_inactive'] > 0) {
            flash('warning', __t('group_members.skipped_student_inactive', ['count' => $stats['student_inactive']]));
        }
        if ($stats['wrong_tenant'] > 0) {
            flash('warning', __t('group_members.skipped_wrong_tenant', ['count' => $stats['wrong_tenant']]));
        }
        if ($stats['ok'] === 0 && $stats['student_inactive'] === 0 && $stats['wrong_tenant'] === 0) {
            flash('info', __t('group_members.none_selected'));
        }

        header('Location: /teacher/groups/' . $groupId, true, 303);
        exit;
    }

    /**
     * POST /teacher/groups/{id}/remove-member/{student_id} — remove membership.
     */
    public static function remove(int $groupId, int $studentId): void
    {
        $tenantId = current_tenant_id();
        if ($tenantId === null) {
            http_response_code(403);
            require LMS_ROOT . '/src/templates/errors/403.php';
            exit;
        }

        $group = Group::findForTenant($groupId, $tenantId);
        if ($group === null) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }

        $removed = GroupMember::remove($groupId, $studentId, $tenantId);
        if ($removed) {
            flash('success', __t('group_members.removed'));
        } else {
            flash('info', __t('group_members.not_found'));
        }

        header('Location: /teacher/groups/' . $groupId, true, 303);
        exit;
    }

    /**
     * POST /teacher/students/{id}/assign-groups — espelho da addMany, mas
     * para o fluxo "atribuir aluno a N grupos" a partir da tela do aluno.
     * Redireciona de volta para a ficha do aluno.
     */
    public static function assignStudentToGroups(int $studentId, array $groupIds): void
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

        $stats = ['ok' => 0, 'student_inactive' => 0, 'wrong_tenant' => 0];
        foreach ($groupIds as $gid) {
            $gid = (int) $gid;
            if ($gid <= 0) {
                continue;
            }
            $result = GroupMember::add($gid, $studentId, $tenantId);
            $stats[$result] = ($stats[$result] ?? 0) + 1;
        }

        if ($stats['ok'] > 0) {
            flash('success', __t('group_members.assigned_count', ['count' => $stats['ok']]));
        }
        if ($stats['student_inactive'] > 0) {
            flash('warning', __t('group_members.skipped_student_inactive', ['count' => $stats['student_inactive']]));
        }
        if ($stats['wrong_tenant'] > 0) {
            flash('warning', __t('group_members.skipped_wrong_tenant', ['count' => $stats['wrong_tenant']]));
        }
        if ($stats['ok'] === 0 && $stats['student_inactive'] === 0 && $stats['wrong_tenant'] === 0) {
            flash('info', __t('group_members.none_selected'));
        }

        header('Location: /teacher/students/' . $studentId, true, 303);
        exit;
    }

    /**
     * POST /teacher/students/{id}/unassign-group/{group_id} — remove aluno
     * de um grupo, redirecionando para a ficha do aluno.
     */
    public static function unassignStudentFromGroup(int $studentId, int $groupId): void
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

        $removed = GroupMember::remove($groupId, $studentId, $tenantId);
        if ($removed) {
            flash('success', __t('group_members.removed'));
        } else {
            flash('info', __t('group_members.not_found'));
        }

        header('Location: /teacher/students/' . $studentId, true, 303);
        exit;
    }

    /**
     * Adiciona aluno em uma lista de grupos; agrega contadores por resultado.
     * Usado pelo fluxo da tela do grupo.
     *
     * @return array{ok:int,student_inactive:int,wrong_tenant:int}
     */
    public static function addBulk(int $groupId, array $studentIds, int $tenantId): array
    {
        $stats = ['ok' => 0, 'student_inactive' => 0, 'wrong_tenant' => 0];
        foreach ($studentIds as $sid) {
            $sid = (int) $sid;
            if ($sid <= 0) {
                continue;
            }
            $result = GroupMember::add($groupId, $sid, $tenantId);
            $stats[$result] = ($stats[$result] ?? 0) + 1;
        }
        return $stats;
    }
}
