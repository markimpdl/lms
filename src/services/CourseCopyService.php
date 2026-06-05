<?php
declare(strict_types=1);

/**
 * Cópia profunda de conteúdo de curso (E31 / F22 — ADR-034).
 *
 * Três operações públicas:
 *   - duplicateCourse:    clona um curso inteiro num curso novo (mesmo tenant).
 *   - copyCoreCompetence: copia uma CC (com suas CUs) para outro curso.
 *   - copyCompetenceUnit: copia uma CU para uma CC de destino.
 *
 * A cópia é FÍSICA e INDEPENDENTE: novas linhas, novos IDs e arquivos
 * duplicados em disco (novo `stored_path`). Editar a cópia nunca afeta a
 * origem. Copia só estrutura + conteúdo:
 *   curso → CC → CU (posição, workload, manual_completion_*)
 *      → contents (html, `published` mantido da origem) + content_attachments
 *      → activities (+ brief PDF/ZIP) + quiz (questões/opções)
 *      → evaluations (+ brief) + quiz
 *      → learning_outcomes
 *
 * NÃO copia dados de aluno (matrículas, entregas, notas, feedback, XP,
 * conquistas, cu_manual_completions, evaluation_submission_lo_grades).
 *
 * Tudo roda numa transação (`Database::tx`): erro em qualquer passo faz
 * rollback do banco e remove os arquivos já copiados. Retorna o id da nova
 * entidade-raiz, ou `null` se origem/destino inválidos ou em falha.
 *
 * Isolamento (ADR-001): valida que origem e destino pertencem ao tenant que
 * está agindo. (E32 troca essa checagem pelo helper de acesso compartilhado
 * — ver ADR-033.)
 */
final class CourseCopyService
{
    // -- API pública ---------------------------------------------------------

    /** Duplica um curso inteiro no mesmo tenant. Retorna id do novo curso. */
    public static function duplicateCourse(int $courseId, int $tenantId): ?int
    {
        $files = [];
        try {
            return Database::tx(function (PDO $pdo) use ($courseId, $tenantId, &$files): ?int {
                $src = self::fetchCourse($pdo, $courseId, $tenantId);
                if ($src === null) {
                    return null;
                }

                $newName     = mb_substr($src['name'] . ' (cópia)', 0, 150);
                $newCourseId = self::insertId(
                    $pdo,
                    'INSERT INTO courses
                       (tenant_id, name, description, year, language, archived, archived_at,
                        cc_mode, activity_mode, eval_after_activities, grading_mode, report_mode)
                     VALUES (?, ?, ?, ?, ?, 0, NULL, ?, ?, ?, ?, ?)',
                    [
                        $tenantId, $newName, $src['description'], (int) $src['year'], $src['language'],
                        $src['cc_mode'], $src['activity_mode'], (int) $src['eval_after_activities'],
                        $src['grading_mode'], $src['report_mode'],
                    ]
                );

                $st = $pdo->prepare(
                    'SELECT id, name, position FROM core_competencies WHERE course_id = ? ORDER BY position, id'
                );
                $st->execute([$courseId]);
                foreach ($st->fetchAll() as $cc) {
                    self::copyCcInto($pdo, (int) $cc['id'], (string) $cc['name'], (int) $cc['position'], $newCourseId, $tenantId, $files);
                }

                return $newCourseId;
            });
        } catch (\Throwable $e) {
            self::cleanupFiles($files);
            self::logFailure('duplicateCourse', $e);
            return null;
        }
    }

    /**
     * Copia uma Core Competence (com todas as CUs e conteúdo) para outro curso.
     * Destino tem de pertencer ao tenant que está agindo e não estar arquivado.
     * Retorna id da nova CC.
     */
    public static function copyCoreCompetence(int $ccId, int $targetCourseId, int $actingTenantId): ?int
    {
        $files = [];
        try {
            return Database::tx(function (PDO $pdo) use ($ccId, $targetCourseId, $actingTenantId, &$files): ?int {
                $cc = self::fetchCcOwned($pdo, $ccId, $actingTenantId);
                if ($cc === null) {
                    return null;
                }
                $destTenantId = self::fetchEditableCourseTenant($pdo, $targetCourseId, $actingTenantId);
                if ($destTenantId === null) {
                    return null;
                }
                $position = self::nextPosition($pdo, 'core_competencies', 'course_id', $targetCourseId);
                return self::copyCcInto($pdo, $ccId, (string) $cc['name'], $position, $targetCourseId, $destTenantId, $files);
            });
        } catch (\Throwable $e) {
            self::cleanupFiles($files);
            self::logFailure('copyCoreCompetence', $e);
            return null;
        }
    }

    /**
     * Copia uma Competence Unit para uma CC de destino. A CC de destino tem de
     * pertencer ao tenant que está agindo (via curso) e o curso não pode estar
     * arquivado. Retorna id da nova CU.
     */
    public static function copyCompetenceUnit(int $cuId, int $targetCcId, int $actingTenantId): ?int
    {
        $files = [];
        try {
            return Database::tx(function (PDO $pdo) use ($cuId, $targetCcId, $actingTenantId, &$files): ?int {
                $cu = self::fetchCuOwned($pdo, $cuId, $actingTenantId);
                if ($cu === null) {
                    return null;
                }
                $destTenantId = self::fetchEditableCcTenant($pdo, $targetCcId, $actingTenantId);
                if ($destTenantId === null) {
                    return null;
                }
                $position = self::nextPosition($pdo, 'competence_units', 'core_competency_id', $targetCcId);
                return self::copyCuInto($pdo, $cuId, $cu, $position, $targetCcId, $destTenantId, $files);
            });
        } catch (\Throwable $e) {
            self::cleanupFiles($files);
            self::logFailure('copyCompetenceUnit', $e);
            return null;
        }
    }

    // -- Cópia de subárvore --------------------------------------------------

    /** Insere uma nova CC sob $destCourseId e copia suas CUs. Retorna novo id. */
    private static function copyCcInto(PDO $pdo, int $srcCcId, string $name, int $position, int $destCourseId, int $destTenantId, array &$files): int
    {
        $newCcId = self::insertId(
            $pdo,
            'INSERT INTO core_competencies (course_id, name, position) VALUES (?, ?, ?)',
            [$destCourseId, $name, $position]
        );

        $st = $pdo->prepare(
            'SELECT id, name, position, workload_hours, manual_completion_enabled, manual_completion_xp
               FROM competence_units WHERE core_competency_id = ? ORDER BY position, id'
        );
        $st->execute([$srcCcId]);
        foreach ($st->fetchAll() as $cu) {
            self::copyCuInto($pdo, (int) $cu['id'], $cu, (int) $cu['position'], $newCcId, $destTenantId, $files);
        }

        return $newCcId;
    }

    /**
     * Insere uma nova CU sob $destCcId e copia conteúdo, atividades, avaliação
     * e learning outcomes. Retorna novo id.
     *
     * @param array<string,mixed> $cu linha de competence_units (origem)
     */
    private static function copyCuInto(PDO $pdo, int $srcCuId, array $cu, int $position, int $destCcId, int $destTenantId, array &$files): int
    {
        $newCuId = self::insertId(
            $pdo,
            'INSERT INTO competence_units
               (core_competency_id, name, position, workload_hours, manual_completion_enabled, manual_completion_xp)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $destCcId, $cu['name'], $position, (int) $cu['workload_hours'],
                (int) $cu['manual_completion_enabled'], (int) $cu['manual_completion_xp'],
            ]
        );

        self::copyContent($pdo, $srcCuId, $newCuId, $destTenantId, $files);
        self::copyActivities($pdo, $srcCuId, $newCuId, $destTenantId, $files);
        self::copyEvaluation($pdo, $srcCuId, $newCuId, $destTenantId, $files);
        self::copyLearningOutcomes($pdo, $srcCuId, $newCuId);

        return $newCuId;
    }

    /** Copia a página de conteúdo (1:1) + anexos físicos da CU. */
    private static function copyContent(PDO $pdo, int $srcCuId, int $destCuId, int $destTenantId, array &$files): void
    {
        $st = $pdo->prepare('SELECT id, html, published FROM contents WHERE competence_unit_id = ?');
        $st->execute([$srcCuId]);
        $content = $st->fetch();
        if ($content === false) {
            return;
        }

        $newContentId = self::insertId(
            $pdo,
            'INSERT INTO contents (competence_unit_id, html, published) VALUES (?, ?, ?)',
            [$destCuId, $content['html'], (int) $content['published']]
        );

        $ast = $pdo->prepare(
            'SELECT filename, stored_path, mime, size_bytes FROM content_attachments WHERE content_id = ? ORDER BY id'
        );
        $ast->execute([(int) $content['id']]);
        foreach ($ast->fetchAll() as $att) {
            $ext     = pathinfo((string) $att['stored_path'], PATHINFO_EXTENSION);
            $suffix  = $ext !== '' ? '.' . $ext : '';
            $relDest = 'storage/uploads/tenant_' . $destTenantId . '/content/' . $destCuId . '/' . self::uuid4() . $suffix;

            // Arquivo de origem ausente: pula o anexo (não cria referência órfã).
            if (!self::physicalCopy((string) $att['stored_path'], $relDest, $files)) {
                continue;
            }
            self::insertId(
                $pdo,
                'INSERT INTO content_attachments (content_id, filename, stored_path, mime, size_bytes) VALUES (?, ?, ?, ?, ?)',
                [$newContentId, $att['filename'], $relDest, $att['mime'], (int) $att['size_bytes']]
            );
        }
    }

    /** Copia as atividades da CU (+ brief físico + quiz). */
    private static function copyActivities(PDO $pdo, int $srcCuId, int $destCuId, int $destTenantId, array &$files): void
    {
        $st = $pdo->prepare(
            'SELECT id, title, instruction, type, code_language, pdf_path, xp_value, submission_open, allow_online_code_run, position
               FROM activities WHERE competence_unit_id = ? ORDER BY position, id'
        );
        $st->execute([$srcCuId]);
        foreach ($st->fetchAll() as $act) {
            $newActId = self::insertId(
                $pdo,
                'INSERT INTO activities
                   (competence_unit_id, title, instruction, type, code_language, pdf_path, xp_value, submission_open, allow_online_code_run, position)
                 VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?)',
                [
                    $destCuId, $act['title'], $act['instruction'], $act['type'], $act['code_language'],
                    (int) $act['xp_value'], (int) $act['submission_open'], (int) $act['allow_online_code_run'], (int) $act['position'],
                ]
            );

            if (!empty($act['pdf_path'])) {
                $ext     = pathinfo((string) $act['pdf_path'], PATHINFO_EXTENSION) ?: 'pdf';
                $relDest = 'storage/uploads/tenant_' . $destTenantId . '/activities/' . $newActId . '/brief.' . $ext;
                if (self::physicalCopy((string) $act['pdf_path'], $relDest, $files)) {
                    $pdo->prepare('UPDATE activities SET pdf_path = ? WHERE id = ?')->execute([$relDest, $newActId]);
                }
            }

            self::copyQuiz($pdo, 'activity', (int) $act['id'], $newActId, $destTenantId);
        }
    }

    /** Copia a avaliação da CU (0..1) (+ brief físico + quiz). */
    private static function copyEvaluation(PDO $pdo, int $srcCuId, int $destCuId, int $destTenantId, array &$files): void
    {
        $st = $pdo->prepare(
            'SELECT id, title, instructions, type, pdf_path, xp_value, submission_open
               FROM evaluations WHERE competence_unit_id = ?'
        );
        $st->execute([$srcCuId]);
        $ev = $st->fetch();
        if ($ev === false) {
            return;
        }

        $newEvalId = self::insertId(
            $pdo,
            'INSERT INTO evaluations
               (tenant_id, competence_unit_id, title, instructions, type, pdf_path, xp_value, submission_open)
             VALUES (?, ?, ?, ?, ?, NULL, ?, ?)',
            [
                $destTenantId, $destCuId, $ev['title'], $ev['instructions'], $ev['type'],
                (int) $ev['xp_value'], (int) $ev['submission_open'],
            ]
        );

        if (!empty($ev['pdf_path'])) {
            $ext     = pathinfo((string) $ev['pdf_path'], PATHINFO_EXTENSION) ?: 'pdf';
            $relDest = 'storage/uploads/tenant_' . $destTenantId . '/evaluations/' . $newEvalId . '/brief.' . $ext;
            if (self::physicalCopy((string) $ev['pdf_path'], $relDest, $files)) {
                $pdo->prepare('UPDATE evaluations SET pdf_path = ? WHERE id = ?')->execute([$relDest, $newEvalId]);
            }
        }

        self::copyQuiz($pdo, 'evaluation', (int) $ev['id'], $newEvalId, $destTenantId);
    }

    /** Copia o quiz (0..1) de uma activity/evaluation: questões + opções. */
    private static function copyQuiz(PDO $pdo, string $ownerType, int $srcOwnerId, int $destOwnerId, int $destTenantId): void
    {
        $st = $pdo->prepare('SELECT id, show_answers FROM quizzes WHERE owner_type = ? AND owner_id = ?');
        $st->execute([$ownerType, $srcOwnerId]);
        $quiz = $st->fetch();
        if ($quiz === false) {
            return;
        }

        $newQuizId = self::insertId(
            $pdo,
            'INSERT INTO quizzes (tenant_id, owner_type, owner_id, show_answers) VALUES (?, ?, ?, ?)',
            [$destTenantId, $ownerType, $destOwnerId, (int) $quiz['show_answers']]
        );

        $qst = $pdo->prepare('SELECT id, text, weight, position FROM quiz_questions WHERE quiz_id = ? ORDER BY position, id');
        $qst->execute([(int) $quiz['id']]);
        foreach ($qst->fetchAll() as $q) {
            $newQId = self::insertId(
                $pdo,
                'INSERT INTO quiz_questions (quiz_id, text, weight, position) VALUES (?, ?, ?, ?)',
                [$newQuizId, $q['text'], $q['weight'], (int) $q['position']]
            );

            $ost = $pdo->prepare('SELECT text, is_correct, position FROM quiz_options WHERE question_id = ? ORDER BY position, id');
            $ost->execute([(int) $q['id']]);
            foreach ($ost->fetchAll() as $o) {
                self::insertId(
                    $pdo,
                    'INSERT INTO quiz_options (question_id, text, is_correct, position) VALUES (?, ?, ?, ?)',
                    [$newQId, $o['text'], (int) $o['is_correct'], (int) $o['position']]
                );
            }
        }
    }

    /** Copia os learning outcomes da CU (sem dados de nota). */
    private static function copyLearningOutcomes(PDO $pdo, int $srcCuId, int $destCuId): void
    {
        $st = $pdo->prepare('SELECT description, position FROM learning_outcomes WHERE cu_id = ? ORDER BY position, id');
        $st->execute([$srcCuId]);
        foreach ($st->fetchAll() as $lo) {
            self::insertId(
                $pdo,
                'INSERT INTO learning_outcomes (cu_id, description, position) VALUES (?, ?, ?)',
                [$destCuId, $lo['description'], (int) $lo['position']]
            );
        }
    }

    // -- Validação de origem/destino ----------------------------------------

    /** @return array<string,mixed>|null linha do curso de origem (do tenant). */
    private static function fetchCourse(PDO $pdo, int $courseId, int $tenantId): ?array
    {
        $st = $pdo->prepare(
            'SELECT id, name, description, year, language, cc_mode, activity_mode, eval_after_activities, grading_mode, report_mode
               FROM courses WHERE id = ? AND tenant_id = ?'
        );
        $st->execute([$courseId, $tenantId]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null CC de origem se pertence ao tenant (via curso). */
    private static function fetchCcOwned(PDO $pdo, int $ccId, int $tenantId): ?array
    {
        $st = $pdo->prepare(
            'SELECT cc.id, cc.name
               FROM core_competencies cc
               JOIN courses c ON c.id = cc.course_id
              WHERE cc.id = ? AND c.tenant_id = ?'
        );
        $st->execute([$ccId, $tenantId]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null CU de origem se pertence ao tenant (via curso). */
    private static function fetchCuOwned(PDO $pdo, int $cuId, int $tenantId): ?array
    {
        $st = $pdo->prepare(
            'SELECT cu.id, cu.name, cu.workload_hours, cu.manual_completion_enabled, cu.manual_completion_xp
               FROM competence_units cu
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
               JOIN courses c ON c.id = cc.course_id
              WHERE cu.id = ? AND c.tenant_id = ?'
        );
        $st->execute([$cuId, $tenantId]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** tenant_id do curso de destino se editável pelo tenant (existe, mesmo tenant, não arquivado). */
    private static function fetchEditableCourseTenant(PDO $pdo, int $courseId, int $tenantId): ?int
    {
        $st = $pdo->prepare('SELECT tenant_id FROM courses WHERE id = ? AND tenant_id = ? AND archived = 0');
        $st->execute([$courseId, $tenantId]);
        $val = $st->fetchColumn();
        return $val === false ? null : (int) $val;
    }

    /** tenant_id do curso da CC de destino se editável pelo tenant. */
    private static function fetchEditableCcTenant(PDO $pdo, int $ccId, int $tenantId): ?int
    {
        $st = $pdo->prepare(
            'SELECT c.tenant_id
               FROM core_competencies cc
               JOIN courses c ON c.id = cc.course_id
              WHERE cc.id = ? AND c.tenant_id = ? AND c.archived = 0'
        );
        $st->execute([$ccId, $tenantId]);
        $val = $st->fetchColumn();
        return $val === false ? null : (int) $val;
    }

    /** Próxima posição (MAX+1) numa tabela com coluna de ordenação por pai. */
    private static function nextPosition(PDO $pdo, string $table, string $parentColumn, int $parentId): int
    {
        // $table e $parentColumn são literais internos (nunca input do usuário).
        $sql = "SELECT COALESCE(MAX(position), -1) + 1 FROM {$table} WHERE {$parentColumn} = ?";
        $st  = $pdo->prepare($sql);
        $st->execute([$parentId]);
        return (int) $st->fetchColumn();
    }

    // -- Utilidades ----------------------------------------------------------

    /**
     * Copia um arquivo físico de $relSource para $relDest (ambos relativos a
     * LMS_ROOT). Retorna false se a origem não existe (pula benignamente).
     * Lança em erro real de I/O (disco cheio etc.) para abortar a transação.
     */
    private static function physicalCopy(string $relSource, string $relDest, array &$files): bool
    {
        $absSource = LMS_ROOT . '/' . $relSource;
        if ($relSource === '' || !is_file($absSource)) {
            error_log('[CourseCopyService] arquivo de origem ausente, pulando: ' . $relSource);
            return false;
        }

        $absDest = LMS_ROOT . '/' . $relDest;
        $dir     = dirname($absDest);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Falha ao criar diretório de destino: ' . $dir);
        }
        if (!@copy($absSource, $absDest)) {
            throw new RuntimeException('Falha ao copiar arquivo: ' . $relSource);
        }

        $files[] = $absDest;
        return true;
    }

    /** Executa um INSERT e retorna o id gerado. */
    private static function insertId(PDO $pdo, string $sql, array $params): int
    {
        $pdo->prepare($sql)->execute($params);
        return (int) $pdo->lastInsertId();
    }

    /** Remove arquivos copiados (rollback de disco após falha). */
    private static function cleanupFiles(array $files): void
    {
        foreach ($files as $abs) {
            if (is_string($abs) && is_file($abs)) {
                @unlink($abs);
            }
        }
    }

    private static function logFailure(string $op, \Throwable $e): void
    {
        error_log(sprintf('[CourseCopyService::%s] %s', $op, $e->getMessage()));
    }

    private static function uuid4(): string
    {
        $b    = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
