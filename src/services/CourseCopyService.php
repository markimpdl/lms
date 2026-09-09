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
 *      → lessons (html, XP, posição; `published` mantido — exceto em destino
 *        V1, ver `copyLessons`)
 *      → activities (+ brief PDF/ZIP) + quiz (questões/opções)
 *      → evaluations (+ brief) + quiz
 *      → learning_outcomes
 *
 * As URLs de anexo dentro do `html` (de conteúdo e de lição) são reapontadas
 * pro acervo novo ao fim da operação — ver `applyRemaps`.
 *
 * NÃO copia dados de aluno (matrículas, entregas, notas, feedback, XP,
 * conquistas, cu_manual_completions, lesson_completions,
 * evaluation_submission_lo_grades).
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
        // Valida ANTES de abrir a transação: dentro do tx, o callback só pode
        // terminar retornando o id ou lançando — um `return null` no meio seria
        // interpretado como sucesso e cometeria uma cópia parcial sem limpar os
        // arquivos já gravados (cleanup só roda no catch).
        $src = self::fetchCourse(Database::pdo(), $courseId, $tenantId);
        if ($src === null) {
            return null;
        }

        $files = [];
        // Mapa de anexos e alvos de reaponte vivem no escopo da operacao
        // INTEIRA, nao por CU: html cita imagem de outra unidade a vontade.
        $map     = [];
        $targets = [];
        try {
            return Database::tx(function (PDO $pdo) use ($src, $courseId, $tenantId, &$files, &$map, &$targets): int {
                $newCourseId = self::insertId(
                    $pdo,
                    'INSERT INTO courses
                       (tenant_id, name, description, year, language, archived, archived_at,
                        structure_version, cc_mode, activity_mode, eval_after_activities, grading_mode, report_mode)
                     VALUES (?, ?, ?, ?, ?, 0, NULL, ?, ?, ?, ?, ?, ?)',
                    [
                        $tenantId, self::copyName((string) $src['name']), $src['description'], (int) $src['year'], $src['language'],
                        (int) $src['structure_version'],
                        $src['cc_mode'], $src['activity_mode'], (int) $src['eval_after_activities'],
                        $src['grading_mode'], $src['report_mode'],
                    ]
                );

                $st = $pdo->prepare(
                    'SELECT id, name, position FROM core_competencies WHERE course_id = ? ORDER BY position, id'
                );
                $st->execute([$courseId]);
                foreach ($st->fetchAll() as $cc) {
                    self::copyCcInto($pdo, (int) $cc['id'], (string) $cc['name'], (int) $cc['position'], $newCourseId, $tenantId, $files, $map, $targets);
                }

                self::applyRemaps($pdo, $targets, $map);

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
        $pdo = Database::pdo();
        $cc  = self::fetchCcOwned($pdo, $ccId, $actingTenantId);
        if ($cc === null) {
            return null;
        }
        $destTenantId = self::fetchEditableCourseTenant($pdo, $targetCourseId, $actingTenantId);
        if ($destTenantId === null) {
            return null;
        }

        $files   = [];
        $map     = [];
        $targets = [];
        try {
            return Database::tx(function (PDO $pdo) use ($ccId, $cc, $targetCourseId, $destTenantId, &$files, &$map, &$targets): int {
                $position = self::nextPosition($pdo, 'core_competencies', 'course_id', $targetCourseId);
                $newCcId  = self::copyCcInto($pdo, $ccId, (string) $cc['name'], $position, $targetCourseId, $destTenantId, $files, $map, $targets);
                self::applyRemaps($pdo, $targets, $map);
                return $newCcId;
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
        $pdo = Database::pdo();
        $cu  = self::fetchCuOwned($pdo, $cuId, $actingTenantId);
        if ($cu === null) {
            return null;
        }
        $destTenantId = self::fetchEditableCcTenant($pdo, $targetCcId, $actingTenantId);
        if ($destTenantId === null) {
            return null;
        }

        $files   = [];
        $map     = [];
        $targets = [];
        try {
            return Database::tx(function (PDO $pdo) use ($cuId, $cu, $targetCcId, $destTenantId, &$files, &$map, &$targets): int {
                $position = self::nextPosition($pdo, 'competence_units', 'core_competency_id', $targetCcId);
                $newCuId  = self::copyCuInto($pdo, $cuId, $cu, $position, $targetCcId, $destTenantId, $files, $map, $targets);
                // CU sozinha: o mapa tem so os anexos dela. Referencia a
                // outra unidade fica apontando pra origem — a unidade citada
                // nao veio nesta copia, nao ha id novo pra oferecer.
                self::applyRemaps($pdo, $targets, $map);
                return $newCuId;
            });
        } catch (\Throwable $e) {
            self::cleanupFiles($files);
            self::logFailure('copyCompetenceUnit', $e);
            return null;
        }
    }

    // -- Cópia de subárvore --------------------------------------------------

    /** Insere uma nova CC sob $destCourseId e copia suas CUs. Retorna novo id. */
    private static function copyCcInto(
        PDO $pdo,
        int $srcCcId,
        string $name,
        int $position,
        int $destCourseId,
        int $destTenantId,
        array &$files,
        array &$map,
        array &$targets
    ): int {
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
            self::copyCuInto($pdo, (int) $cu['id'], $cu, (int) $cu['position'], $newCcId, $destTenantId, $files, $map, $targets);
        }

        return $newCcId;
    }

    /**
     * Insere uma nova CU sob $destCcId e copia conteúdo, atividades, avaliação
     * e learning outcomes. Retorna novo id.
     *
     * @param array<string,mixed> $cu linha de competence_units (origem)
     */
    private static function copyCuInto(
        PDO $pdo,
        int $srcCuId,
        array $cu,
        int $position,
        int $destCcId,
        int $destTenantId,
        array &$files,
        array &$map,
        array &$targets
    ): int {
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

        self::copyContent($pdo, $srcCuId, $newCuId, $destTenantId, $files, $map, $targets);
        self::copyLessons($pdo, $srcCuId, $newCuId, $destCcId, $targets);
        self::copyActivities($pdo, $srcCuId, $newCuId, $destTenantId, $files);
        self::copyEvaluation($pdo, $srcCuId, $newCuId, $destTenantId, $files);
        self::copyLearningOutcomes($pdo, $srcCuId, $newCuId);

        return $newCuId;
    }

    /**
     * Reaponta, no fim da operação, todo `html` copiado pro acervo novo.
     *
     * **Por que no fim, e não durante.** O mapa de anexos é acumulado no
     * escopo INTEIRO da cópia, não por CU: conteúdo e lição citam à vontade
     * imagem de outra unidade (é o que o `ContentImageRehost` e o script de
     * reparo existem pra consertar, então produção tem esses dados). Se cada
     * CU reescrevesse com o próprio mapinha, a referência cruzada sobreviveria
     * apontando pro curso de ORIGEM — e o aluno do curso novo, que não está
     * matriculado no original, tomaria 404. Era o bug desta correção
     * sobrevivendo num subconjunto dos casos. Esperar o fim também resolve a
     * referência pra frente (CU-B citando anexo da CU-C, copiada depois).
     *
     * **Por que sobre o html ORIGINAL, numa passada só.** Reaplicar o mapa
     * sobre html já reescrito é inseguro: um id recém-inserido pode coincidir
     * com um id de origem que é chave do mapa de outra CU, e a segunda passada
     * o reescreveria de novo. Cada alvo guarda o html como veio da origem e é
     * remapeado exatamente uma vez, com o mapa fechado.
     *
     * Anexo fora do mapa (arquivo físico ausente na origem, ou de CU que não
     * entrou nesta cópia — caso do `copyCompetenceUnit`, que leva uma CU só)
     * fica intocado: aponta pro original, que é o melhor disponível.
     *
     * @param list<array{table:string,id:int,cu:int,html:string}> $targets
     * @param array<int,int>                                     $map
     */
    private static function applyRemaps(PDO $pdo, array $targets, array $map): void
    {
        if ($targets === [] || $map === []) {
            return;
        }

        $upd = [
            'contents' => $pdo->prepare('UPDATE contents SET html = ? WHERE id = ?'),
            'lessons'  => $pdo->prepare('UPDATE lessons  SET html = ? WHERE id = ?'),
        ];

        foreach ($targets as $t) {
            $novo = ContentImageRehost::remap($t['html'], $t['cu'], $map);
            if ($novo !== $t['html']) {
                $upd[$t['table']]->execute([$novo, $t['id']]);
            }
        }
    }

    /**
     * Copia a página de conteúdo (1:1) + anexos físicos da CU.
     *
     * Alimenta `$map` com `aid de origem => aid novo` e registra o conteúdo em
     * `$targets` pra que o `applyRemaps` reaponte o html no fim. Sem isso,
     * curso duplicado nascia com as imagens apontando pro ORIGINAL: o
     * professor via tudo (a rota dele autoriza por tenant) e o aluno do curso
     * novo tomava 404, porque a rota do aluno autoriza pela matrícula no curso
     * DONO do anexo. Mesmo bug que o `ContentImageRehost` conserta no save, e
     * aqui na raiz.
     *
     * @param array<int,int>                                     $map
     * @param list<array{table:string,id:int,cu:int,html:string}> $targets
     */
    private static function copyContent(
        PDO $pdo,
        int $srcCuId,
        int $destCuId,
        int $destTenantId,
        array &$files,
        array &$map,
        array &$targets
    ): void {
        $st = $pdo->prepare('SELECT id, html, published FROM contents WHERE competence_unit_id = ?');
        $st->execute([$srcCuId]);
        $content = $st->fetch();
        if ($content === false) {
            return;
        }

        // Insere com o html da origem: os anexos precisam do content_id pra
        // existir, e o reaponte espera o mapa completo (ver `applyRemaps`).
        $newContentId = self::insertId(
            $pdo,
            'INSERT INTO contents (competence_unit_id, html, published) VALUES (?, ?, ?)',
            [$destCuId, $content['html'], (int) $content['published']]
        );

        $targets[] = [
            'table' => 'contents',
            'id'    => $newContentId,
            'cu'    => $destCuId,
            'html'  => (string) $content['html'],
        ];

        $ast = $pdo->prepare(
            'SELECT id, filename, stored_path, mime, size_bytes FROM content_attachments WHERE content_id = ? ORDER BY id'
        );
        $ast->execute([(int) $content['id']]);

        foreach ($ast->fetchAll() as $att) {
            $ext     = pathinfo((string) $att['stored_path'], PATHINFO_EXTENSION);
            $suffix  = $ext !== '' ? '.' . $ext : '';
            $relDest = 'storage/uploads/tenant_' . $destTenantId . '/content/' . $destCuId . '/' . self::uuid4() . $suffix;

            // Arquivo de origem ausente: pula o anexo (não cria referência
            // órfã). Fica fora do mapa de propósito — a URL segue apontando
            // pro original em vez de citar um id que não existe.
            if (!self::physicalCopy((string) $att['stored_path'], $relDest, $files)) {
                continue;
            }
            $map[(int) $att['id']] = self::insertId(
                $pdo,
                'INSERT INTO content_attachments (content_id, filename, stored_path, mime, size_bytes) VALUES (?, ?, ?, ?, ?)',
                [$newContentId, $att['filename'], $relDest, $att['mime'], (int) $att['size_bytes']]
            );
        }
    }

    /**
     * Copia as lições da CU (curso V2).
     *
     * Não existia: duplicar curso V2 devolvia uma trilha VAZIA, com as
     * unidades e atividades no lugar e nenhuma lição — sem erro nenhum, porque
     * `lessons` simplesmente não era visitada.
     *
     * **Destino V1 recebe as lições em RASCUNHO.** Copiar publicado corromperia
     * o progresso do aluno: `StudentProgress` documenta "em V1 não existem
     * lições" como a razão de não ramificar por formato, e conta
     * `lessons WHERE published = 1` no denominador da CU. Como as telas do
     * aluno e do professor gateiam a trilha inteira em `$isV2`, a lição ficaria
     * invisível e nunca completável: a CU travaria abaixo de 100% pra sempre,
     * puxando a média do curso, e o professor não teria nem como despublicá-la.
     * Rascunho não entra no denominador, então o material chega intacto sem
     * mexer em progresso nenhum — e aparece pro professor publicar se o curso
     * virar V2 depois. (`copyCompetenceUnit` permite destino V1: o controller
     * não checa formato.)
     *
     * `lesson_completions` fica de fora por definição: é progresso de aluno.
     *
     * @param list<array{table:string,id:int,cu:int,html:string}> $targets
     */
    private static function copyLessons(PDO $pdo, int $srcCuId, int $destCuId, int $destCcId, array &$targets): void
    {
        $st = $pdo->prepare(
            'SELECT title, html, xp_value, published, position
               FROM lessons WHERE competence_unit_id = ? ORDER BY position, id'
        );
        $st->execute([$srcCuId]);
        $lessons = $st->fetchAll();
        if ($lessons === []) {
            return;
        }

        $destIsV2 = self::destCourseIsV2($pdo, $destCcId);

        foreach ($lessons as $lesson) {
            $newId = self::insertId(
                $pdo,
                'INSERT INTO lessons (competence_unit_id, title, html, xp_value, published, position)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $destCuId, $lesson['title'], $lesson['html'], (int) $lesson['xp_value'],
                    $destIsV2 ? (int) $lesson['published'] : 0,
                    (int) $lesson['position'],
                ]
            );

            $targets[] = [
                'table' => 'lessons',
                'id'    => $newId,
                'cu'    => $destCuId,
                'html'  => (string) $lesson['html'],
            ];
        }
    }

    /** O curso que contém a CC de destino é V2? Decide `published` da lição. */
    private static function destCourseIsV2(PDO $pdo, int $destCcId): bool
    {
        $st = $pdo->prepare(
            'SELECT c.structure_version
               FROM core_competencies cc
               JOIN courses c ON c.id = cc.course_id
              WHERE cc.id = ?
              LIMIT 1'
        );
        $st->execute([$destCcId]);
        return (int) $st->fetchColumn() === 2;
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
            'SELECT id, name, description, year, language, structure_version, cc_mode, activity_mode, eval_after_activities, grading_mode, report_mode
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

    /**
     * Acrescenta " (cópia)" ao nome do curso duplicado, truncando a BASE (não
     * o sufixo) para caber em courses.name VARCHAR(150). Garante que o marcador
     * "(cópia)" sempre apareça, mesmo para nomes próximos do limite.
     */
    private static function copyName(string $name): string
    {
        $suffix = ' (cópia)';
        $max    = 150;
        if (mb_strlen($name) + mb_strlen($suffix) > $max) {
            $name = mb_substr($name, 0, $max - mb_strlen($suffix));
        }
        return $name . $suffix;
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
