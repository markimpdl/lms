<?php
declare(strict_types=1);

/**
 * Lista (somente leitura) dos arquivos de uma entrega, com link de download
 * e tamanho. Usada nas telas do aluno e do professor (atividade + avaliação).
 *
 * Variáveis esperadas (setadas pelo caller antes do require):
 *   $filesListItems  list<array{id:int,filename:string,size_bytes:int}>
 *                    (SubmissionFiles::listFor — id 0 = arquivo legado)
 *   $filesListBase   string  URL do download; ganha ?f=<id> quando id > 0
 *   $filesListClass  string  classe do <ul> (opcional)
 */
?>
<ul class="<?= e($filesListClass ?? 'list-unstyled mb-0') ?>">
    <?php foreach ($filesListItems as $__fl): ?>
        <li>
            <a href="<?= e($filesListBase . ($__fl['id'] > 0 ? '?f=' . $__fl['id'] : '')) ?>"><?= e($__fl['filename']) ?></a>
            <small class="text-muted">· <?= e(SubmissionFiles::humanSize($__fl['size_bytes'])) ?></small>
        </li>
    <?php endforeach; ?>
</ul>
