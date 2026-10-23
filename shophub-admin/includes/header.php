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

/* Detail screens keep their section lit in the rail. */
$navParent = ['order-view.php' => 'orders.php'];
$currentNav = $navParent[$current] ?? $current;

/* Badge counts: only what needs a human. */
$badges = [
    'orders.php'   => (int)Database::value("SELECT COUNT(*) FROM orders WHERE status IN ('pending','processing')"),
    'reviews.php'  => (int)Database::value("SELECT COUNT(*) FROM reviews WHERE status = 'pending'"),
    'messages.php' => (int)Database::value("SELECT COUNT(*) FROM messages WHERE status = 'new'"),
];

if ($publicShell) {
    $badges = [];
}

/* Day-to-day items first, in the order they are reached for; the two admin
   utilities (import/export, settings) sit at the bottom of the rail. */
$nav = [
    'Dashboard'      => ['index.php', 'grid'],
    'Products'       => ['products.php', 'box'],
    'Categories'     => ['categories.php', 'tags'],
    'Orders'         => ['orders.php', 'cart'],
    'Reports'        => ['reports.php', 'chart'],
    'Reviews'        => ['reviews.php', 'star'],
    'Customers'      => ['customers.php', 'users'],
    'Coupons'        => ['coupons.php', 'ticket'],
    'Messages'       => ['messages.php', 'mail'],
    'Import / Export' => ['import-export.php', 'upload'],
    'Settings'       => ['settings.php', 'settings'],
];

/* "ShopHub" with the second half in gold — the same wordmark the shop uses. */
$brandName = (string)setting('store_name', cfg('app.name', 'ShopHub'));
$hubAt     = stripos($brandName, 'hub');
$brandHtml = $hubAt === false
    ? e($brandName)
    : e(substr($brandName, 0, $hubAt)) . '<b>' . e(substr($brandName, $hubAt)) . '</b>';

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

<div class="admin-shell">
    <aside class="admin-sidebar" data-admin-sidebar>
        <a class="admin-brand" href="<?= e(base_url('index.php')) ?>">
            <span class="admin-brand-mark" aria-hidden="true">
                <svg viewBox="0 0 44 44">
                    <defs>
                        <linearGradient id="shubMark" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0" stop-color="#ffd451"/>
                            <stop offset="1" stop-color="#ffc400"/>
                        </linearGradient>
                    </defs>
                    <rect width="44" height="44" rx="13" fill="url(#shubMark)"/>
                    <text x="22" y="30" text-anchor="middle" font-family="Georgia, 'Times New Roman', serif" font-weight="700" font-size="24" fill="#0b0b0d">S</text>
                </svg>
            </span>
            <span class="admin-brand-text">
                <strong><?= $brandHtml ?></strong>
                <em>Admin</em>
            </span>
        </a>

        <nav class="admin-nav">
            <?php foreach ($nav as $label => [$file, $icon]): ?>
                <a href="<?= e(base_url($file)) ?>" class="admin-nav-link<?= $currentNav === $file ? ' is-active' : '' ?>">
                    <?= admin_icon($icon) ?>
                    <span><?= e($label) ?></span>
                    <?php if (!empty($badges[$file])): ?><b class="admin-badge"><?= (int)$badges[$file] ?></b><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="admin-sidebar-foot">
            <div class="admin-sidebar-user">
                <span class="admin-user-avatar"><?= e(strtoupper(substr((string)($admin['name'] ?? 'A'), 0, 1))) ?></span>
                <span class="admin-user-text">
                    <strong><?= e((string)($admin['name'] ?? 'Staff')) ?></strong>
                    <em><?= e((string)($admin['email'] ?? '')) ?></em>
                </span>
            </div>

            <a class="admin-nav-link" href="<?= e(store_url('index.php')) ?>" target="_blank" rel="noopener">
                <?= admin_icon('external') ?><span>View storefront</span>
            </a>
            <a class="admin-nav-link danger" href="<?= e(base_url('logout.php')) ?>">
                <?= admin_icon('logout') ?><span>Sign out</span>
            </a>
        </div>
    </aside>

    <main class="admin-main" id="main">
        <header class="admin-topbar">
            <button type="button" class="admin-burger" data-admin-burger aria-expanded="false" aria-label="Toggle navigation">
                <span></span><span></span><span></span>
            </button>

            <a class="admin-topbar-mark" href="<?= e(base_url('index.php')) ?>" aria-label="Dashboard">
                <svg viewBox="0 0 44 44" aria-hidden="true">
                    <rect width="44" height="44" rx="13" fill="#ffc400"/>
                    <text x="22" y="30" text-anchor="middle" font-family="Georgia, 'Times New Roman', serif" font-weight="700" font-size="24" fill="#0b0b0d">S</text>
                </svg>
            </a>

            <span class="admin-topbar-title"><strong><?= $brandHtml ?></strong> staff</span>

            <div class="admin-topbar-spacer"></div>

            <div class="admin-user">
                <span class="admin-user-avatar"><?= e(strtoupper(substr((string)($admin['name'] ?? 'A'), 0, 1))) ?></span>
                <span class="admin-user-text">
                    <strong><?= e((string)($admin['name'] ?? 'Staff')) ?></strong>
                    <em><?= e((string)($admin['email'] ?? '')) ?></em>
                </span>
            </div>
        </header>

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

        <div class="admin-content">
<?php endif; ?>
