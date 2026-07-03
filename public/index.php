<?php
/**
 * トップページ
 *
 * TODO: ログイン状態に応じて店長メニュー / 従業員メニューへ自動振り分けする予定
 */

$pageTitle = 'トップページ';
$basePath  = '';
$showBack  = false;
$showHome  = false;
$mainClass = 'container container-landing';

require_once __DIR__ . '/../app/includes/header.php';
?>

<section class="landing">
    <div class="landing-logo" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" role="img">
            <polyline points="17 1 21 5 17 9"></polyline>
            <path d="M3 11V9a4 4 0 0 1 4-4h14"></path>
            <polyline points="7 23 3 19 7 15"></polyline>
            <path d="M21 13v2a4 4 0 0 1-4 4H3"></path>
        </svg>
    </div>

    <h1 class="landing-title">シフト代勤マッチング<wbr>支援システム</h1>

    <p class="landing-tagline">
        休みたい人と、代われる人を、かんたんマッチング。<br>
        従業員同士の連絡負担と、店長のシフト調整負担を軽減します。
    </p>

    <a class="btn btn-landing" href="login.php">ログインしてはじめる</a>
</section>

<?php require_once __DIR__ . '/../app/includes/footer.php'; ?>
