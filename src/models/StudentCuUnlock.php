<?php
declare(strict_types=1);

/**
 * Desbloqueio manual de CU por aluno (E36-02).
 *
 * O professor libera uma Unidade especifica pra um aluno especifico, furando
 * a trava sequencial do curso. Vale nos dois formatos de curso (V1 classico e
 * V2 trilha) — eh override do gate calculado em `course_progression_state()`,
 * nao altera estrutura nenhuma.
 *
 * O que o desbloqueio NAO faz:
 *  - nao marca a CU como concluida;
 *  - nao entra no calculo de % (StudentProgress segue igual);
 *  - nao libera as CUs seguintes, so a que foi liberada.
 *
 * Isolamento: as queries aqui recebem ids ja validados pelo caller (a pagina
 * confere autoria da CU via `effective_authoring_tenant` e pertencimento do
 * aluno ao tenant do professor). Este model nao re-valida tenant — segue o
 * mesmo contrato de `CuRoster` e dos demais models de conteudo, cujo tenant
 * deriva da cadeia CU -> CC -> course.
 */
final class StudentCuUnlock
{
    /**
     * Ids das CUs liberadas pro aluno DENTRO de um curso. Uma query por
     * render de pagina — `course_progression_state()` chama uma vez e
     * consulta o set em memoria, sem N+1 por CU.
     *
     * @return array<int,true> mapa cu_id => true (lookup O(1) via isset)
     */
    public static function cuIdsForStudentInCourse(int $studentId, int $courseId): array
    {
        if ($studentId <= 0 || $courseId <= 0) {
            return [];
        }
        $stmt = Database::pdo()->prepare(
            'SELECT u.cu_id
               FROM student_cu_unlocks u
               JOIN competence_units  cu ON cu.id = u.cu_id
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
              WHERE u.student_user_id = ?
                AND cc.course_id      = ?'
        );
        $stmt->execute([$studentId, $courseId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['cu_id']] = true;
        }
        return $out;
    }

    /**
     * Alunos com a CU liberada. Alimenta a coluna de cadeado na grade de
     * `/teacher/cu/{id}` sem uma query por linha.
     *
     * @return array<int,true> mapa student_user_id => true
     */
    public static function studentIdsForCu(int $cuId): array
    {
        if ($cuId <= 0) {
            return [];
        }
        $stmt = Database::pdo()->prepare(
            'SELECT student_user_id FROM student_cu_unlocks WHERE cu_id = ?'
        );
        $stmt->execute([$cuId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['student_user_id']] = true;
        }
        return $out;
    }

    /**
     * Libera a CU pro aluno. Idempotente via PK composta — clicar duas vezes
     * nao duplica nem falha.
     */
    public static function grant(int $cuId, int $studentId, int $grantedByUserId): void
    {
        Database::pdo()
            ->prepare(
                'INSERT IGNORE INTO student_cu_unlocks
                    (cu_id, student_user_id, granted_by_user_id)
                 VALUES (?, ?, ?)'
            )
            ->execute([$cuId, $studentId, $grantedByUserId > 0 ? $grantedByUserId : null]);
    }

    /**
     * Revoga o desbloqueio. A CU volta a obedecer a regra sequencial —
     * o que o aluno ja concluiu la dentro continua concluido.
     */
    public static function revoke(int $cuId, int $studentId): void
    {
        Database::pdo()
            ->prepare('DELETE FROM student_cu_unlocks WHERE cu_id = ? AND student_user_id = ?')
            ->execute([$cuId, $studentId]);
    }

    /** True se a CU esta liberada manualmente pro aluno. */
    public static function isUnlocked(int $cuId, int $studentId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT 1 FROM student_cu_unlocks WHERE cu_id = ? AND student_user_id = ? LIMIT 1'
        );
        $stmt->execute([$cuId, $studentId]);
        return $stmt->fetchColumn() !== false;
    }
}
