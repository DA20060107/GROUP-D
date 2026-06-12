<?php
/**
 * 承認画面（店長用）
 *
 * TODO: leave_requests / substitute_candidates を参照し、承認・却下処理を approvals テーブルへ記録する予定
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('manager');

$pageTitle = '承認';
$basePath  = '../../public/';

require_once __DIR__ . '/../../app/includes/header.php';
?>

<p class="page-description">
    代勤候補からの回答内容を確認し、代勤の最終承認を行います。
</p>

<div class="section">
    <table>
        <thead>
            <tr>
                <th>休み申請者</th>
                <th>対象シフト</th>
                <th>代勤候補</th>
                <th>候補の回答</th>
                <th>状態</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>佐藤 花子</td>
                <td>2026-06-15 17:00-22:00</td>
                <td>鈴木 次郎</td>
                <td>対応可能</td>
                <td>承認待ち</td>
                <td>
                    <a class="btn" href="#">承認（仮）</a>
                    <a class="btn btn-secondary" href="#">却下（仮）</a>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
