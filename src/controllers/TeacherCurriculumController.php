<?php
declare(strict_types=1);

/**
 * Controller do currículo hierárquico do professor — CCs e CUs (E3-02 e E3-03).
 *
 * E32 (F23/ADR-033): autoriza por ACESSO COMPARTILHADO. Em vez de
 * `current_tenant_id()`, resolve o curso da entidade e usa
 * `effective_authoring_tenant()` — devolve o tenant do dono quando o professor
 * atual (dono OU colaborador) pode editar. Para o dono, é idêntico ao
 * comportamento anterior. Dados de aluno seguem por tenant (outras telas).
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
        $tenantId = effective_authoring_tenant($courseId);
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

        course_audit($courseId, 'create', 'core_competency', $newId, $name);
        flash('success', __t('cc.created', ['name' => $name]));
        header('Location: /teacher/courses/' . $courseId, true, 303);
        exit;
    }

    /** POST /teacher/cc/{id}/rename — renomeia CC. */
    public static function renameCc(int $ccId, string $name): void
    {
        $courseId = CoreCompetency::courseIdOf($ccId);
        $tenantId = $courseId !== null ? effective_authoring_tenant($courseId) : null;
        if ($courseId === null || $tenantId === null) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }

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
            course_audit($courseId, 'update', 'core_competency', $ccId, $name);
            flash('success', __t('cc.renamed', ['name' => $name]));
        }
        header('Location: /teacher/courses/' . $courseId, true, 303);
        exit;
    }

    /** POST /teacher/cc/{id}/delete — exclusão com confirmação por nome (E3-05). */
    public static function deleteCc(int $ccId, string $expectedName): void
    {
        $courseId = CoreCompetency::courseIdOf($ccId);
        $tenantId = $courseId !== null ? effective_authoring_tenant($courseId) : null;
        if ($courseId === null || $tenantId === null) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }

        $cc = CoreCompetency::findForTenant($ccId, $tenantId);
        if ($cc === null) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }

        $result = CoreCompetency::delete($ccId, $tenantId, $expectedName);
        if ($result === 'name_mismatch') {
            flash('danger', __t('delete.err.name_mismatch'));
            header('Location: /teacher/courses/' . $courseId, true, 303);
            exit;
        }
        if ($result !== 'ok') {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }

        // Label capturado ANTES do delete (snapshot legível após a exclusão).
        course_audit($courseId, 'delete', 'core_competency', $ccId, (string) $cc['name']);
        flash('success', __t('cc.deleted', ['name' => $cc['name']]));
        header('Location: /teacher/courses/' . $courseId, true, 303);
        exit;
    }

    /** POST /teacher/cc/{id}/move-up|move-down — reordena. */
    public static function moveCc(int $ccId, string $direction): void
    {
        $courseId = CoreCompetency::courseIdOf($ccId);
        $tenantId = $courseId !== null ? effective_authoring_tenant($courseId) : null;
        if ($courseId === null || $tenantId === null) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }

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
    public static function createCu(int $courseId, int $ccId, string $name, int $workloadHours = 0): void
    {
        $tenantId = effective_authoring_tenant($courseId);
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

        $newId = CompetenceUnit::create($ccId, $tenantId, $name, max(0, $workloadHours));
        if ($newId === null) {
            flash('danger', __t('cu.err.cc_unavailable'));
            header('Location: ' . $backUrl, true, 303);
            exit;
        }

        course_audit($courseId, 'create', 'competence_unit', $newId, $name);
        flash('success', __t('cu.created', ['name' => $name]));
        header('Location: ' . $backUrl, true, 303);
        exit;
    }

    /** POST /teacher/cu/{id}/rename. `workloadHours=null` mantém o valor. */
    public static function renameCu(int $cuId, string $name, ?int $workloadHours = null): void
    {
        $courseId = CompetenceUnit::courseIdOf($cuId);
        $tenantId = $courseId !== null ? effective_authoring_tenant($courseId) : null;
        if ($courseId === null || $tenantId === null) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }

        $cu = CompetenceUnit::findForTenant($cuId, $tenantId);
        if ($cu === null) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }
        $ccId    = (int) $cu['core_competency_id'];
        $backUrl = '/teacher/courses/' . $courseId . '/cc/' . $ccId;

        $name = trim($name);
        $err = self::validateCuName($name);
        if ($err !== null) {
            flash('danger', __t($err));
            header('Location: ' . $backUrl, true, 303);
            exit;
        }

        $ok = CompetenceUnit::rename($cuId, $tenantId, $name, $workloadHours);
        if (!$ok) {
            flash('danger', __t('cu.err.cc_unavailable'));
        } else {
            course_audit($courseId, 'update', 'competence_unit', $cuId, $name);
            flash('success', __t('cu.renamed', ['name' => $name]));
        }
        header('Location: ' . $backUrl, true, 303);
        exit;
    }

    /** POST /teacher/cu/{id}/delete — exclusão com confirmação por nome (E3-05). */
    public static function deleteCu(int $cuId, string $expectedName): void
    {
        $courseId = CompetenceUnit::courseIdOf($cuId);
        $tenantId = $courseId !== null ? effective_authoring_tenant($courseId) : null;
        if ($courseId === null || $tenantId === null) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }

        $cu = CompetenceUnit::findForTenant($cuId, $tenantId);
        if ($cu === null) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }
        $ccId    = (int) $cu['core_competency_id'];
        $backUrl = '/teacher/courses/' . $courseId . '/cc/' . $ccId;

        $result = CompetenceUnit::delete($cuId, $tenantId, $expectedName);
        if ($result === 'name_mismatch') {
            flash('danger', __t('delete.err.name_mismatch'));
            header('Location: ' . $backUrl, true, 303);
            exit;
        }
        if ($result !== 'ok') {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }

        // Label capturado ANTES do delete (snapshot legível após a exclusão).
        course_audit($courseId, 'delete', 'competence_unit', $cuId, (string) $cu['name']);
        flash('success', __t('cu.deleted', ['name' => $cu['name']]));
        header('Location: ' . $backUrl, true, 303);
        exit;
    }

    /** POST /teacher/cu/{id}/move-up|move-down. */
    public static function moveCu(int $cuId, string $direction): void
    {
        $courseId = CompetenceUnit::courseIdOf($cuId);
        $tenantId = $courseId !== null ? effective_authoring_tenant($courseId) : null;
        if ($courseId === null || $tenantId === null) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }

        $cu = CompetenceUnit::findForTenant($cuId, $tenantId);
        if ($cu === null) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }
        $ccId = (int) $cu['core_competency_id'];

        $ok = $direction === 'up'
            ? CompetenceUnit::moveUp($cuId, $tenantId)
            : CompetenceUnit::moveDown($cuId, $tenantId);

        if (!$ok) {
            flash('danger', __t('cu.err.move'));
        }
        header('Location: /teacher/courses/' . $courseId . '/cc/' . $ccId, true, 303);
        exit;
    }

    // =========================================================================
    // Cópia de conteúdo (E31 / F22 — ADR-034)
    //
    // Mantida owner-scoped (current_tenant_id) nesta fase: copiar a partir de
    // cursos compartilhados será habilitado num polimento posterior do E32.
    // =========================================================================

    /**
     * POST /teacher/cc/{id}/copy — copia a CC (com suas CUs) para outro curso.
     * Destino tem de ser um curso do tenant e não pode estar arquivado.
     * Cópia física e independente; redireciona para o curso de destino.
     */
    public static function copyCc(int $ccId, int $targetCourseId): void
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
        $backUrl = '/teacher/courses/' . (int) $cc['course_id'];

        $targetCourse = Course::findForTenant($targetCourseId, $tenantId);
        if ($targetCourse === null) {
            flash('danger', __t('copy.err.target_invalid'));
            header('Location: ' . $backUrl, true, 303);
            exit;
        }
        if ((int) $targetCourse['archived'] === 1) {
            flash('danger', __t('copy.err.target_archived'));
            header('Location: ' . $backUrl, true, 303);
            exit;
        }

        $newCcId = CourseCopyService::copyCoreCompetence($ccId, $targetCourseId, $tenantId);
        if ($newCcId === null) {
            flash('danger', __t('copy.err.failed'));
            header('Location: ' . $backUrl, true, 303);
            exit;
        }

        // Cópia cria conteúdo novo no curso de destino — registra como create.
        course_audit($targetCourseId, 'create', 'core_competency', $newCcId, (string) $cc['name']);
        flash('success', __t('cc.copied', [
            'name'   => (string) $cc['name'],
            'course' => (string) $targetCourse['name'],
        ]));
        header('Location: /teacher/courses/' . $targetCourseId, true, 303);
        exit;
    }

    /**
     * POST /teacher/cu/{id}/copy — copia a CU para uma CC de destino.
     * Destino tem de ser uma CC do tenant cujo curso não esteja arquivado.
     * Cópia física e independente; redireciona para a CC de destino.
     */
    public static function copyCu(int $cuId, int $targetCcId): void
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
        $backUrl = '/teacher/courses/' . (int) $cu['course_id'] . '/cc/' . (int) $cu['core_competency_id'];

        $targetCc = CoreCompetency::findForTenant($targetCcId, $tenantId);
        if ($targetCc === null) {
            flash('danger', __t('copy.err.target_invalid'));
            header('Location: ' . $backUrl, true, 303);
            exit;
        }
        if ((int) $targetCc['course_archived'] === 1) {
            flash('danger', __t('copy.err.target_archived'));
            header('Location: ' . $backUrl, true, 303);
            exit;
        }

        $newCuId = CourseCopyService::copyCompetenceUnit($cuId, $targetCcId, $tenantId);
        if ($newCuId === null) {
            flash('danger', __t('copy.err.failed'));
            header('Location: ' . $backUrl, true, 303);
            exit;
        }

        // Cópia cria conteúdo novo na CC de destino — registra como create.
        course_audit((int) $targetCc['course_id'], 'create', 'competence_unit', $newCuId, (string) $cu['name']);
        flash('success', __t('cu.copied', [
            'name' => (string) $cu['name'],
            'cc'   => (string) $targetCc['name'],
        ]));
        header('Location: /teacher/courses/' . (int) $targetCc['course_id'] . '/cc/' . $targetCcId, true, 303);
        exit;
    }
}
