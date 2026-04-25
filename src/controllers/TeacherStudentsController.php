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
 *   $_SESSION['student_password_reset_once']  — senha pós-reset (E4-05)
 *
 * Validados por `student_id` antes de exibir para não vazarem entre telas.
 */
final class TeacherStudentsController
{
    /**
     * Input validado pelo service — caller re-renderiza o form em erro.
     *
     * Se `$courseIds` vier com ids, dispara matrícula em lote logo após o
     * cadastro (E4-02). Retorna só os erros de validação do cadastro; erros
     * parciais de matrícula viram flash no próprio redirect.
     */
    public static function create(array $input, bool $sendByEmail, array $courseIds = []): array
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

        if ($courseIds !== []) {
            $stats = TeacherEnrollmentsController::enrollBulk($student['id'], $courseIds, $tenantId);
            if ($stats['ok'] > 0) {
                flash('success', __t('enrollments.created_count', ['count' => $stats['ok']]));
            }
            if ($stats['course_archived'] > 0) {
                flash('warning', __t('enrollments.skipped_archived', ['count' => $stats['course_archived']]));
            }
            if ($stats['wrong_tenant'] > 0) {
                flash('warning', __t('enrollments.skipped_wrong_tenant', ['count' => $stats['wrong_tenant']]));
            }
        }

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

        $name   = trim((string) ($input['name']        ?? ''));
        $lang   = (string)      ($input['language']    ?? '');
        $gender = (string)      ($input['gender']      ?? '');
        $idDoc  = trim((string) ($input['id_document'] ?? ''));

        $errors = [];
        if (mb_strlen($name) < 3 || mb_strlen($name) > 120) {
            $errors['name'] = 'students.form.err.name';
        }
        if ($lang !== 'pt' && $lang !== 'en') {
            $errors['language'] = 'students.form.err.language';
        }
        if ($gender !== 'male' && $gender !== 'female') {
            $errors['gender'] = 'students.form.err.gender_required';
        }
        if ($idDoc !== '' && preg_match('/^[0-9]{1,30}$/', $idDoc) !== 1) {
            $errors['id_document'] = 'students.form.err.id_document_format';
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

        Student::update($studentId, $tenantId, [
            'name'        => $name,
            'language'    => $lang,
            'gender'      => $gender,
            'id_document' => $idDoc !== '' ? $idDoc : null,
        ]);

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
     * POST /teacher/students/{id}/reset-password — força nova senha do aluno
     * (E4-05). Replica o padrão de `AdminTeachersController::resetPassword`
     * de E2-07: timestamp gerado em PHP (literal no UPDATE, evita drift de
     * relógio com o middleware de E1-05) + transient de sessão quando o
     * email não é entregue.
     *
     * Sessão ativa do aluno é invalidada no próximo request pelo mesmo
     * middleware — nada a fazer aqui.
     *
     * @return array<string,string> erros por campo; vazio em sucesso.
     */
    public static function resetPassword(int $studentId, string $newPassword, bool $sendByEmail): array
    {
        $tenantId = current_tenant_id();
        if ($tenantId === null) {
            http_response_code(403);
            require LMS_ROOT . '/src/templates/errors/403.php';
            exit;
        }

        if (strlen($newPassword) < 8) {
            return ['new_password' => 'students.form.err.password_min'];
        }

        $student = Student::findForTenant($studentId, $tenantId);
        if ($student === null) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }

        $now  = date('Y-m-d H:i:s');
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

        Database::pdo()->prepare(
            'UPDATE users SET password_hash = ?, password_changed_at = ?
              WHERE id = ? AND tenant_id = ?'
        )->execute([$hash, $now, $studentId, $tenantId]);

        $mailDelivered = false;
        if ($sendByEmail && Mailer::isConfigured()) {
            self::sendPasswordResetEmail([
                'email'    => (string) $student['email'],
                'password' => $newPassword,
                'language' => (string) $student['language'],
            ]);
            $mailDelivered = true;
        }

        if (!$mailDelivered) {
            $_SESSION['student_password_reset_once'] = [
                'student_id' => $studentId,
                'name'       => (string) $student['name'],
                'email'      => (string) $student['email'],
                'password'   => $newPassword,
                'reason'     => $sendByEmail ? 'smtp_unavailable' : 'teacher_opted_out',
            ];
        }

        flash('success', __t('students.password_reset.done', ['name' => (string) $student['name']]));
        header('Location: /teacher/students/' . $studentId, true, 303);
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

    /**
     * Variante pós-reset de senha (E4-05) — template separado porque o
     * corpo muda (aviso de reset, não cadastro).
     *
     * @param array{email:string, password:string, language:string} $student
     */
    private static function sendPasswordResetEmail(array $student): void
    {
        $lang = in_array($student['language'], ['pt', 'en'], true) ? $student['language'] : 'pt';
        $path = LMS_ROOT . '/src/templates/email/student_password_reset.' . $lang . '.php';

        $base = rtrim((string) ($GLOBALS['__ENV']['APP_BASE_URL'] ?? ''), '/');
        $data = [
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
