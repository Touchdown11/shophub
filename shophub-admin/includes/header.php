<?php
/**
 * ShopHub staff portal — page shell.
 *
 * Loads the storefront's design tokens first (one palette, one type scale),
 * then the portal stylesheet on top.
 */
require_once __DIR__ . '/bootstrap.php';

/* Pages that render before sign-in (login.php, install notices) set $publicShell. */
$publicShell = !empty($publicShell);

if (!$publicShell) {
    require_admin();
}

$admin   = $publicShell ? null : auth_user();
$current = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));

/* Badge counts: only what needs a human. */
$badges = [
    'orders.php'   => (int)Database::value("SELECT COUNT(*) FROM orders WHERE status IN ('pending','processing')"),
    'reviews.php'  => (int)Database::value("SELECT COUNT(*) FROM reviews WHERE status = 'pending'"),
    'messages.php' => (int)Database::value("SELECT COUNT(*) FROM messages WHERE status = 'new'"),
];

if ($publicShell) {
    $badges = [];
}

$nav = [
    'Dashboard'      => ['index.php', 'grid'],
    'Products'       => ['products.php', 'box'],
    'Categories'     => ['categories.php', 'tags'],
    'Orders'         => ['orders.php', 'cart'],
    'Reports'        => ['reports.php', 'chart'],
    'Customers'      => ['customers.php', 'users'],
    'Reviews'        => ['reviews.php', 'star'],
    'Coupons'        => ['coupons.php', 'ticket'],
    'Messages'       => ['messages.php', 'mail'],
    'Import / Export' => ['import-export.php', 'upload'],
    'Settings'       => ['settings.php', 'settings'],
];

$pageTitle    = $pageTitle ?? 'Dashboard';
$pageSubtitle = $pageSubtitle ?? '';
$flashes      = take_flashes_html();
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> · <?= e((string)setting('store_name', cfg('app.name', 'ShopHub'))) ?> staff</title>

    <!-- storefront tokens (palette, type, buttons), then the portal skin -->
    <link rel="stylesheet" href="<?= e(store_url('assets/css/style.css?v=' . asset_version(store_path('assets/css/style.css')))) ?>">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/admin.css?v=' . asset_version(__DIR__ . '/../assets/css/admin.css'))) ?>">
    <link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="8" fill="#ffc400"/><text x="16" y="23" font-family="Georgia,serif" font-size="20" font-weight="bold" text-anchor="middle" fill="#0b0b0d">S</text></svg>') ?>">
</head>
<body class="admin-body<?= $publicShell ? ' is-public' : '' ?>">

<a class="skip-link" href="#main">Skip to content</a>

<?php if ($publicShell): ?>
    <?= $flashes ?>
    <?= $content ?? '' ?>
<?php else: ?>

<header class="admin-topbar">
    <button type="button" class="admin-burger" data-admin-nav aria-label="Toggle navigation">
        <span></span><span></span><span></span>
    </button>

    <a class="admin-brand" href="<?= e(base_url('index.php')) ?>">
        <span class="admin-brand-mark">S</span>
        <span class="admin-brand-text">
            <strong><?= e((string)setting('store_name', cfg('app.name', 'ShopHub'))) ?></strong>
            <em>Staff portal</em>
        </span>
    </a>

    <div class="admin-topbar-spacer"></div>

    <a class="admin-ghost-link" href="<?= e(store_url('index.php')) ?>" target="_blank" rel="noopener">
        <?= admin_icon('external', 16) ?> View storefront
    </a>

    <div class="admin-user">
        <span class="admin-user-avatar"><?= e(strtoupper(substr((string)($admin['name'] ?? 'A'), 0, 1))) ?></span>
        <span class="admin-user-text">
            <strong><?= e((string)($admin['name'] ?? 'Staff')) ?></strong>
            <em><?= e((string)($admin['email'] ?? '')) ?></em>
        </span>
        <a class="admin-ghost-link" href="<?= e(base_url('logout.php')) ?>"><?= admin_icon('logout', 16) ?> Sign out</a>
    </div>
</header>

<div class="admin-shell">
    <aside class="admin-sidebar" data-admin-sidebar>
        <nav class="admin-nav">
            <?php foreach ($nav as $label => [$file, $icon]): ?>
                <a href="<?= e(base_url($file)) ?>" class="admin-nav-link<?= $current === $file ? ' is-active' : '' ?>">
                    <?= admin_icon($icon) ?>
                    <span><?= e($label) ?></span>
                    <?php if (!empty($badges[$file])): ?><b class="admin-badge"><?= (int)$badges[$file] ?></b><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="admin-sidebar-foot">
            <p class="muted small">Signed in as <strong><?= e((string)($admin['email'] ?? '')) ?></strong></p>
            <p class="muted small">Session ends after <?= (int)cfg('app.admin_session_minutes', 120) ?> minutes idle.</p>
        </div>
    </aside>

    <main class="admin-main" id="main">
        <div class="admin-page-head">
            <div>
                <h1><?= e($pageTitle) ?></h1>
                <?php if ($pageSubtitle !== ''): ?><p class="admin-subtitle"><?= e($pageSubtitle) ?></p><?php endif; ?>
            </div>
            <?php if (!empty($pageActions)): ?>
                <div class="admin-page-actions"><?= $pageActions ?></div>
            <?php endif; ?>
        </div>

        <?= $flashes ?>
<?php endif; ?>
