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
