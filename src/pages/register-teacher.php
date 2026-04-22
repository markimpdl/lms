<?php
declare(strict_types=1);

/**
 * /register-teacher — ponto de entrada público do cadastro de professor.
 *
 * No MVP, **sempre** mostra a mensagem de fechamento: o switch existe em
 * /admin/settings apenas para preparação, e o formulário público entra
 * pós-MVP (conforme AC de E2-05). Quando for implementar o cadastro
 * público de verdade, checar aqui `setting_get('public_registration')`
 * e renderizar o form se `on`.
 */

$page_title = __t('auth.register_teacher.title');

ob_start();
?>
<div class="row justify-content-center">
    <div class="col-12 col-sm-8 col-md-6 col-lg-5">
        <div class="card shadow-sm text-center">
            <div class="card-body p-4">
                <h1 class="h5 mb-3"><?= e(__t('auth.register_teacher.title')) ?></h1>
                <p class="mb-3"><?= e(__t('public_reg.closed')) ?></p>
                <a href="/login" class="btn btn-outline-primary">
                    <?= e(__t('auth.login')) ?>
                </a>
            </div>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();

require LMS_ROOT . '/src/templates/layout.php';
