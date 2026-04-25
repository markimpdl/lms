<?php
declare(strict_types=1);

/**
 * /teacher/settings — configurações globais do tenant do professor (E17-04).
 *
 * MVP: apenas estilo do avatar default (Árabe vs Ocidental). Mais opções
 * adicionadas futuramente (idioma padrão do tenant, branding, etc.).
 *
 * Auth + role pelo front controller. Tenant via `current_tenant_id()`.
 */

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$tenant = Tenant::findById($tenantId);
if ($tenant === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$errors = [];
$style  = (string) $tenant['avatar_style'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /teacher/settings');
        exit;
    }

    $newStyle = (string) ($_POST['avatar_style'] ?? '');
    if (!in_array($newStyle, ['arabe', 'ocidental'], true)) {
        $errors['avatar_style'] = 'settings.err.invalid_avatar_style';
    } else {
        Tenant::updateAvatarStyle($tenantId, $newStyle);
        flash('success', __t('settings.success'));
        header('Location: /teacher/settings', true, 303);
        exit;
    }
}

$page_title = __t('settings.title');

ob_start();
?>
<div class="row justify-content-center">
    <div class="col-12 col-md-10 col-lg-8">
        <h1 class="h4 mb-3"><?= e(__t('settings.title')) ?></h1>

        <div class="d-flex flex-wrap gap-2 mb-3">
            <a href="/teacher/settings/notifications" class="btn btn-outline-primary btn-sm">
                <?= e(__t('notifications.settings.link')) ?>
            </a>
        </div>

        <?php if ($errors !== []): ?>
            <div class="alert alert-danger" role="alert">
                <?= e(__t($errors['avatar_style'] ?? 'students.form.has_errors')) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="/teacher/settings" class="card card-body shadow-sm" novalidate>
            <?= csrf_field() ?>

            <h2 class="h6 mb-2"><?= e(__t('settings.avatar.title')) ?></h2>
            <p class="text-muted small mb-3"><?= e(__t('settings.avatar.help')) ?></p>

            <div class="row g-3 mb-3">
                <div class="col-12 col-md-6">
                    <label class="card h-100 p-3 d-flex flex-row align-items-center gap-3 <?= $style === 'arabe' ? 'border-primary' : '' ?>"
                           style="cursor: pointer;">
                        <input type="radio" name="avatar_style" value="arabe"
                               <?= $style === 'arabe' ? 'checked' : '' ?>
                               class="form-check-input mt-0">
                        <div class="flex-grow-1">
                            <div class="fw-semibold"><?= e(__t('settings.avatar.style.arabe')) ?></div>
                            <div class="d-flex gap-2 mt-2">
                                <img src="/assets/avatars/arabe-male.svg" alt="" width="56" height="56" loading="lazy"
                                     style="border-radius: 50%; background: #EEF2FF;">
                                <img src="/assets/avatars/arabe-female.svg" alt="" width="56" height="56" loading="lazy"
                                     style="border-radius: 50%; background: #FCE7F3;">
                            </div>
                        </div>
                    </label>
                </div>

                <div class="col-12 col-md-6">
                    <label class="card h-100 p-3 d-flex flex-row align-items-center gap-3 <?= $style === 'ocidental' ? 'border-primary' : '' ?>"
                           style="cursor: pointer;">
                        <input type="radio" name="avatar_style" value="ocidental"
                               <?= $style === 'ocidental' ? 'checked' : '' ?>
                               class="form-check-input mt-0">
                        <div class="flex-grow-1">
                            <div class="fw-semibold"><?= e(__t('settings.avatar.style.ocidental')) ?></div>
                            <div class="d-flex gap-2 mt-2">
                                <img src="/assets/avatars/ocidental-male.svg" alt="" width="56" height="56" loading="lazy"
                                     style="border-radius: 50%; background: #E0F2FE;">
                                <img src="/assets/avatars/ocidental-female.svg" alt="" width="56" height="56" loading="lazy"
                                     style="border-radius: 50%; background: #FFE4E6;">
                            </div>
                        </div>
                    </label>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary"><?= e(__t('common.save')) ?></button>
                <a href="/teacher" class="btn btn-outline-secondary"><?= e(__t('common.cancel')) ?></a>
            </div>
        </form>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
