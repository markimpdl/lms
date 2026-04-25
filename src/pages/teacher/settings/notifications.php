<?php
declare(strict_types=1);

/**
 * /teacher/settings/notifications — config de notificações por tenant
 * (E21-03). Matriz 8 eventos × 2 canais = 16 toggles. Default ON quando
 * tenant não tem rows.
 *
 * Auth + role pelo front controller. Tenant via `current_tenant_id()`.
 * Submit POST persiste 16 rows via REPLACE INTO (NotificationSetting::saveBulk).
 */

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /teacher/settings/notifications', true, 303);
        exit;
    }

    // Form com checkboxes: chave ausente = unchecked. Itera sobre a
    // whitelist pra construir payload completo (16 entries).
    $raw     = $_POST['notifications'] ?? [];
    $payload = [];
    foreach (NotificationService::EVENTS as $event) {
        $payload[$event] = [
            'bell'  => is_array($raw) && !empty($raw[$event]['bell']),
            'email' => is_array($raw) && !empty($raw[$event]['email']),
        ];
    }

    NotificationSetting::saveBulk($tenantId, $payload);
    flash('success', __t('notifications.settings.saved'));
    header('Location: /teacher/settings/notifications', true, 303);
    exit;
}

$settings = NotificationSetting::getAllForTenant($tenantId);

$page_title = __t('notifications.settings.title');

ob_start();
?>
<div class="row justify-content-center">
    <div class="col-12 col-md-10 col-lg-8">
        <h1 class="h4 mb-1"><?= e(__t('notifications.settings.title')) ?></h1>
        <p class="text-muted small mb-3"><?= e(__t('notifications.settings.subtitle')) ?></p>

        <form method="POST" action="/teacher/settings/notifications" class="card card-body shadow-sm" novalidate>
            <?= csrf_field() ?>

            <!-- Desktop: tabela -->
            <div class="table-responsive d-none d-md-block">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th><?= e(__t('notifications.settings.event_col')) ?></th>
                            <th class="text-center" style="width: 110px;">
                                <?= e(__t('notification.channel.bell')) ?>
                            </th>
                            <th class="text-center" style="width: 110px;">
                                <?= e(__t('notification.channel.email')) ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (NotificationService::EVENTS as $event): ?>
                            <tr>
                                <td><?= e(__t('notification.event.' . $event)) ?></td>
                                <td class="text-center">
                                    <input type="checkbox"
                                           name="notifications[<?= e($event) ?>][bell]"
                                           value="1"
                                           class="form-check-input"
                                           aria-label="<?= e(__t('notification.event.' . $event)) ?> · <?= e(__t('notification.channel.bell')) ?>"
                                           <?= $settings[$event]['bell'] ? 'checked' : '' ?>>
                                </td>
                                <td class="text-center">
                                    <input type="checkbox"
                                           name="notifications[<?= e($event) ?>][email]"
                                           value="1"
                                           class="form-check-input"
                                           aria-label="<?= e(__t('notification.event.' . $event)) ?> · <?= e(__t('notification.channel.email')) ?>"
                                           <?= $settings[$event]['email'] ? 'checked' : '' ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile: cards empilhados -->
            <div class="d-md-none">
                <?php foreach (NotificationService::EVENTS as $event): ?>
                    <div class="border rounded p-3 mb-2">
                        <div class="fw-semibold mb-2"><?= e(__t('notification.event.' . $event)) ?></div>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input type="checkbox"
                                       name="notifications[<?= e($event) ?>][bell]"
                                       value="1"
                                       id="m_<?= e($event) ?>_bell"
                                       class="form-check-input"
                                       <?= $settings[$event]['bell'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="m_<?= e($event) ?>_bell">
                                    <?= e(__t('notification.channel.bell')) ?>
                                </label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox"
                                       name="notifications[<?= e($event) ?>][email]"
                                       value="1"
                                       id="m_<?= e($event) ?>_email"
                                       class="form-check-input"
                                       <?= $settings[$event]['email'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="m_<?= e($event) ?>_email">
                                    <?= e(__t('notification.channel.email')) ?>
                                </label>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-3">
                <button type="submit" class="btn btn-primary"><?= e(__t('common.save')) ?></button>
                <a href="/teacher/settings" class="btn btn-outline-secondary">
                    <?= e(__t('common.cancel')) ?>
                </a>
            </div>
        </form>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
