<?php
declare(strict_types=1);

/**
 * Visão agregada do currículo do aluno (E5-05).
 *
 * Retorna a árvore Curso → CC → CU dos cursos em que o aluno está
 * matriculado, num único SELECT. Usado no dashboard do aluno pra dar
 * navegação até o conteúdo de cada CU.
 *
 * Não expõe estrutura de cursos em que o aluno não tem matrícula —
 * nunca vaza currículo cross-curso.
 */
final class StudentCurriculum
{
    /**
     * Visão de UM curso específico do aluno. Retorna null se o aluno não
     * está matriculado nesse curso. Usado por `/student/course/{id}` (tela
     * nova de E5-05).
     *
     * @return array{course_id:int, course_name:string, course_archived:int, ccs:list<array{id:int, name:string, cus:list<array{id:int, name:string}>}>}|null
     */
    public static function forStudentCourse(int $studentId, int $courseId): ?array
    {
        $all = self::forStudent($studentId);
        foreach ($all as $course) {
            if ($course['course_id'] === $courseId) {
                return $course;
            }
        }
        return null;
    }

    /**
     * @return list<array{
     *   course_id: int,
     *   course_name: string,
     *   course_archived: int,
     *   ccs: list<array{id:int, name:string, cus: list<array{id:int, name:string}>}>
     * }>
     */
    public static function forStudent(int $studentId): array
    {
        $sql = <<<SQL
            SELECT c.id  AS course_id, c.name AS course_name, c.archived AS course_archived,
                   cc.id AS cc_id,     cc.name AS cc_name,     cc.position AS cc_position,
                   cu.id AS cu_id,     cu.name AS cu_name,     cu.position AS cu_position
              FROM enrollments e
              JOIN courses c            ON c.id  = e.course_id
              LEFT JOIN core_competencies cc ON cc.course_id = c.id
              LEFT JOIN competence_units  cu ON cu.core_competency_id = cc.id
             WHERE e.student_user_id = ?
             ORDER BY c.name ASC, c.id ASC,
                      cc.position ASC, cc.id ASC,
                      cu.position ASC, cu.id ASC
            SQL;

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([$studentId]);
        $rows = $stmt->fetchAll();

        $tree = [];
        $courseIdx = [];
        $ccIdx     = [];

        foreach ($rows as $row) {
            $courseId = (int) $row['course_id'];
            if (!isset($courseIdx[$courseId])) {
                $courseIdx[$courseId] = count($tree);
                $tree[] = [
                    'course_id'       => $courseId,
                    'course_name'     => (string) $row['course_name'],
                    'course_archived' => (int)    $row['course_archived'],
                    'ccs'             => [],
                ];
            }
            if ($row['cc_id'] === null) {
                continue;
            }
            $ccId = (int) $row['cc_id'];
            $key  = $courseId . ':' . $ccId;
            if (!isset($ccIdx[$key])) {
                $ccIdx[$key] = count($tree[$courseIdx[$courseId]]['ccs']);
                $tree[$courseIdx[$courseId]]['ccs'][] = [
                    'id'   => $ccId,
                    'name' => (string) $row['cc_name'],
                    'cus'  => [],
                ];
            }
            if ($row['cu_id'] !== null) {
                $tree[$courseIdx[$courseId]]['ccs'][$ccIdx[$key]]['cus'][] = [
                    'id'   => (int)    $row['cu_id'],
                    'name' => (string) $row['cu_name'],
                ];
            }
        }

        return $tree;
    }
}
