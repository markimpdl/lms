<?php
declare(strict_types=1);

/**
 * Model de colaborador de curso (E32 / F23 — ADR-033).
 *
 * Liga um curso a professores colaboradores de OUTROS tenants. Compartilha
 * apenas a autoria de conteúdo — dados de aluno (matrículas, entregas, notas,
 * XP, ranking) seguem isolados por tenant.
 *
 * Apenas o DONO do curso (owner do tenant) adiciona/remove colaboradores; o
 * controller garante isso e `add()` revalida a posse do curso pelo tenant.
 */
final class CourseCollaborator
{
    /**
     * Adiciona um professor como colaborador do curso, por email. Só o dono
     * pode: valida que o curso pertence a $ownerTenantId.
     *
     * Retorna:
     *  - 'ok'               adicionado
     *  - 'course_not_owned' curso não pertence ao tenant do dono
     *  - 'not_a_teacher'    email não é de um professor ativo existente
     *  - 'self'             o email é do próprio dono
     *  - 'already'          já é colaborador
     */
    public static function add(int $courseId, int $ownerTenantId, int $invitedByUserId, string $email): string
    {
        $pdo = Database::pdo();

        $st = $pdo->prepare('SELECT 1 FROM courses WHERE id = ? AND tenant_id = ? LIMIT 1');
        $st->execute([$courseId, $ownerTenantId]);
        if ($st->fetchColumn() === false) {
            return 'course_not_owned';
        }

        $teacher = self::findActiveTeacherByEmail($email);
        if ($teacher === null) {
            return 'not_a_teacher';
        }
        $teacherId = (int) $teacher['id'];

        if ($teacherId === $invitedByUserId) {
            return 'self';
        }

        $st = $pdo->prepare('SELECT 1 FROM course_collaborators WHERE course_id = ? AND user_id = ? LIMIT 1');
        $st->execute([$courseId, $teacherId]);
        if ($st->fetchColumn() !== false) {
            return 'already';
        }

        $pdo->prepare(
            'INSERT INTO course_collaborators (course_id, user_id, invited_by) VALUES (?, ?, ?)'
        )->execute([$courseId, $teacherId, $invitedByUserId]);

        return 'ok';
    }

    /**
     * Remove um colaborador do curso. Retorna true se algo foi removido. O
     * controller valida a posse do curso pelo dono antes de chamar.
     */
    public static function remove(int $courseId, int $userId): bool
    {
        $st = Database::pdo()->prepare(
            'DELETE FROM course_collaborators WHERE course_id = ? AND user_id = ?'
        );
        $st->execute([$courseId, $userId]);
        return $st->rowCount() > 0;
    }

    /**
     * Colaboradores de um curso (id, nome, email, data), ordem de entrada.
     *
     * @return list<array{user_id:int,name:string,email:string,created_at:string}>
     */
    public static function listByCourse(int $courseId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT u.id AS user_id, u.name, u.email, cc.created_at
               FROM course_collaborators cc
               JOIN users u ON u.id = cc.user_id
              WHERE cc.course_id = ?
              ORDER BY cc.created_at ASC, u.name ASC'
        );
        $st->execute([$courseId]);
        return array_map(static fn(array $r): array => [
            'user_id'    => (int) $r['user_id'],
            'name'       => (string) $r['name'],
            'email'      => (string) $r['email'],
            'created_at' => (string) $r['created_at'],
        ], $st->fetchAll());
    }

    /**
     * Dados (id, nome, email) de um colaborador específico do curso, ou null
     * se não for colaborador. Usado para capturar o "desfazer" na remoção.
     *
     * @return array{id:int,name:string,email:string}|null
     */
    public static function findCollaboratorUser(int $courseId, int $userId): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT u.id, u.name, u.email
               FROM course_collaborators cc
               JOIN users u ON u.id = cc.user_id
              WHERE cc.course_id = ? AND cc.user_id = ?
              LIMIT 1'
        );
        $st->execute([$courseId, $userId]);
        $row = $st->fetch();
        if ($row === false) {
            return null;
        }
        return [
            'id'    => (int) $row['id'],
            'name'  => (string) $row['name'],
            'email' => (string) $row['email'],
        ];
    }

    /**
     * IDs dos cursos de um tenant que TÊM ao menos um colaborador — para
     * marcar o badge "Compartilhado por você" na listagem do dono (E32-04).
     *
     * @return list<int>
     */
    public static function courseIdsSharedByOwner(int $ownerTenantId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT DISTINCT cc.course_id
               FROM course_collaborators cc
               JOIN courses c ON c.id = cc.course_id
              WHERE c.tenant_id = ?'
        );
        $st->execute([$ownerTenantId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** True se $userId é colaborador de $courseId. */
    public static function isCollaborator(int $courseId, int $userId): bool
    {
        $st = Database::pdo()->prepare(
            'SELECT 1 FROM course_collaborators WHERE course_id = ? AND user_id = ? LIMIT 1'
        );
        $st->execute([$courseId, $userId]);
        return $st->fetchColumn() !== false;
    }

    /**
     * IDs dos cursos em que $userId é colaborador (não inclui os próprios).
     *
     * @return list<int>
     */
    public static function listCourseIdsForCollaborator(int $userId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT course_id FROM course_collaborators WHERE user_id = ?'
        );
        $st->execute([$userId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Busca um professor ativo pelo email. Email de professor é único
     * globalmente (ADR-026: teacher tem tenant_id NULL → email_tenant_key
     * = "email:0"), então no máximo uma linha.
     *
     * @return array{id:int,name:string,email:string,language:string}|null
     */
    public static function findActiveTeacherByEmail(string $email): ?array
    {
        $st = Database::pdo()->prepare(
            "SELECT id, name, email, language
               FROM users
              WHERE email = ? AND role = 'teacher' AND active = 1
              LIMIT 1"
        );
        $st->execute([$email]);
        $row = $st->fetch();
        if ($row === false) {
            return null;
        }
        return [
            'id'       => (int) $row['id'],
            'name'     => (string) $row['name'],
            'email'    => (string) $row['email'],
            'language' => (string) $row['language'],
        ];
    }
}
