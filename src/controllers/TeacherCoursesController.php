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
     * @param array<string,string> $input
     * @return array{errors: array<string,string>, data: array<string,mixed>}
     */
    public static function validate(array $input): array
    {
        $name        = trim((string) ($input['name']        ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $year        = (int)          ($input['year']        ?? 0);
        $language    = (string)       ($input['language']    ?? 'pt');

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

        return [
            'errors' => $errors,
            'data'   => [
                'name'        => $name,
                'description' => $description,
                'year'        => $year,
                'language'    => $language,
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

        $v = self::validate($input);
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
            flash('error', __t('courses.edit.blocked_archived'));
            header('Location: /teacher/courses/' . $courseId, true, 303);
            exit;
        }

        $v = self::validate($input);
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
}
