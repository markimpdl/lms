<?php
declare(strict_types=1);

/**
 * Controller do currículo hierárquico do professor — CCs e CUs (E3-02 e E3-03).
 *
 * Hoje só atende CC. E3-03 estende com equivalentes para CompetenceUnit.
 * Tenant vem sempre de current_tenant_id() — nunca do input.
 */
final class TeacherCurriculumController
{
    /** Valida o nome de uma CC. Retorna chave i18n do erro ou null. */
    public static function validateName(string $name): ?string
    {
        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            return 'cc.form.err.name';
        }
        return null;
    }

    /** POST /teacher/cc/new — cria CC e redireciona para o show do curso. */
    public static function createCc(int $courseId, string $name): void
    {
        $tenantId = current_tenant_id();
        if ($tenantId === null) {
            http_response_code(403);
            require LMS_ROOT . '/src/templates/errors/403.php';
            exit;
        }

        $name = trim($name);
        $err = self::validateName($name);
        if ($err !== null) {
            flash('danger', __t($err));
            header('Location: /teacher/courses/' . $courseId, true, 303);
            exit;
        }

        $newId = CoreCompetency::create($courseId, $tenantId, $name);
        if ($newId === null) {
            // Curso não pertence ao tenant ou está arquivado.
            flash('danger', __t('cc.err.course_unavailable'));
            header('Location: /teacher/courses/' . $courseId, true, 303);
            exit;
        }

        flash('success', __t('cc.created', ['name' => $name]));
        header('Location: /teacher/courses/' . $courseId, true, 303);
        exit;
    }

    /** POST /teacher/cc/{id}/rename — renomeia CC. */
    public static function renameCc(int $ccId, string $name): void
    {
        $tenantId = current_tenant_id();
        if ($tenantId === null) {
            http_response_code(403);
            require LMS_ROOT . '/src/templates/errors/403.php';
            exit;
        }

        $cc = CoreCompetency::findForTenant($ccId, $tenantId);
        if ($cc === null) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }
        $courseId = (int) $cc['course_id'];

        $name = trim($name);
        $err = self::validateName($name);
        if ($err !== null) {
            flash('danger', __t($err));
            header('Location: /teacher/courses/' . $courseId, true, 303);
            exit;
        }

        $ok = CoreCompetency::rename($ccId, $tenantId, $name);
        if (!$ok) {
            flash('danger', __t('cc.err.course_unavailable'));
        } else {
            flash('success', __t('cc.renamed', ['name' => $name]));
        }
        header('Location: /teacher/courses/' . $courseId, true, 303);
        exit;
    }

    /** POST /teacher/cc/{id}/move-up|move-down — reordena. */
    public static function moveCc(int $ccId, string $direction): void
    {
        $tenantId = current_tenant_id();
        if ($tenantId === null) {
            http_response_code(403);
            require LMS_ROOT . '/src/templates/errors/403.php';
            exit;
        }

        $cc = CoreCompetency::findForTenant($ccId, $tenantId);
        if ($cc === null) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }
        $courseId = (int) $cc['course_id'];

        $ok = $direction === 'up'
            ? CoreCompetency::moveUp($ccId, $tenantId)
            : CoreCompetency::moveDown($ccId, $tenantId);

        // Silêncio quando ok: o reorder é uma ação visual — sem flash para
        // não poluir o feedback. Só emitimos flash em falha.
        if (!$ok) {
            flash('danger', __t('cc.err.move'));
        }
        header('Location: /teacher/courses/' . $courseId, true, 303);
        exit;
    }
}
