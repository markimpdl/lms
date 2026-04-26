<?php
declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Gera Reports PDF pós-feedback de avaliações em LO mode (E26 — F17).
 *
 * **Disponibilidade restrita:** tenants Actvet com curso `grading_mode='learning_outcomes'`
 * E `report_mode='skill_hub'` E todos os 5 LOs com nota lançada. Fora desse
 * cenário, retorna status sem gerar arquivo (caller decide o que fazer).
 *
 * Arquivo gerado em `storage/reports/eval_{evalId}_student_{studentId}_attempt_{N}.pdf`.
 * Path retornado é **relativo a LMS_ROOT** (gravado em `evaluation_submissions.report_pdf_path`).
 *
 * Re-correção (re-feedback do prof) sobrescreve o arquivo existente.
 *
 * Variáveis substituídas via `str_replace` (sintaxe `{{VAR_NAME}}`):
 *  - `{{NAME_COURSE}}`, `{{NAME_STUDENT}}`, `{{NAME_UC}}`
 *  - `{{WORKLOAD_UC}}` (formato `30h`)
 *  - `{{AVG_LEARN}}` (média × 10; inteiro quando exato, 1 decimal só se necessário)
 *  - `{{LEARN_1_NAME}}` … `{{LEARN_5_NAME}}` (descrições)
 *  - `{{LEARN_1_SCORE}}` … `{{LEARN_5_SCORE}}` (grade × 10; inteiro sempre)
 *
 * Variáveis fora do catálogo: ficam literais no PDF (debug-friendly, decisão da spec).
 *
 * **dompdf** rodando com `chroot` apontando pro template + `isRemoteEnabled=false`
 * — não abre URLs externas nem arquivos fora do template dir.
 */
final class ReportService
{
    private const REL_TEMPLATE_DIR = '/public/assets/report-templates/skill_hub';
    private const REL_OUTPUT_DIR   = '/storage/reports';
    private const REQUIRED_LO_COUNT = 5;

    /**
     * Gera o PDF de uma submissão.
     *
     * Status retornados:
     *   'ok'              — gerado; `path` contém o caminho relativo gravar
     *   'not_found'       — submissão não existe
     *   'not_eligible'    — tenant não é Actvet OU curso não-LO OU report_mode!='skill_hub'
     *   'not_ready'       — UC não tem 5 LOs cadastrados OU faltam grades
     *   'render_failed'   — dompdf falhou (mensagem em log; caller deve tratar best-effort)
     *
     * @return array{status:string, path?:string, error_key?:string}
     */
    public static function generate(int $submissionId): array
    {
        $ctx = self::loadContext($submissionId);
        if ($ctx === null) {
            return ['status' => 'not_found'];
        }

        if (!$ctx['eligible']) {
            return ['status' => 'not_eligible'];
        }

        if (count($ctx['los']) !== self::REQUIRED_LO_COUNT
            || count($ctx['grades']) !== self::REQUIRED_LO_COUNT) {
            return ['status' => 'not_ready'];
        }

        $vars = self::buildVariables($ctx);
        $templatePath = LMS_ROOT . self::REL_TEMPLATE_DIR . '/template.html';
        $template     = @file_get_contents($templatePath);
        if ($template === false) {
            return ['status' => 'render_failed', 'error_key' => 'reports.err.template_missing'];
        }

        $html = strtr($template, $vars);

        $relPath = self::REL_OUTPUT_DIR
            . '/eval_' . $ctx['evaluation_id']
            . '_student_' . $ctx['student_id']
            . '_attempt_' . $ctx['attempt']
            . '.pdf';
        $absPath = LMS_ROOT . $relPath;

        $outDir = dirname($absPath);
        if (!is_dir($outDir) && !@mkdir($outDir, 0775, true)) {
            return ['status' => 'render_failed', 'error_key' => 'reports.err.outdir_missing'];
        }

        try {
            self::renderPdf($html, $absPath);
        } catch (\Throwable $e) {
            error_log('[ReportService] dompdf failed for submission ' . $submissionId . ': ' . $e->getMessage());
            return ['status' => 'render_failed', 'error_key' => 'reports.err.render'];
        }

        return ['status' => 'ok', 'path' => ltrim($relPath, '/')];
    }

    /**
     * Carrega tudo que o template precisa numa única query + 2 satélites.
     *
     * @return array{
     *   evaluation_id:int, student_id:int, attempt:int,
     *   course_name:string, student_name:string, uc_name:string,
     *   workload_hours:int, eligible:bool,
     *   los:list<array{id:int, position:int, description:string}>,
     *   grades:array<int, float>
     * }|null
     */
    private static function loadContext(int $submissionId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT s.id AS submission_id, s.evaluation_id, s.student_user_id, s.attempt,
                    c.name AS course_name, c.grading_mode, c.report_mode,
                    cu.id AS cu_id, cu.name AS uc_name, cu.workload_hours,
                    u.name AS student_name,
                    t.is_actvet
               FROM evaluation_submissions s
               JOIN evaluations e        ON e.id  = s.evaluation_id
               JOIN competence_units cu  ON cu.id = e.competence_unit_id
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
               JOIN courses c            ON c.id  = cc.course_id
               JOIN tenants t            ON t.id  = e.tenant_id
               JOIN users u              ON u.id  = s.student_user_id
              WHERE s.id = ?
              LIMIT 1'
        );
        $stmt->execute([$submissionId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $eligible = (int) ($row['is_actvet'] ?? 0) === 1
            && (string) ($row['grading_mode'] ?? '') === 'learning_outcomes'
            && (string) ($row['report_mode']  ?? '') === 'skill_hub';

        $los    = LearningOutcome::findByCu((int) $row['cu_id']);
        $grades = LearningOutcome::findGradesBySubmission((int) $row['submission_id']);

        return [
            'evaluation_id'  => (int)    $row['evaluation_id'],
            'student_id'     => (int)    $row['student_user_id'],
            'attempt'        => (int)    $row['attempt'],
            'course_name'    => (string) $row['course_name'],
            'student_name'   => (string) $row['student_name'],
            'uc_name'        => (string) $row['uc_name'],
            'workload_hours' => (int)    $row['workload_hours'],
            'eligible'       => $eligible,
            'los'            => $los,
            'grades'         => $grades,
        ];
    }

    /**
     * Monta o mapa de substituição `{{VAR}}` → valor formatado, pronto pra
     * `strtr`. Inclui as 4 variáveis de cabeçalho + 1 de média + 10 dos
     * 5 LOs (NAME + SCORE).
     *
     * @param array<string,mixed> $ctx
     * @return array<string,string>
     */
    private static function buildVariables(array $ctx): array
    {
        // Soma das grades (DECIMAL(3,1) por LO; média também 1 decimal).
        $sum = 0.0;
        foreach ($ctx['grades'] as $g) {
            $sum += (float) $g;
        }
        $average = round($sum / max(1, count($ctx['grades'])), 1);

        $vars = [
            '{{NAME_COURSE}}'  => self::esc($ctx['course_name']),
            '{{NAME_STUDENT}}' => self::esc($ctx['student_name']),
            '{{NAME_UC}}'      => self::esc($ctx['uc_name']),
            '{{WORKLOAD_UC}}'  => self::formatWorkload((int) $ctx['workload_hours']),
            '{{AVG_LEARN}}'    => self::formatScore($average),
        ];

        // LOs ordenados por position 1..5. Se vier menos que 5 (defesa
        // redundante — caller já validou), preenche string vazia pros
        // remanescentes pra evitar template sujo.
        for ($i = 0; $i < self::REQUIRED_LO_COUNT; $i++) {
            $n   = $i + 1;
            $lo  = $ctx['los'][$i] ?? null;
            $loId = $lo !== null ? (int) $lo['id'] : 0;
            $description = $lo !== null ? (string) $lo['description'] : '';
            $grade = $loId > 0 ? ($ctx['grades'][$loId] ?? null) : null;

            $vars['{{LEARN_' . $n . '_NAME}}']  = self::esc($description);
            $vars['{{LEARN_' . $n . '_SCORE}}'] = $grade !== null ? self::formatScore((float) $grade) : '';
        }

        return $vars;
    }

    /**
     * Score × 10 com formatação específica:
     *  - Inteiro quando o resultado é inteiro (ex.: 8.0 × 10 = 80)
     *  - 1 decimal só se necessário (ex.: 8.5 × 10 = 85; 8.52 × 10 = 85.2)
     *  - Vírgula como separador decimal (PT-BR padrão do sistema)
     */
    private static function formatScore(float $score): string
    {
        $multiplied = $score * 10.0;
        // Trabalha em 1 decimal pra alinhar com DECIMAL(3,1) da coluna grade.
        $rounded = round($multiplied, 1);
        if ($rounded == (int) $rounded) {
            return (string) (int) $rounded;
        }
        return str_replace('.', ',', (string) $rounded);
    }

    /**
     * Workload em formato `30h` (sem espaço).
     */
    private static function formatWorkload(int $hours): string
    {
        return $hours . 'h';
    }

    /**
     * Escape HTML básico — descrições/nomes vêm do banco mas alguém pode
     * cadastrar com `<` ou `&` que quebraria o template ou abriria XSS no PDF.
     * Não usar `e()` (helper do projeto) porque o PDF não tem o mesmo contexto
     * de browser; htmlspecialchars básico basta.
     */
    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Renderiza o HTML em PDF e salva no path absoluto. dompdf gated com
     * chroot apontando pro template dir (impede acesso a outros files do
     * disco) e remote desativado (sem buscar URLs externas).
     */
    private static function renderPdf(string $html, string $absOutPath): void
    {
        $templateDir = LMS_ROOT . self::REL_TEMPLATE_DIR;

        $options = new Options();
        $options->set('chroot', $templateDir);
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->setBasePath($templateDir);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $output = $dompdf->output();
        if (file_put_contents($absOutPath, $output) === false) {
            throw new \RuntimeException('Failed to write PDF to ' . $absOutPath);
        }
    }
}
