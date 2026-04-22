<?php
declare(strict_types=1);

/**
 * Provisiona um professor + tenant em uma única transação (E2-02).
 *
 * Professor tem `tenant_id = NULL` (ADR-026). O tenant criado em seguida
 * aponta seu `owner_user_id` para o id recém-criado. Qualquer falha em uma
 * das duas operações rola a outra atrás.
 *
 * Regras de validação são executadas antes de abrir a transação; unicidades
 * são checadas por SELECT + confirmadas por exception SQLSTATE 23000 em
 * último caso (cobrindo race raríssima entre check e INSERT).
 */
final class TeacherProvisioningService
{
    public const NAME_MIN  = 3;
    public const NAME_MAX  = 120;
    public const PASS_MIN  = 8;

    /**
     * @param array<string,string> $input Chaves: name, email, password, language, tenant_name.
     *
     * @return array{
     *   errors:  array<string,string>,
     *   teacher: array{id:int, email:string, password:string, language:string, tenant_name:string}|null
     * }
     */
    public static function create(array $input): array
    {
        $name   = trim((string) ($input['name']        ?? ''));
        $email  = strtolower(trim((string) ($input['email'] ?? '')));
        $pass   = (string)      ($input['password']    ?? '');
        $lang   = (string)      ($input['language']    ?? '');
        $tenant = trim((string) ($input['tenant_name'] ?? ''));

        $errors = [];

        if (mb_strlen($name) < self::NAME_MIN || mb_strlen($name) > self::NAME_MAX) {
            $errors['name'] = 'admin.teachers.form.err.name';
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'admin.teachers.form.err.email_invalid';
        }
        if (strlen($pass) < self::PASS_MIN) {
            $errors['password'] = 'admin.teachers.form.err.password_min';
        }
        if ($lang !== 'pt' && $lang !== 'en') {
            $errors['language'] = 'admin.teachers.form.err.language';
        }
        if (mb_strlen($tenant) < self::NAME_MIN || mb_strlen($tenant) > self::NAME_MAX) {
            $errors['tenant_name'] = 'admin.teachers.form.err.tenant_name';
        }

        if ($errors !== []) {
            return ['errors' => $errors, 'teacher' => null];
        }

        $pdo = Database::pdo();

        // Pré-checks de unicidade para mensagens por campo (UNIQUE keys cobrem
        // a race residual e caem no catch abaixo).
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() !== false) {
            return [
                'errors'  => ['email' => 'admin.teachers.form.err.email_taken'],
                'teacher' => null,
            ];
        }

        $stmt = $pdo->prepare('SELECT 1 FROM tenants WHERE name = ? LIMIT 1');
        $stmt->execute([$tenant]);
        if ($stmt->fetchColumn() !== false) {
            return [
                'errors'  => ['tenant_name' => 'admin.teachers.form.err.tenant_taken'],
                'teacher' => null,
            ];
        }

        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);

        try {
            $teacherId = (int) Database::tx(static function (PDO $pdo) use ($name, $email, $hash, $lang, $tenant): int {
                $pdo->prepare(
                    'INSERT INTO users (tenant_id, email, password_hash, name, role, language, active)
                          VALUES (NULL, ?, ?, ?, "teacher", ?, 1)'
                )->execute([$email, $hash, $name, $lang]);

                $newId = (int) $pdo->lastInsertId();

                $pdo->prepare(
                    'INSERT INTO tenants (owner_user_id, name, active)
                          VALUES (?, ?, 1)'
                )->execute([$newId, $tenant]);

                return $newId;
            });
        } catch (PDOException $e) {
            // Último recurso: violação de UNIQUE pós-race. Devolve erro genérico
            // por campo mais provável; detalhe real vai para o log de DB.
            if ($e->getCode() === '23000') {
                $field = str_contains((string) $e->getMessage(), 'uk_tenants_name')
                    ? 'tenant_name'
                    : 'email';
                return [
                    'errors' => [
                        $field => $field === 'tenant_name'
                            ? 'admin.teachers.form.err.tenant_taken'
                            : 'admin.teachers.form.err.email_taken',
                    ],
                    'teacher' => null,
                ];
            }
            throw $e;
        }

        return [
            'errors'  => [],
            'teacher' => [
                'id'          => $teacherId,
                'email'       => $email,
                'password'    => $pass,
                'language'    => $lang,
                'tenant_name' => $tenant,
            ],
        ];
    }
}
