<?php
declare(strict_types=1);

/**
 * /admin/settings — configurações globais do super-admin (E2-05).
 *
 * Por ora só cobre o switch de cadastro público. Auth + papel garantidos
 * pelo front controller via src/routes.php.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /admin/settings');
        exit;
    }

    $public = isset($_POST['public_registration']) ? 'on' : 'off';
    setting_set('public_registration', $public);

    flash(
        'success',
        __t(
            $public === 'on'
                ? 'admin.settings.public_reg_enabled'
                : 'admin.settings.public_reg_disabled'
        )
    );
    header('Location: /admin/settings', true, 303);
    exit;
}

$publicReg = setting_get('public_registration', 'off');

$page_title = __t('admin.settings.title');

ob_start();
?>
<div class="row justify-content-center">
    <div class="col-12 col-md-10 col-lg-8">
        <header class="lms-dashboard-header">
            <div>
                <span class="lms-dashboard-eyebrow"><?= e(__t('admin.settings.eyebrow')) ?></span>
                <h1 class="lms-dashboard-title"><?= e(__t('admin.settings.title')) ?></h1>
            </div>
        </header>

        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h6 mb-3"><?= e(__t('admin.settings.public_reg')) ?></h2>
                <p class="small text-muted"><?= e(__t('admin.settings.public_reg_hint')) ?></p>

                <form method="POST" action="/admin/settings" class="mb-0">
                    <?= csrf_field() ?>
                    <div class="form-check form-switch form-switch-lg mb-3">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="f-public-reg" name="public_registration"
                               <?= $publicReg === 'on' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="f-public-reg">
                            <?= e(__t(
                                $publicReg === 'on'
                                    ? 'admin.settings.public_reg_on'
                                    : 'admin.settings.public_reg_off'
                            )) ?>
                        </label>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <?= e(__t('common.save')) ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();

require LMS_ROOT . '/src/templates/layout.php';
