<?php
declare(strict_types=1);

/**
 * /teacher/cu/{id}/content/print — versão limpa do conteúdo da CU para
 * impressão / salvar como PDF.
 *
 * Página standalone (NÃO usa layout.php): documento HTML completo sem
 * header/footer/nav/sidebar do app, com CSS otimizado para impressão. Abre
 * em nova aba a partir de /teacher/cu/{id}. O teacher dispara a impressão
 * pelo botão da toolbar (escondida em @media print) e usa o próprio diálogo
 * do navegador para imprimir ou "Salvar como PDF".
 *
 * Mostra o conteúdo mesmo em rascunho (não publicado): é uma ferramenta de
 * revisão do professor, não exposição ao aluno. Auth: require_role('teacher')
 * já garantido pelo router; aqui resolvemos e validamos o tenant.
 */

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$cuId = (int) ($_REQUEST['id'] ?? 0);
$cu   = CompetenceUnit::findForTenant($cuId, $tenantId);
if ($cu === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$courseId = (int) $cu['course_id'];
$ccId     = (int) $cu['core_competency_id'];

$content    = Content::findForCu($cuId, $tenantId);
$hasContent = $content !== null;

// Nomes de contexto (curso › CC) — mesma fonte usada na tela da CU.
$tree       = curriculum_tree($courseId, $tenantId);
$courseName = (string) $tree['name'];
$ccName     = '';
foreach ($tree['ccs'] as $navCc) {
    if ((int) $navCc['id'] === $ccId) {
        $ccName = (string) $navCc['name'];
        break;
    }
}

$cuName = (string) $cu['name'];
$lang   = current_lang();
?><!doctype html>
<html lang="<?= e($lang) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e($cuName) ?></title>
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
    <style>
        :root { --ink: #1a1a1a; --muted: #6b7280; --line: #d1d5db; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            color: var(--ink);
            background: #f3f4f6;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
        }

        /* Barra de ações — escondida na impressão. */
        .print-toolbar {
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            gap: .5rem;
            align-items: center;
            justify-content: flex-end;
            padding: .75rem 1rem;
            background: #ffffff;
            border-bottom: 1px solid var(--line);
        }
        .print-toolbar .spacer { margin-right: auto; color: var(--muted); font-size: .875rem; }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: .5rem .9rem;
            font-size: .9rem;
            font-weight: 600;
            line-height: 1;
            border-radius: .4rem;
            border: 1px solid var(--line);
            background: #fff;
            color: var(--ink);
            text-decoration: none;
            cursor: pointer;
        }
        .btn--primary { background: #0d6efd; border-color: #0d6efd; color: #fff; }

        .sheet {
            max-width: 820px;
            margin: 1.5rem auto;
            padding: 2.5rem 3rem;
            background: #fff;
            border-radius: .4rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .1);
        }

        .doc-header { border-bottom: 2px solid var(--ink); padding-bottom: 1rem; margin-bottom: 1.75rem; }
        .doc-eyebrow {
            font-size: .8rem;
            font-weight: 600;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: var(--muted);
            margin: 0 0 .35rem;
        }
        .doc-title { font-size: 1.7rem; line-height: 1.25; margin: 0; }

        .doc-empty { color: var(--muted); text-align: center; padding: 3rem 0; }

        /* Conteúdo rico (já sanitizado em E5-01). */
        .content { font-size: 1rem; }
        .content :first-child { margin-top: 0; }
        .content h1, .content h2, .content h3, .content h4 { line-height: 1.3; margin: 1.6em 0 .5em; }
        .content p, .content ul, .content ol, .content table { margin: 0 0 1em; }
        .content img { max-width: 100%; height: auto; }
        .content table { width: 100%; border-collapse: collapse; }
        .content th, .content td { border: 1px solid var(--line); padding: .4rem .6rem; }
        .content blockquote {
            border-left: 3px solid var(--line);
            padding-left: 1rem;
            color: var(--muted);
            margin: 0 0 1em;
        }
        .content pre {
            background: #f6f8fa;
            border: 1px solid var(--line);
            padding: .75rem 1rem;
            border-radius: .3rem;
            overflow-x: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
            font-size: .85rem;
        }
        .content code { font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace; }
        .content :not(pre) > code { background: #f6f8fa; padding: .1rem .3rem; border-radius: .2rem; font-size: .9em; }
        .content iframe { width: 100%; aspect-ratio: 16 / 9; border: 0; max-width: 100%; }
        .content a { color: #0d6efd; }

        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .sheet {
                max-width: none;
                margin: 0;
                padding: 0;
                box-shadow: none;
                border-radius: 0;
            }
            .content a { color: var(--ink); text-decoration: underline; }
            /* Evita cortar elementos no meio entre páginas. */
            .content pre, .content table, .content blockquote, .content img { break-inside: avoid; }
            .content h1, .content h2, .content h3, .content h4 { break-after: avoid; }
            /* iframes (YouTube/Vimeo) não imprimem — mostra a URL como nota. */
            .content iframe { display: none; }
        }

        @page { margin: 16mm; }
    </style>
</head>
<body>
    <div class="print-toolbar no-print">
        <span class="spacer"><?= e($courseName) ?> › <?= e($ccName) ?></span>
        <a class="btn" href="/teacher/cu/<?= $cuId ?>"><?= e(__t('common.back')) ?></a>
        <button type="button" class="btn btn--primary" onclick="window.print()">
            <?= e(__t('content.print.action')) ?>
        </button>
    </div>

    <article class="sheet">
        <header class="doc-header">
            <p class="doc-eyebrow"><?= e($courseName) ?> › <?= e($ccName) ?></p>
            <h1 class="doc-title"><?= e($cuName) ?></h1>
        </header>

        <?php if ($hasContent): ?>
            <div class="content">
                <?= (string) $content['html'] /* HTML já sanitizado em E5-01 via ContentSanitizer */ ?>
            </div>
        <?php else: ?>
            <p class="doc-empty"><?= e(__t('content.none_yet')) ?></p>
        <?php endif; ?>
    </article>
</body>
</html>
