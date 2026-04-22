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
     * Monta e entrega o email de boas-vindas no idioma do professor.
     * O template é um arquivo PHP por idioma (ADR-014 adaptado: não queremos
     * depender de __t() em email, para evitar fallback de chave ausente).
     */
    private static function sendWelcomeEmail(array $teacher): void
    {
        $lang = in_array($teacher['language'], ['pt', 'en'], true) ? $teacher['language'] : 'pt';
        $path = LMS_ROOT . '/src/templates/email/teacher_welcome.' . $lang . '.php';

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
