<?php
declare(strict_types=1);

/**
 * Controller do CRUD de Cursos do professor (E3-01).
 *
 * Regras de validação e orquestração ficam aqui; queries no Course model.
 * Tenant vem sempre de `current_tenant_id()` — nunca do input.
 */
final class TeacherCoursesController
{
    /** Anos aceitos no form (ADR-023 cobre 1900-2100; form restringe à janela útil). */
    public const YEAR_MIN = 2020;
    public const YEAR_MAX = 2035;

    /**
     * Valida input e devolve ou (array vazio de erros) ou mapa field → i18n.
     *
     * `$isActvet` controla a aceitação do `grading_mode='learning_outcomes'`
     * — defesa server-side: tenant não-Actvet sempre força `'grade'`,
     * mesmo que o POST tente outro valor (E25-01).
     *
     * @param array<string,string> $input
     * @return array{errors: array<string,string>, data: array<string,mixed>}
     */
    public static function validate(array $input, bool $isActvet = false): array
    {
        $name        = trim((string) ($input['name']        ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $year        = (int)          ($input['year']        ?? 0);
        $language    = (string)       ($input['language']    ?? 'pt');

        // E19-01: 3 modos de progressao. Defaults sequenciais — alinha com
        // schema. Whitelist estrita rejeita valores fora dos ENUMs.
        $ccMode       = (string) ($input['cc_mode']       ?? 'sequential');
        $activityMode = (string) ($input['activity_mode'] ?? 'sequential');
        $evalAfter    = !empty($input['eval_after_activities']) ? 1 : 0;

        // E36: structure_version. 1 = classico (V1), 2 = trilha de licoes (V2).
        // Whitelist estrita no mesmo padrao dos modos de progressao: valor fora
        // do par cai silencioso pro classico (defesa server-side). So eh lido na
        // criacao — `update()` nao toca na coluna, o formato eh imutavel.
        $structureVersion = (int) ($input['structure_version'] ?? 1);
        if ($structureVersion !== 1 && $structureVersion !== 2) {
            $structureVersion = 1;
        }

        // E25-01: grading_mode (default 'grade'). LO mode só pra Actvet.
        $gradingMode = (string) ($input['grading_mode'] ?? ($isActvet ? 'learning_outcomes' : 'grade'));
        if (!in_array($gradingMode, ['grade', 'learning_outcomes'], true)) {
            $gradingMode = 'grade';
        }
        if (!$isActvet) {
            $gradingMode = 'grade';
        }

        // E26-03: report_mode (default 'disabled'). 'skill_hub' só pra
        // Actvet+LO. POST manipulado em outros casos cai silencioso pra
        // 'disabled' (defesa server-side).
        $reportMode = (string) ($input['report_mode'] ?? 'disabled');
        if (!in_array($reportMode, ['disabled', 'skill_hub'], true)) {
            $reportMode = 'disabled';
        }
        if (!$isActvet || $gradingMode !== 'learning_outcomes') {
            $reportMode = 'disabled';
        }

        $errors = [];
        if (mb_strlen($name) < 3 || mb_strlen($name) > 150) {
            $errors['name'] = 'courses.form.err.name';
        }
        if (mb_strlen($description) > 2000) {
            $errors['description'] = 'courses.form.err.description';
        }
        if ($year < self::YEAR_MIN || $year > self::YEAR_MAX) {
            $errors['year'] = 'courses.form.err.year';
        }
        if ($language !== 'pt' && $language !== 'en') {
            $errors['language'] = 'courses.form.err.language';
        }
        if ($ccMode !== 'sequential' && $ccMode !== 'free') {
            $errors['cc_mode'] = 'courses.form.err.cc_mode';
        }
        if ($activityMode !== 'sequential' && $activityMode !== 'free') {
            $errors['activity_mode'] = 'courses.form.err.activity_mode';
        }

        return [
            'errors' => $errors,
            'data'   => [
                'name'                  => $name,
                'description'           => $description,
                'year'                  => $year,
                'language'              => $language,
                'structure_version'     => $structureVersion,
                'cc_mode'               => $ccMode,
                'activity_mode'         => $activityMode,
                'eval_after_activities' => $evalAfter,
                'grading_mode'          => $gradingMode,
                'report_mode'           => $reportMode,
            ],
        ];
    }

    /**
     * POST /teacher/courses/new → cria curso e redireciona.
     * Retorna erros (mapa field→i18n) para o caller re-renderizar o form.
     *
     * @return array<string,string>
     */
    public static function create(array $input): array
    {
        $tenantId = current_tenant_id();
        if ($tenantId === null) {
            http_response_code(403);
            require LMS_ROOT . '/src/templates/errors/403.php';
            exit;
        }

        $tenant   = Tenant::findById($tenantId);
        $isActvet = $tenant !== null && (int) ($tenant['is_actvet'] ?? 0) === 1;

        $v = self::validate($input, $isActvet);
        if ($v['errors'] !== []) {
            return $v['errors'];
        }

        $courseId = Course::create($tenantId, $v['data']);

        flash('success', __t('courses.created', ['name' => $v['data']['name']]));
        header('Location: /teacher/courses/' . $courseId, true, 303);
        exit;
    }

    /**
     * POST /teacher/courses/{id} → atualiza curso existente.
     * Bloqueia edição de curso arquivado (retorna erro genérico).
     *
     * @return array<string,string>
     */
    public static function update(int $courseId, array $input): array
    {
        // E32: editar configurações do curso é autoria compartilhada (dono OU
        // colaborador). effective_authoring_tenant devolve o tenant do dono.
        $tenantId = effective_authoring_tenant($courseId);
        if ($tenantId === null) {
            http_response_code(403);
            require LMS_ROOT . '/src/templates/errors/403.php';
            exit;
        }

        $course = Course::findForTenant($courseId, $tenantId);
        if ($course === null) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }
        if ((int) $course['archived'] === 1) {
            flash('error', __t('courses.edit.blocked_archived'));
            header('Location: /teacher/courses/' . $courseId, true, 303);
            exit;
        }

        $tenant   = Tenant::findById($tenantId);
        $isActvet = $tenant !== null && (int) ($tenant['is_actvet'] ?? 0) === 1;

        $v = self::validate($input, $isActvet);
        if ($v['errors'] !== []) {
            return $v['errors'];
        }

        Course::update($courseId, $tenantId, $v['data']);

        flash('success', __t('courses.updated', ['name' => $v['data']['name']]));
        header('Location: /teacher/courses/' . $courseId, true, 303);
        exit;
    }

    /**
     * POST /teacher/courses/{id}/delete → exclusão com confirmação por nome (E3-05).
     * Cascade do schema cuida de CCs, CUs, conteúdo, atividades, avaliações
     * e submissões. Redireciona para listagem em sucesso.
     */
    public static function delete(int $courseId, string $expectedName): void
    {
        $tenantId = current_tenant_id();
        if ($tenantId === null) {
            http_response_code(403);
            require LMS_ROOT . '/src/templates/errors/403.php';
            exit;
        }

        $course = Course::findForTenant($courseId, $tenantId);
        if ($course === null) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }

        $result = Course::delete($courseId, $tenantId, $expectedName);
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

        flash('success', __t('courses.deleted', ['name' => $course['name']]));
        header('Location: /teacher/courses', true, 303);
        exit;
    }

    /**
     * POST /teacher/courses/{id}/toggle-archive → alterna archived.
     */
    public static function toggleArchive(int $courseId): void
    {
        $tenantId = current_tenant_id();
        if ($tenantId === null) {
            http_response_code(403);
            require LMS_ROOT . '/src/templates/errors/403.php';
            exit;
        }

        $course = Course::findForTenant($courseId, $tenantId);
        if ($course === null) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }

        if ((int) $course['archived'] === 1) {
            Course::restore($courseId, $tenantId);
            flash('success', __t('courses.restored', ['name' => $course['name']]));
        } else {
            Course::archive($courseId, $tenantId);
            flash('success', __t('courses.archived', ['name' => $course['name']]));
        }

        header('Location: /teacher/courses/' . $courseId, true, 303);
        exit;
    }

    /**
     * POST /teacher/courses/{id}/duplicate → cópia profunda do curso (E31-02).
     * Gera uma nova turma a partir da base existente: só estrutura + conteúdo,
     * sem alunos/entregas/notas/XP (ADR-034). Redireciona para o curso novo.
     */
    public static function duplicate(int $courseId): void
    {
        $tenantId = current_tenant_id();
        if ($tenantId === null) {
            http_response_code(403);
            require LMS_ROOT . '/src/templates/errors/403.php';
            exit;
        }

        $course = Course::findForTenant($courseId, $tenantId);
        if ($course === null) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }

        $newId = CourseCopyService::duplicateCourse($courseId, $tenantId);
        if ($newId === null) {
            flash('danger', __t('courses.err.duplicate_failed'));
            header('Location: /teacher/courses/' . $courseId, true, 303);
            exit;
        }

        flash('success', __t('courses.duplicated', ['name' => $course['name']]));
        header('Location: /teacher/courses/' . $newId, true, 303);
        exit;
    }
}
