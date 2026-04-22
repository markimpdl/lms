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

    // =========================================================================
    // Competence Unit (E3-03)
    // =========================================================================

    /** Valida o nome de uma CU. Retorna chave i18n do erro ou null. */
    public static function validateCuName(string $name): ?string
    {
        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            return 'cu.form.err.name';
        }
        return null;
    }

    /** POST /teacher/cu/new — cria CU e redireciona para a página da CC. */
    public static function createCu(int $courseId, int $ccId, string $name): void
    {
        $tenantId = current_tenant_id();
        if ($tenantId === null) {
            http_response_code(403);
            require LMS_ROOT . '/src/templates/errors/403.php';
            exit;
        }

        $backUrl = '/teacher/courses/' . $courseId . '/cc/' . $ccId;

        $name = trim($name);
        $err = self::validateCuName($name);
        if ($err !== null) {
            flash('danger', __t($err));
            header('Location: ' . $backUrl, true, 303);
            exit;
        }

        $newId = CompetenceUnit::create($ccId, $tenantId, $name);
        if ($newId === null) {
            flash('danger', __t('cu.err.cc_unavailable'));
            header('Location: ' . $backUrl, true, 303);
            exit;
        }

        flash('success', __t('cu.created', ['name' => $name]));
        header('Location: ' . $backUrl, true, 303);
        exit;
    }

    /** POST /teacher/cu/{id}/rename. */
    public static function renameCu(int $cuId, string $name): void
    {
        $tenantId = current_tenant_id();
        if ($tenantId === null) {
            http_response_code(403);
            require LMS_ROOT . '/src/templates/errors/403.php';
            exit;
        }

        $cu = CompetenceUnit::findForTenant($cuId, $tenantId);
        if ($cu === null) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }
        $courseId = (int) $cu['course_id'];
        $ccId     = (int) $cu['core_competency_id'];
        $backUrl  = '/teacher/courses/' . $courseId . '/cc/' . $ccId;

        $name = trim($name);
        $err = self::validateCuName($name);
        if ($err !== null) {
            flash('danger', __t($err));
            header('Location: ' . $backUrl, true, 303);
            exit;
        }

        $ok = CompetenceUnit::rename($cuId, $tenantId, $name);
        if (!$ok) {
            flash('danger', __t('cu.err.cc_unavailable'));
        } else {
            flash('success', __t('cu.renamed', ['name' => $name]));
        }
        header('Location: ' . $backUrl, true, 303);
        exit;
    }

    /** POST /teacher/cu/{id}/move-up|move-down. */
    public static function moveCu(int $cuId, string $direction): void
    {
        $tenantId = current_tenant_id();
        if ($tenantId === null) {
            http_response_code(403);
            require LMS_ROOT . '/src/templates/errors/403.php';
            exit;
        }

        $cu = CompetenceUnit::findForTenant($cuId, $tenantId);
        if ($cu === null) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }
        $courseId = (int) $cu['course_id'];
        $ccId     = (int) $cu['core_competency_id'];

        $ok = $direction === 'up'
            ? CompetenceUnit::moveUp($cuId, $tenantId)
            : CompetenceUnit::moveDown($cuId, $tenantId);

        if (!$ok) {
            flash('danger', __t('cu.err.move'));
        }
        header('Location: /teacher/courses/' . $courseId . '/cc/' . $ccId, true, 303);
        exit;
    }
}
