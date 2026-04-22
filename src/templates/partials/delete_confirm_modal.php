<?php
declare(strict_types=1);

/**
 * Modal genérico de exclusão com confirmação por digitação (E3-05).
 * Um único modal por página, populado via show.bs.modal a partir dos data-*
 * do botão clicado:
 *   data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"
 *   data-item-name="Curso X"
 *   data-action-url="/teacher/courses/10/delete"
 *   data-counts="<json com lista de strings já formatadas>"
 *
 * O botão "Excluir" do modal fica disabled até que o texto digitado case
 * exato (case-sensitive) com o nome esperado. Servidor revalida.
 */
?>
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <form method="POST" action="" id="deleteConfirmForm" class="modal-content" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="expected_name" id="deleteExpectedName" value="">

            <div class="modal-header">
                <h5 class="modal-title" id="deleteConfirmModalLabel"><?= e(__t('delete.modal.title')) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <p class="mb-2">
                    <?= e(__t('delete.modal.about_to_delete')) ?>
                    <strong id="deleteItemName"></strong>.
                </p>

                <div id="deleteCountsWrap" class="mb-3 d-none">
                    <p class="mb-1 small text-muted"><?= e(__t('delete.modal.will_also_delete')) ?></p>
                    <ul id="deleteCountsList" class="small mb-0"></ul>
                </div>

                <div class="alert alert-danger small mb-3" role="alert">
                    <?= e(__t('delete.modal.permanent_warning')) ?>
                </div>

                <label for="deleteConfirmInput" class="form-label">
                    <?= e(__t('delete.modal.type_to_confirm')) ?>
                </label>
                <input type="text" id="deleteConfirmInput" class="form-control form-control-lg"
                       placeholder="" autocomplete="off">
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    <?= e(__t('common.cancel')) ?>
                </button>
                <button type="submit" class="btn btn-danger" id="deleteSubmitBtn" disabled>
                    <?= e(__t('delete.modal.submit')) ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('deleteConfirmModal');
    if (!modal) return;

    var form        = document.getElementById('deleteConfirmForm');
    var nameEl      = document.getElementById('deleteItemName');
    var expectedEl  = document.getElementById('deleteExpectedName');
    var input       = document.getElementById('deleteConfirmInput');
    var submitBtn   = document.getElementById('deleteSubmitBtn');
    var countsWrap  = document.getElementById('deleteCountsWrap');
    var countsList  = document.getElementById('deleteCountsList');

    modal.addEventListener('show.bs.modal', function (event) {
        var btn = event.relatedTarget;
        if (!btn) return;

        var name = btn.getAttribute('data-item-name') || '';
        var url  = btn.getAttribute('data-action-url') || '';

        form.action           = url;
        nameEl.textContent    = name;
        expectedEl.value      = name;
        input.value           = '';
        input.placeholder     = name;
        submitBtn.disabled    = true;

        var counts = [];
        try { counts = JSON.parse(btn.getAttribute('data-counts') || '[]'); } catch (e) {}
        countsList.innerHTML = '';
        if (counts.length > 0) {
            counts.forEach(function (line) {
                var li = document.createElement('li');
                li.textContent = line;
                countsList.appendChild(li);
            });
            countsWrap.classList.remove('d-none');
        } else {
            countsWrap.classList.add('d-none');
        }

        setTimeout(function () { input.focus(); }, 250);
    });

    input.addEventListener('input', function () {
        submitBtn.disabled = input.value !== expectedEl.value;
    });
})();
</script>
