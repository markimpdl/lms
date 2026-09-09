<?php
declare(strict_types=1);

/**
 * Reparo retroativo: imagens de conteúdo/lição apontando pra anexo de OUTRA
 * unidade (bug das "imagens que o professor vê e o aluno novo não").
 *
 * A rota que serve o anexo pro aluno autoriza pela matrícula no curso **dono
 * do anexo** — HTML colado de outra unidade só carrega pra quem estuda o
 * curso de origem. O fix definitivo está no save do professor
 * (`ContentImageRehost`, aplicado em lição nova/editada e no conteúdo da CU);
 * este script varre o que já está gravado e conserta sem precisar reabrir
 * cada tela.
 *
 * O que faz por linha afetada: copia o arquivo do anexo estranho pra
 * `storage/uploads/tenant_<tid>/content/<cu_id>/`, cria a linha nova em
 * `content_attachments` apontando pro conteúdo da CU certa e reescreve o
 * `src` no HTML. Nada é apagado — o anexo original continua servindo a
 * unidade de origem.
 *
 * **Escopo: o próprio tenant do professor logado, e nada além.** Super-admin
 * não roda — CLAUDE.md é explícito que ele "NÃO edita conteúdo dos
 * professores", e uma varredura cross-tenant num POST só seria isso. Quem
 * conserta o curso é quem o escreve.
 *
 * **Fica inerte por padrão.** `public/` é servido direto pelo .htaccess
 * (`RewriteCond %{REQUEST_FILENAME} -f`), então sem trava a URL ficaria de pé
 * pra qualquer professor autenticado enquanto o arquivo existisse. Só
 * responde com `ENABLE_REHOST_TOOL => true` em `config/env.php`.
 *
 * **USO (1 vez em prod):**
 *   1. O deploy já sobe este arquivo (`public/` não é filtrado)
 *   2. Ligar `ENABLE_REHOST_TOOL => true` em `config/env.php` (npm run upload-env)
 *   3. Abrir https://lms.rumo.info/_rehost_foreign_images.php logado como professor
 *   4. Conferir o relatório do GET e confirmar no botão
 *   5. **Desligar a flag** — e, quando não precisar mais, apagar este arquivo
 *      do REPO e redeployar (apagar só no servidor não resolve: o deploy
 *      seguinte o traria de volta)
 *
 * Rodar duas vezes é inofensivo: na segunda passada toda imagem já é anexo da
 * própria CU e o relatório vem vazio. Cada linha reescrita entra na auditoria
 * do curso (E33), igual ao save do editor.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

// Trava de existência: o arquivo pode ficar no servidor entre deploys, mas
// não responde sem a flag ligada de propósito. 404 seco, sem o template de
// erro — esse puxa o layout, que monta nav e emite token; o caminho
// desligado não deve depender de nada.
if (empty($GLOBALS['__ENV']['ENABLE_REHOST_TOOL'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404\n";
    exit;
}

require_auth();
$user = current_user();
$role = (string) ($user['role'] ?? '?');

// Só professor, e só no próprio tenant: aluno não escreve no acervo, e
// super-admin não edita conteúdo de professor (CLAUDE.md).
$onlyTenant = $role === 'teacher' ? current_tenant_id() : null;
if ($onlyTenant === null) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 — este reparo roda como professor, no próprio tenant.\n";
    exit;
}

$pdo = Database::pdo();

/**
 * As duas telas com picker de imagem, cada uma com SQL própria e estática —
 * nada de nome de tabela interpolado, e o tenant sempre ligado como
 * parâmetro obrigatório.
 *
 * `kind` acompanha a linha porque o UPDATE do POST é um por tabela.
 *
 * @return list<array<string,mixed>>
 */
function rfi_fetch_contents(PDO $pdo, int $onlyTenant): array
{
    $stmt = $pdo->prepare(
        "SELECT 'content' AS kind, t.id, t.html, t.competence_unit_id AS cu_id,
                cu.name AS label, c.tenant_id, c.id AS course_id,
                c.name AS course_name, cu.name AS cu_name
           FROM contents t
           JOIN competence_units cu  ON cu.id = t.competence_unit_id
           JOIN core_competencies cc ON cc.id = cu.core_competency_id
           JOIN courses c            ON c.id  = cc.course_id
          WHERE t.html LIKE '%/attachment/%'
            AND c.tenant_id = ?
          ORDER BY c.id, cu.id, t.id"
    );
    $stmt->execute([$onlyTenant]);
    return $stmt->fetchAll();
}

/** @return list<array<string,mixed>> */
function rfi_fetch_lessons(PDO $pdo, int $onlyTenant): array
{
    $stmt = $pdo->prepare(
        "SELECT 'lesson' AS kind, t.id, t.html, t.competence_unit_id AS cu_id,
                t.title AS label, c.tenant_id, c.id AS course_id,
                c.name AS course_name, cu.name AS cu_name
           FROM lessons t
           JOIN competence_units cu  ON cu.id = t.competence_unit_id
           JOIN core_competencies cc ON cc.id = cu.core_competency_id
           JOIN courses c            ON c.id  = cc.course_id
          WHERE t.html LIKE '%/attachment/%'
            AND c.tenant_id = ?
          ORDER BY c.id, cu.id, t.position, t.id"
    );
    $stmt->execute([$onlyTenant]);
    return $stmt->fetchAll();
}

$rows = array_merge(
    rfi_fetch_contents($pdo, $onlyTenant),
    rfi_fetch_lessons($pdo, $onlyTenant)
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: text/plain; charset=utf-8');

    // Sem try/catch isto sai como 500 em branco (display_errors off em prod):
    // o fluxo pede pra conferir o relatório antes de confirmar, então estourar
    // o TTL do token é o caminho provável, não o exótico.
    try {
        csrf_verify();
    } catch (RuntimeException) {
        echo "Token expirado. Recarregue a página e confirme de novo.\n";
        exit;
    }

    // Uma passada varre todo o conteúdo do tenant e faz hash de arquivo por
    // anexo candidato — pode passar do limite default de execução.
    @set_time_limit(0);

    // `html` é a única coluna tocada: publicação, XP e posição ficam como estão.
    $updContent = $pdo->prepare('UPDATE contents SET html = ? WHERE id = ?');
    $updLesson  = $pdo->prepare('UPDATE lessons  SET html = ? WHERE id = ?');

    $totalRows  = 0;
    $totalImgs  = 0;
    $totalSkip  = 0;
    $totalBlock = 0;

    foreach ($rows as $row) {
        $cuId   = (int) $row['cu_id'];
        $result = ContentImageRehost::apply(
            (string) $row['html'],
            $cuId,
            (int) $row['tenant_id']
        );

        $totalSkip  += $result['skipped'];
        $totalBlock += $result['blocked'];
        if ($result['rehosted'] === 0) {
            continue;
        }

        $upd = $row['kind'] === 'lesson' ? $updLesson : $updContent;
        $upd->execute([$result['html'], (int) $row['id']]);

        // Mesma trilha dos saves do editor (E33): reescrita em massa não pode
        // ser invisível pro professor dono do curso.
        course_audit(
            (int) $row['course_id'],
            'update',
            $row['kind'] === 'lesson' ? 'lesson' : 'content',
            (int) $row['id'],
            (string) ($row['label'] ?? '')
        );

        $totalRows++;
        $totalImgs += $result['rehosted'];

        printf(
            "%s #%d (CU %d, %s): %d imagem(ns) re-hospedada(s)\n",
            (string) $row['kind'],
            (int) $row['id'],
            $cuId,
            (string) $row['course_name'],
            $result['rehosted']
        );
    }

    echo "\nOK: {$totalRows} linha(s) atualizada(s), {$totalImgs} imagem(ns) re-hospedada(s).\n";
    if ($totalSkip > 0) {
        echo "{$totalSkip} URL(s) sem conserto possível — anexo apagado ou de outro professor;"
            . " reenvie a imagem pelo editor.\n";
    }
    if ($totalBlock > 0) {
        echo "{$totalBlock} imagem(ns) não couberam: a CU chegou ao teto de "
            . AttachmentStorage::MAX_ATTACHMENTS_PER_CU . " anexos. Apague anexo sem uso e rode de novo.\n";
    }
    echo "\nLEMBRE-SE: desligue ENABLE_REHOST_TOOL agora.\n";
    exit;
}

// GET: relatório do que seria mexido, sem escrever nada.
$report      = [];
$countRows   = 0;
$countImgs   = 0;
$countBroken = 0;

foreach ($rows as $row) {
    $scan = ContentImageRehost::scan(
        (string) $row['html'],
        (int) $row['cu_id'],
        (int) $row['tenant_id']
    );
    if ($scan['foreign'] === [] && $scan['unknown'] === []) {
        continue;
    }

    $foreign = [];
    foreach ($scan['foreign'] as $aid => $att) {
        $foreign[] = [
            'aid'      => (int) $aid,
            'owner_cu' => (int) $att['competence_unit_id'],
            'filename' => (string) $att['filename'],
        ];
    }

    $report[] = [
        'kind'    => $row['kind'] === 'lesson' ? 'Lição' : 'Conteúdo da CU',
        'id'      => (int) $row['id'],
        'label'   => (string) ($row['label'] ?? ''),
        'course'  => (string) $row['course_name'],
        'cu'      => (string) $row['cu_name'],
        'cu_id'   => (int) $row['cu_id'],
        'foreign' => $foreign,
        'unknown' => $scan['unknown'],
    ];
    $countRows++;
    $countImgs   += count($foreign);
    $countBroken += count($scan['unknown']);
}

?><!DOCTYPE html>
<html lang="pt"><head><meta charset="utf-8"><title>Reparo — imagens de outra unidade</title>
<style>
body{font-family:sans-serif;max-width:860px;margin:2rem auto;padding:0 1rem;line-height:1.5}
table{border-collapse:collapse;width:100%;font-size:.9rem}
th,td{border:1px solid #ddd;padding:.4rem .5rem;text-align:left;vertical-align:top}
th{background:#f4f4f4}
code{background:#f4f4f4;padding:0 .2rem}
</style>
</head><body>
<h1>Reparo retroativo — imagens apontando pra outra unidade</h1>
<p style="color:#666;font-size:.85rem">
    Logado como <strong><?= e((string) ($user['name'] ?? '?')) ?></strong>
    (role: <code><?= e($role) ?></code>) — escopo: tenant <?= (int) $onlyTenant ?>
</p>

<?php if ($report === []): ?>
    <p><strong>Nada a fazer.</strong> Nenhum conteúdo ou lição aponta pra anexo de outra unidade.</p>
<?php else: ?>
    <p>
        <strong><?= $countRows ?></strong> linha(s) afetada(s),
        <strong><?= $countImgs ?></strong> imagem(ns) a re-hospedar<?php if ($countBroken > 0): ?>,
        <strong><?= $countBroken ?></strong> URL(s) sem conserto (anexo apagado ou de outro tenant — precisam
        ser reenviadas à mão no editor)<?php endif; ?>.
    </p>
    <table>
        <thead><tr><th>Onde</th><th>Curso / CU</th><th>Imagens de outra unidade</th><th>Sem conserto</th></tr></thead>
        <tbody>
        <?php foreach ($report as $r): ?>
            <tr>
                <td><?= e($r['kind']) ?> #<?= $r['id'] ?><br><small><?= e(mb_substr($r['label'], 0, 60)) ?></small></td>
                <td><?= e($r['course']) ?><br><small><?= e($r['cu']) ?> (CU <?= $r['cu_id'] ?>)</small></td>
                <td>
                    <?php foreach ($r['foreign'] as $f): ?>
                        anexo <code><?= $f['aid'] ?></code> (CU <?= $f['owner_cu'] ?>) — <?= e($f['filename']) ?><br>
                    <?php endforeach; ?>
                    <?= $r['foreign'] === [] ? '—' : '' ?>
                </td>
                <td><?= $r['unknown'] === [] ? '—' : e(implode(', ', array_map('strval', $r['unknown']))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <form method="POST" style="margin-top:1.5rem">
        <?= csrf_field() ?>
        <button type="submit" style="padding:.5rem 1rem;background:#0a6;color:#fff;border:0;cursor:pointer">
            Re-hospedar agora
        </button>
    </form>
<?php endif; ?>

<hr>
<p><strong>Depois de rodar, apague este arquivo do servidor (FileZilla).</strong></p>
</body></html>
