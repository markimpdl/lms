<?php
declare(strict_types=1);

/**
 * Ações administrativas sobre a entidade professor (E2-02+).
 *
 * Hoje só atende ao POST de criação. Segue o estilo flat do projeto (sem
 * namespaces) — convenção de arquivos por classe, autoload simples.
 */
final class AdminTeachersController
{
    /**
     * Processa o POST de /admin/teachers/new.
     *
     * Fluxo de sucesso:
     *   1. Cria user+tenant em transação via service.
     *   2. Se SMTP configurado (ou admin marcou "enviar") → manda welcome
     *      email no idioma do professor; caso contrário, guarda credenciais
     *      num transient de sessão (`teacher_creds_once`) para a listagem
     *      exibir apenas uma vez.
     *   3. flash + redirect 303 para /admin/teachers (PRG).
     *
     * Fluxo de erro: devolve array de erros; chamador re-renderiza o form com
     * `old` values.
     *
     * @param array<string,string> $input        $_POST sanitizado pelo chamador.
     * @param bool                 $sendByEmail  Checkbox "enviar credenciais".
     *
     * @return array<string,string> Mapa field → i18n key. Vazio quando bem-sucedido.
     */
    public static function create(array $input, bool $sendByEmail): array
    {
        $result = TeacherProvisioningService::create($input);
        if ($result['errors'] !== []) {
            return $result['errors'];
        }

        $teacher = $result['teacher'];
        $mailDelivered = false;

        if ($sendByEmail && Mailer::isConfigured()) {
            self::sendWelcomeEmail($teacher);
            $mailDelivered = true;
        }

        if (!$mailDelivered) {
            // Admin precisa ler e passar as credenciais manualmente.
            $_SESSION['teacher_creds_once'] = [
                'name'        => $input['name'] ?? '',
                'email'       => $teacher['email'],
                'password'    => $teacher['password'],
                'tenant_name' => $teacher['tenant_name'],
                'reason'      => $sendByEmail ? 'smtp_unavailable' : 'admin_opted_out',
            ];
        }

        flash('success', __t('admin.teachers.created', ['name' => $input['name'] ?? '']));
        header('Location: /admin/teachers', true, 303);
        exit;
    }

    /**
     * Processa o POST de /admin/teachers/{id} (E2-03).
     *
     * Atualiza `users.name`/`users.language` e `tenants.name` em uma
     * transação. Erros por campo voltam como mapa i18n → caller re-renderiza
     * o form; em sucesso, redirect 303 para a listagem com flash.
     *
     * @param array<string,string> $input Chaves: name, language, tenant_name.
     * @return array<string,string> Mapa field → i18n key.
     */
    public static function update(int $teacherId, array $input): array
    {
        $name   = trim((string) ($input['name']        ?? ''));
        $lang   = (string)      ($input['language']    ?? '');
        $tenant = trim((string) ($input['tenant_name'] ?? ''));

        $errors = [];
        if (mb_strlen($name) < 3 || mb_strlen($name) > 120) {
            $errors['name'] = 'admin.teachers.form.err.name';
        }
        if ($lang !== 'pt' && $lang !== 'en') {
            $errors['language'] = 'admin.teachers.form.err.language';
        }
        if (mb_strlen($tenant) < 3 || mb_strlen($tenant) > 120) {
            $errors['tenant_name'] = 'admin.teachers.form.err.tenant_name';
        }
        if ($errors !== []) {
            return $errors;
        }

        $teacher = TeacherAdmin::findById($teacherId);
        if ($teacher === null) {
            // Id sumiu no meio do fluxo — trata como 404 amigável.
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }
        $tenantId = (int) $teacher['tenant_id'];

        try {
            Database::tx(static function (PDO $pdo) use ($teacherId, $tenantId, $name, $lang, $tenant): void {
                $pdo->prepare('UPDATE users SET name = ?, language = ? WHERE id = ?')
                    ->execute([$name, $lang, $teacherId]);

                $renameErr = Tenant::rename($tenantId, $tenant);
                if ($renameErr !== null) {
                    throw new RuntimeException($renameErr);
                }
            });
        } catch (RuntimeException $e) {
            return ['tenant_name' => $e->getMessage()];
        }

        flash('success', __t('admin.teachers.edit.updated', ['name' => $name]));
        header('Location: /admin/teachers', true, 303);
        exit;
    }

    /**
     * Alterna `users.active` e `tenants.active` do professor em uma transação
     * (E2-04). Não há exclusão dura (ADR-024). O middleware de E1-05 faz o
     * resto: sessões ativas do professor são invalidadas no próximo request
     * porque `require_auth` recarrega `active` do banco a cada hit.
     *
     * Alunos continuam com `active=1` e suas matrículas; o tenant desativado
     * só fica invisível quando `Course::listByTenant` chegar em E3/E4 com o
     * filtro `tenants.active=1`.
     *
     * @param string $from Override do Location de destino ('list'|'edit').
     */
    public static function toggleActive(int $teacherId, string $from = 'list'): void
    {
        $teacher = TeacherAdmin::findById($teacherId);
        if ($teacher === null) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }

        $next = (int) $teacher['active'] === 1 ? 0 : 1;

        Database::tx(static function (PDO $pdo) use ($teacherId, $next): void {
            $pdo->prepare('UPDATE users SET active = ? WHERE id = ?')
                ->execute([$next, $teacherId]);
            $pdo->prepare('UPDATE tenants SET active = ? WHERE owner_user_id = ?')
                ->execute([$next, $teacherId]);
        });

        flash(
            'success',
            __t(
                $next === 1 ? 'admin.teachers.reactivated' : 'admin.teachers.deactivated',
                ['name' => $teacher['name']]
            )
        );

        $target = $from === 'edit' ? '/admin/teachers/' . $teacherId : '/admin/teachers';
        header('Location: ' . $target, true, 303);
        exit;
    }

    /**
     * Reseta a senha do professor pelo super-admin (E2-07).
     *
     * Usa timestamp gerado em PHP no UPDATE para que `password_changed_at`
     * fique idêntico ao que a sessão gravaria — o mesmo princípio que
     * `UserController::changePassword` adota para não deslogar o próprio
     * usuário por drift de 1s entre clock PHP/MySQL (aqui não afeta o
     * admin, mas preserva determinismo).
     *
     * Sessões vivas do professor-alvo são invalidadas na próxima request
     * pelo middleware de E1-05 — nada a fazer aqui.
     *
     * Quando SMTP não está configurado ou o admin desmarcou "enviar por
     * email", a nova senha fica em `$_SESSION['teacher_password_reset_once']`
     * para a tela de edição exibir uma vez e drenar.
     *
     * @return array<string,string> erros por campo; vazio em sucesso.
     */
    public static function resetPassword(int $teacherId, string $newPassword, bool $sendByEmail): array
    {
        if (strlen($newPassword) < 8) {
            return ['new_password' => 'admin.teachers.form.err.password_min'];
        }

        $teacher = TeacherAdmin::findById($teacherId);
        if ($teacher === null) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }

        $now  = date('Y-m-d H:i:s');
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

        Database::pdo()->prepare(
            'UPDATE users SET password_hash = ?, password_changed_at = ?
              WHERE id = ?'
        )->execute([$hash, $now, $teacherId]);

        $mailDelivered = false;
        if ($sendByEmail && Mailer::isConfigured()) {
            self::sendPasswordResetEmail([
                'email'       => (string) $teacher['email'],
                'password'    => $newPassword,
                'language'    => (string) $teacher['language'],
                'tenant_name' => (string) $teacher['tenant_name'],
            ]);
            $mailDelivered = true;
        }

        if (!$mailDelivered) {
            $_SESSION['teacher_password_reset_once'] = [
                'teacher_id' => $teacherId,
                'name'       => (string) $teacher['name'],
                'email'      => (string) $teacher['email'],
                'password'   => $newPassword,
                'reason'     => $sendByEmail ? 'smtp_unavailable' : 'admin_opted_out',
            ];
        }

        flash('success', __t('admin.teachers.reset_password.done', ['name' => $teacher['name']]));
        header('Location: /admin/teachers/' . $teacherId, true, 303);
        exit;
    }

    /**
     * Monta e entrega o email de boas-vindas no idioma do professor.
     * O template é um arquivo PHP por idioma (ADR-014 adaptado: não queremos
     * depender de __t() em email, para evitar fallback de chave ausente).
     */
    private static function sendWelcomeEmail(array $teacher): void
    {
        self::sendEmailTemplate('teacher_welcome', $teacher);
    }

    /**
     * Variante do email de boas-vindas — comunica reset pelo admin (E2-07).
     * Template separado porque o corpo muda (aviso de reset, não cadastro).
     */
    private static function sendPasswordResetEmail(array $teacher): void
    {
        self::sendEmailTemplate('password_reset_by_admin', $teacher);
    }

    private static function sendEmailTemplate(string $template, array $teacher): void
    {
        $lang = in_array($teacher['language'], ['pt', 'en'], true) ? $teacher['language'] : 'pt';
        $path = LMS_ROOT . '/src/templates/email/' . $template . '.' . $lang . '.php';

        $base = rtrim((string) ($GLOBALS['__ENV']['APP_BASE_URL'] ?? ''), '/');
        $data = [
            'email'       => $teacher['email'],
            'password'    => $teacher['password'],
            'login_url'   => $base . '/login',
            'tenant_name' => $teacher['tenant_name'],
        ];

        /** @var array{subject:string, html:string, text:string} $tpl */
        $tpl = (static function (string $tplPath, array $data): array {
            return require $tplPath;
        })($path, $data);

        Mailer::send($teacher['email'], $tpl['subject'], $tpl['html'], $tpl['text']);
    }
}
