<?php
/**
 * 通知確認画面（店長用）
 *
 * - ログイン中の店長ユーザー宛の通知のみを表示する
 * - 代勤候補が見つからなかった場合の「候補者なし」通知もここで確認できる
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('manager');
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/includes/status_labels.php';
require_once __DIR__ . '/../../app/includes/notification_helpers.php';

$pageTitle = '通知確認';
$basePath  = '../../public/';

$user   = currentUser();
$userId = (int) $user['id'];

ensureNotificationFavoriteColumn($pdo);
cleanupOldReadNotifications($pdo, $userId);

$filter = normalizeNotificationFilter($_GET['filter'] ?? 'all');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$redirectUrl = notificationPageUrl($filter, $page);

// ------------------------------------------------------------
// POST処理（既読・削除）
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'mark_read' && $id > 0) {
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id')
            ->execute(['id' => $id, 'user_id' => $userId]);
    } elseif ($action === 'mark_read_async' && $id > 0) {
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id')
            ->execute(['id' => $id, 'user_id' => $userId]);

        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    } elseif ($action === 'delete' && $id > 0) {
        $pdo->prepare('DELETE FROM notifications WHERE id = :id AND user_id = :user_id')
            ->execute(['id' => $id, 'user_id' => $userId]);
    } elseif ($action === 'mark_all_read') {
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :user_id')
            ->execute(['user_id' => $userId]);
    } elseif ($action === 'delete_read') {
        // お気に入り登録済みの通知は一括削除の対象外とする
        $pdo->prepare('DELETE FROM notifications WHERE user_id = :user_id AND is_read = 1 AND is_favorite = 0')
            ->execute(['user_id' => $userId]);
    } elseif ($action === 'toggle_favorite' && $id > 0) {
        $pdo->prepare(
            'UPDATE notifications
             SET is_favorite = CASE WHEN is_favorite = 1 THEN 0 ELSE 1 END
             WHERE id = :id AND user_id = :user_id'
        )->execute(['id' => $id, 'user_id' => $userId]);
    }

    header('Location: ' . $redirectUrl);
    exit;
}

// ------------------------------------------------------------
// 自分（店長）宛の通知一覧
// ------------------------------------------------------------
$where = ['n.user_id = :user_id'];
$params = ['user_id' => $userId];
if ($filter === 'read') {
    $where[] = 'n.is_read = 1';
} elseif ($filter === 'unread') {
    $where[] = 'n.is_read = 0';
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM notifications n
     WHERE {$whereSql}"
);
$countStmt->execute($params);
$totalNotifications = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalNotifications / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    "SELECT n.*, lr.status AS leave_status
     FROM notifications n
     LEFT JOIN leave_requests lr ON lr.id = n.related_leave_request_id
     WHERE {$whereSql}
     ORDER BY n.created_at DESC, n.id DESC
     LIMIT :limit OFFSET :offset"
);
$stmt->bindValue('user_id', $userId, PDO::PARAM_INT);
$stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$notifications = $stmt->fetchAll();

require_once __DIR__ . '/../../app/includes/header.php';
?>

<p class="page-description">
    休み申請や代勤候補の抽出結果など、店長宛の通知を確認します。
</p>
<p class="page-description notification-auto-delete-note">
    既読の通知は90日後に自動削除されます。保存したい通知は、お気に入り登録してください。
</p>

<div class="notification-toolbar">
    <details class="notification-filter-menu">
        <summary class="notification-filter-button" aria-label="通知の表示条件">⇅</summary>
        <div class="notification-filter-panel">
            <?php foreach (['all', 'read', 'unread'] as $filterOption): ?>
                <a
                    class="notification-filter-link <?php echo $filter === $filterOption ? 'is-active' : ''; ?>"
                    href="<?php echo htmlspecialchars(notificationPageUrl($filterOption)); ?>"
                >
                    <?php echo htmlspecialchars(notificationFilterLabel($filterOption)); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </details>
    <details class="notification-menu">
        <summary class="notification-menu-button" aria-label="通知操作メニュー">…</summary>
        <div class="notification-menu-panel">
            <form method="post" action="<?php echo htmlspecialchars($redirectUrl); ?>" onsubmit="return confirm('すべての通知を既読にします。よろしいですか？');">
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="btn btn-secondary">すべて既読にする</button>
            </form>
            <form method="post" action="<?php echo htmlspecialchars($redirectUrl); ?>" onsubmit="return confirm('既読の通知をすべて削除します。よろしいですか？\n※お気に入り登録されている通知は削除されません。');">
                <input type="hidden" name="action" value="delete_read">
                <button type="submit" class="btn btn-secondary">既読をすべて削除</button>
            </form>
        </div>
    </details>
</div>

<div class="section">
    <p class="notification-count">
        <?php echo htmlspecialchars(notificationFilterLabel($filter)); ?>：
        <?php echo (int) $totalNotifications; ?>件
        <?php if ($totalNotifications > 0): ?>
            （<?php echo (int) $page; ?> / <?php echo (int) $totalPages; ?>ページ）
        <?php endif; ?>
    </p>
    <?php if (empty($notifications)): ?>
        <p class="page-description">通知はありません。</p>
    <?php else: ?>
        <div class="notification-list">
            <div class="notification-table-header">
                <span>通知</span>
                <span>操作</span>
            </div>
            <?php foreach ($notifications as $n): ?>
                <?php $detailId = 'notification-detail-' . (int) $n['id']; ?>
                <div class="notification-card <?php echo $n['is_read'] ? '' : 'is-unread'; ?>">
                    <div class="notification-summary-row">
                        <button
                            type="button"
                            class="notification-summary-button"
                            aria-haspopup="dialog"
                            data-notification-detail="<?php echo htmlspecialchars($detailId); ?>"
                            data-notification-title="<?php echo htmlspecialchars($n['title']); ?>"
                            data-notification-id="<?php echo (int) $n['id']; ?>"
                            data-read-state="<?php echo (int) $n['is_read']; ?>"
                        >
                            <span class="notification-title"><?php echo htmlspecialchars($n['title']); ?></span>
                            <span class="notification-meta">
                                <?php echo htmlspecialchars($n['created_at']); ?>
                                ・<?php echo htmlspecialchars(notificationTypeLabel($n['type'])); ?>
                            </span>
                            <span class="badge notification-status-badge <?php echo $n['is_read'] ? 'badge-inactive' : 'badge-active'; ?>">
                                <?php echo $n['is_read'] ? '既読' : '未読'; ?>
                            </span>
                        </button>
                        <div class="notification-actions">
                            <form method="post" action="<?php echo htmlspecialchars($redirectUrl); ?>">
                                <input type="hidden" name="action" value="toggle_favorite">
                                <input type="hidden" name="id" value="<?php echo (int) $n['id']; ?>">
                                <button
                                    type="submit"
                                    class="btn-icon-favorite <?php echo $n['is_favorite'] ? 'is-favorite' : ''; ?>"
                                    aria-label="<?php echo $n['is_favorite'] ? 'お気に入りを解除する' : 'お気に入りに登録する'; ?>"
                                >
                                    <?php echo $n['is_favorite'] ? '★' : '☆'; ?>
                                </button>
                            </form>
                            <form method="post" action="<?php echo htmlspecialchars($redirectUrl); ?>" onsubmit="return confirm('この通知を削除します。よろしいですか？');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int) $n['id']; ?>">
                                <button type="submit" class="btn-icon-danger" aria-label="通知を削除する">🗑</button>
                            </form>
                        </div>
                    </div>
                    <div id="<?php echo htmlspecialchars($detailId); ?>" class="notification-detail-source" hidden>
                        <p><?php echo nl2br(htmlspecialchars($n['message'])); ?></p>
                        <div class="notification-detail-actions">
                            <?php if (
                                in_array($n['type'], ['candidate_available', 'no_candidate'], true)
                                && $n['leave_status'] === 'cancelled'
                            ): ?>
                                <?php echo renderStatusBadge('キャンセル済み・対応不要', 'badge-inactive'); ?>
                            <?php elseif ($n['type'] === 'candidate_available'): ?>
                                <?php if ($n['related_leave_request_id'] !== null): ?>
                                    <a class="btn btn-secondary" href="approvals.php?from=notifications#lr-<?php echo (int) $n['related_leave_request_id']; ?>">承認画面で確認する</a>
                                <?php else: ?>
                                    <a class="btn btn-secondary" href="approvals.php?from=notifications">承認画面で確認する</a>
                                <?php endif; ?>
                            <?php elseif (in_array($n['type'], ['no_candidate', 'rematch_no_candidate'], true)): ?>
                                <?php echo renderStatusBadge('手動対応が必要', 'badge-warning'); ?>
                                <?php if ($n['related_leave_request_id'] !== null): ?>
                                    <a class="btn btn-secondary" href="approvals.php?from=notifications#lr-<?php echo (int) $n['related_leave_request_id']; ?>">承認画面で確認・再抽出する</a>
                                <?php else: ?>
                                    <a class="btn btn-secondary" href="approvals.php?from=notifications">承認画面で確認・再抽出する</a>
                                <?php endif; ?>
                            <?php elseif (in_array($n['type'], [
                                'after_approval_cancel_requested',
                                'substitute_cancel_requested',
                            ], true)): ?>
                                <a class="btn btn-secondary" href="cancellation_requests.php?from=notifications">キャンセル申請を確認する</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="calendar-modal" data-notification-modal hidden>
            <div class="calendar-modal-backdrop" data-notification-modal-close></div>
            <div class="calendar-modal-panel" role="dialog" aria-modal="true" aria-labelledby="notification-modal-title">
                <button type="button" class="calendar-modal-close" data-notification-modal-close aria-label="閉じる">×</button>
                <h3 id="notification-modal-title" data-notification-modal-title>通知の詳細</h3>
                <div class="calendar-modal-body" data-notification-modal-body></div>
            </div>
        </div>
        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="通知ページ">
                <?php if ($page > 1): ?>
                    <a class="pagination-link" href="<?php echo htmlspecialchars(notificationPageUrl($filter, $page - 1)); ?>">前へ</a>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a
                        class="pagination-link <?php echo $i === $page ? 'is-current' : ''; ?>"
                        href="<?php echo htmlspecialchars(notificationPageUrl($filter, $i)); ?>"
                    >
                        <?php echo (int) $i; ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a class="pagination-link" href="<?php echo htmlspecialchars(notificationPageUrl($filter, $page + 1)); ?>">次へ</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
document.addEventListener('click', function (event) {
    const modal = document.querySelector('[data-notification-modal]');
    if (!modal) {
        return;
    }

    if (event.target.closest('[data-notification-modal-close]')) {
        modal.hidden = true;
        document.body.classList.remove('calendar-modal-open');
        return;
    }

    const button = event.target.closest('[data-notification-detail]');
    if (!button) {
        return;
    }

    const detail = document.getElementById(button.dataset.notificationDetail);
    const title = modal.querySelector('[data-notification-modal-title]');
    const body = modal.querySelector('[data-notification-modal-body]');
    title.textContent = button.dataset.notificationTitle || '通知の詳細';
    body.innerHTML = detail ? detail.innerHTML : '';
    modal.hidden = false;
    document.body.classList.add('calendar-modal-open');

    if (button.dataset.readState === '1') {
        return;
    }

    const notificationId = button.dataset.notificationId;
    if (!notificationId) {
        return;
    }

    const params = new URLSearchParams();
    params.append('action', 'mark_read_async');
    params.append('id', notificationId);

    fetch('notifications.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: params.toString()
    }).then(function (response) {
        if (!response.ok) {
            return;
        }

        button.dataset.readState = '1';
        const card = button.closest('.notification-card');
        if (card) {
            card.classList.remove('is-unread');
        }

        const badge = button.querySelector('.notification-status-badge');
        if (badge) {
            badge.textContent = '既読';
            badge.classList.remove('badge-active');
            badge.classList.add('badge-inactive');
        }
    });
});

document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') {
        return;
    }

    const modal = document.querySelector('[data-notification-modal]');
    if (modal && !modal.hidden) {
        modal.hidden = true;
        document.body.classList.remove('calendar-modal-open');
    }
});
</script>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
