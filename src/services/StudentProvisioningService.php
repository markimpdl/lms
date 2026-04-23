<?php
declare(strict_types=1);

/**
 * Provisiona um aluno no tenant do professor logado (E4-01).
 *
 * Diferenças relevantes em relação ao `TeacherProvisioningService`:
 *   - Não cria tenant (usa o tenant_id do professor).
 *   - ADR-026: mesmo email pode existir em tenants diferentes como alunos
 *     distintos (cobertura nativa pela UK `uk_users_email_tenant`), mas
 *     NÃO pode colidir com um professor/super_admin já existente
 *     (validação em app — a UK sozinha não diferencia `tenant_id=NULL`).
 *
 * Retorna `errors: map + student: {id, email, password, language}` no padrão
 * já usado pelo `AdminTeachersController`.
 */
final class StudentProvisioningService
{
    public const NAME_MIN = 3;
    public const NAME_MAX = 120;
    public const PASS_MIN = 8;

    /**
     * @param array<string,string> $input Chaves: name, email, password, language.
     *
     * @return array{
     *   errors:  array<string,string>,
     *   student: array{id:int, email:string, password:string, language:string}|null
     * }
     */
    public static function create(int $tenantId, array $input): array
    {
        $name  = trim((string) ($input['name']     ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $pass  = (string)      ($input['password'] ?? '');
        $lang  = (string)      ($input['language'] ?? '');

        $errors = [];
        if (mb_strlen($name) < self::NAME_MIN || mb_strlen($name) > self::NAME_MAX) {
            $errors['name'] = 'students.form.err.name';
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'students.form.err.email_invalid';
        }
        if (strlen($pass) < self::PASS_MIN) {
            $errors['password'] = 'students.form.err.password_min';
        }
        if ($lang !== 'pt' && $lang !== 'en') {
            $errors['language'] = 'students.form.err.language';
        }
        if ($errors !== []) {
            return ['errors' => $errors, 'student' => null];
        }

        $pdo = Database::pdo();

        // ADR-026: email já usado por teacher/super_admin (tenant_id IS NULL) é bloqueado.
        $stmt = $pdo->prepare(
            'SELECT 1 FROM users WHERE email = ? AND role IN ("teacher", "super_admin") LIMIT 1'
        );
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() !== false) {
            return [
                'errors'  => ['email' => 'students.form.err.email_taken_by_staff'],
                'student' => null,
            ];
        }

        // Dentro do mesmo tenant, email de aluno precisa ser único.
        $stmt = $pdo->prepare(
            'SELECT 1 FROM users WHERE email = ? AND tenant_id = ? AND role = "student" LIMIT 1'
        );
        $stmt->execute([$email, $tenantId]);
        if ($stmt->fetchColumn() !== false) {
            return [
                'errors'  => ['email' => 'students.form.err.email_taken'],
                'student' => null,
            ];
        }

        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);

        try {
            $studentId = Student::create($tenantId, [
                'name'          => $name,
                'email'         => $email,
                'password_hash' => $hash,
                'language'      => $lang,
            ]);
        } catch (PDOException $e) {
            // Rede de segurança: race entre o SELECT de email e o INSERT.
            if ($e->getCode() === '23000') {
                return [
                    'errors'  => ['email' => 'students.form.err.email_taken'],
                    'student' => null,
                ];
            }
            throw $e;
        }

        return [
            'errors'  => [],
            'student' => [
                'id'       => $studentId,
                'email'    => $email,
                'password' => $pass,
                'language' => $lang,
            ],
        ];
    }

    /**
     * Gera senha aleatória de 12 caracteres alfanuméricos + símbolos (ADR-022).
     * Usada pelo botão "gerar forte" no form de cadastro (via endpoint ou
     * chamada direta no controller). Garante pelo menos 1 de cada classe.
     */
    public static function generateStrongPassword(): string
    {
        $lower   = 'abcdefghijkmnpqrstuvwxyz';
        $upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits  = '23456789';
        $symbols = '!@#$%&*?';
        $all     = $lower . $upper . $digits . $symbols;

        $out  = $lower[random_int(0,  strlen($lower)   - 1)];
        $out .= $upper[random_int(0,  strlen($upper)   - 1)];
        $out .= $digits[random_int(0, strlen($digits)  - 1)];
        $out .= $symbols[random_int(0, strlen($symbols) - 1)];

        for ($i = 4; $i < 12; $i++) {
            $out .= $all[random_int(0, strlen($all) - 1)];
        }

        return str_shuffle($out);
    }
}
