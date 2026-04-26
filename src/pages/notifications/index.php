<?php
declare(strict_types=1);

/**
 * /notifications — lista paginada de notificações do usuário logado (E10-01).
 *
 * Reusa `Notification::findForUser` / `countForUser`. Cada row é um form
 * POST pra `/notifications/mark-read` (marca + redireciona pro link).
 */

$user = current_user();
if ($user === null) {
    header('Location: /login');
    return;
}
$uid = (int) $user['id'];

$perPage = 20;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$total    = Notification::countForUser($uid);
$items    = Notification::findForUser($uid, $perPage, $offset);
$lastPage = max(1, (int) ceil($total / $perPage));

$page_title = __t('notifications.page.title');

// Aluno usa lms-student-area (sidebar + dark mode automatico via layout.php).
// Teacher e super-admin caem no layout container Bootstrap classico.
$isStudent = ($user['role'] ?? '') === 'student';

ob_start();
?>
<?php if (!$isStudent): ?><div class="row justify-content-center"><div class="col-12 col-lg-8"><?php endif; ?>
        <div class="d-flex align-items-center justify-content-between mb-3 gap-2">
            <h1 class="h4 m-0"><?= e(__t('notifications.page.title')) ?></h1>
            <?php if ($total > 0): ?>
                <form method="post" action="/notifications/mark-all-read" class="m-0">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <?= e(__t('notifications.bell.mark_all_read')) ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($items === []): ?>
            <div class="lms-notif-list">
                <div class="lms-notif-list__empty"><?= e(__t('notifications.page.empty')) ?></div>
            </div>
        <?php else: ?>
            <ul class="lms-notif-list">
                <?php foreach ($items as $n): ?>
                    <?php $isUnread = ($n['read_at'] ?? null) === null; ?>
                    <li class="lms-notif-row <?= $isUnread ? 'lms-notif-row--unread' : '' ?>">
                        <form method="post" action="/notifications/mark-read" class="m-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                            <button type="submit" class="lms-notif-row__btn">
                                <?php if ($isUnread): ?>
                                    <span class="lms-notif-row__dot" aria-hidden="true"></span>
                                <?php else: ?>
                                    <span class="lms-notif-row__spacer" aria-hidden="true"></span>
                                <?php endif; ?>
                                <span class="lms-notif-row__body">
                                    <h2 class="lms-notif-row__title"><?= e((string) $n['title']) ?></h2>
                                    <?php if (($n['body'] ?? '') !== ''): ?>
                                        <p class="lms-notif-row__text"><?= e((string) $n['body']) ?></p>
                                    <?php endif; ?>
                                    <span class="lms-notif-row__time"><?= e(format_short_datetime((string) $n['created_at'])) ?></span>
                                </span>
                            </button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($lastPage > 1): ?>
                <nav aria-label="<?= e(__t('notifications.page.pagination_label')) ?>" class="mt-3">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= max(1, $page - 1) ?>"><?= e(__t('notifications.page.prev')) ?></a>
                        </li>
                        <li class="page-item disabled">
                            <span class="page-link"><?= e(__t('notifications.page.page_x_of_y', ['x' => (string) $page, 'y' => (string) $lastPage])) ?></span>
                        </li>
                        <li class="page-item <?= $page >= $lastPage ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= min($lastPage, $page + 1) ?>"><?= e(__t('notifications.page.next')) ?></a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
<?php if (!$isStudent): ?></div></div><?php endif; ?>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
