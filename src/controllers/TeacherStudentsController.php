<?php
declare(strict_types=1);

/**
 * Ações do professor sobre alunos do próprio tenant (E4-01).
 *
 * Todas as operações resolvem o tenant via `current_tenant_id()` — nunca do
 * input — e validam que o aluno-alvo pertence a esse tenant (404 amigável
 * caso contrário, para não vazar presença cross-tenant).
 *
 * Transients de sessão:
 *   $_SESSION['student_creds_once']           — credenciais pós-cadastro
 *
 * Validados por `student_id` antes de exibir para não vazarem entre telas.
 */
final class TeacherStudentsController
{
    /** Input validado pelo service — caller re-renderiza o form em erro. */
    public static function create(array $input, bool $sendByEmail): array
    {
        $tenantId = current_tenant_id();
        if ($tenantId === null) {
            http_response_code(403);
            require LMS_ROOT . '/src/templates/errors/403.php';
            exit;
        }

        $result = StudentProvisioningService::create($tenantId, $input);
        if ($result['errors'] !== []) {
            return $result['errors'];
        }

        $student = $result['student'];
        $mailDelivered = false;

        if ($sendByEmail && Mailer::isConfigured()) {
            self::sendWelcomeEmail([
                'name'     => (string) ($input['name'] ?? ''),
                'email'    => $student['email'],
                'password' => $student['password'],
                'language' => $student['language'],
            ]);
            $mailDelivered = true;
        }

        if (!$mailDelivered) {
            // Credenciais vão para transient vinculado ao student_id para
            // a tela de detalhe exibir uma vez e drenar.
            $_SESSION['student_creds_once'] = [
                'student_id' => $student['id'],
                'name'       => (string) ($input['name'] ?? ''),
                'email'      => $student['email'],
                'password'   => $student['password'],
                'reason'     => $sendByEmail ? 'smtp_unavailable' : 'teacher_opted_out',
            ];
        }

        flash('success', __t('students.created', ['name' => $input['name'] ?? '']));
        header('Location: /teacher/students/' . $student['id'], true, 303);
        exit;
    }

    /**
     * POST /teacher/students/{id} — atualiza nome e idioma. Email é imutável
     * (ADR-021) e nunca é aceito no input.
     *
     * @return array<string,string>
     */
    public static function update(int $studentId, array $input): array
    {
        $tenantId = current_tenant_id();
        if ($tenantId === null) {
            http_response_code(403);
            require LMS_ROOT . '/src/templates/errors/403.php';
            exit;
        }

        $name = trim((string) ($input['name']     ?? ''));
        $lang = (string)      ($input['language'] ?? '');

        $errors = [];
        if (mb_strlen($name) < 3 || mb_strlen($name) > 120) {
            $errors['name'] = 'students.form.err.name';
        }
        if ($lang !== 'pt' && $lang !== 'en') {
            $errors['language'] = 'students.form.err.language';
        }
        if ($errors !== []) {
            return $errors;
        }

        $student = Student::findForTenant($studentId, $tenantId);
        if ($student === null) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }

        Student::update($studentId, $tenantId, ['name' => $name, 'language' => $lang]);

        flash('success', __t('students.edit.updated', ['name' => $name]));
        header('Location: /teacher/students/' . $studentId, true, 303);
        exit;
    }

    /**
     * POST /teacher/students/{id}/toggle — alterna `active`. Middleware de
     * E1-05 expulsa sessões vivas na próxima request quando fica 0.
     */
    public static function toggleActive(int $studentId): void
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

        $next = Student::toggleActive($studentId, $tenantId);

        flash(
            'success',
            __t(
                $next === 1 ? 'students.reactivated' : 'students.deactivated',
                ['name' => (string) $student['name']]
            )
        );
        header('Location: /teacher/students/' . $studentId, true, 303);
        exit;
    }

    /**
     * POST /teacher/students/{id}/delete — exclusão definitiva com
     * confirmação por digitação do email (padrão E3-05).
     */
    public static function delete(int $studentId, string $expectedEmail): void
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

        $result = Student::delete($studentId, $tenantId, $expectedEmail);
        if ($result === 'email_mismatch') {
            flash('danger', __t('delete.err.name_mismatch'));
            header('Location: /teacher/students/' . $studentId, true, 303);
            exit;
        }
        if ($result !== 'ok') {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }

        flash('success', __t('students.deleted', ['name' => (string) $student['name']]));
        header('Location: /teacher/students', true, 303);
        exit;
    }

    /**
     * Monta e entrega o email de boas-vindas no idioma do aluno.
     *
     * @param array{name:string, email:string, password:string, language:string} $student
     */
    private static function sendWelcomeEmail(array $student): void
    {
        $lang = in_array($student['language'], ['pt', 'en'], true) ? $student['language'] : 'pt';
        $path = LMS_ROOT . '/src/templates/email/student_welcome.' . $lang . '.php';

        $base = rtrim((string) ($GLOBALS['__ENV']['APP_BASE_URL'] ?? ''), '/');
        $data = [
            'name'      => $student['name'],
            'email'     => $student['email'],
            'password'  => $student['password'],
            'login_url' => $base . '/login',
        ];

        /** @var array{subject:string, html:string, text:string} $tpl */
        $tpl = (static function (string $tplPath, array $data): array {
            return require $tplPath;
        })($path, $data);

        Mailer::send($student['email'], $tpl['subject'], $tpl['html'], $tpl['text']);
    }
}
