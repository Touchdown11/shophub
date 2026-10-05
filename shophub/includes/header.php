<?php
/**
 * Storefront header. Expects $pageTitle / $pageDescription to be optional.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

$cartCount    = cart_contents()['count'];
$wishlistIds  = wishlist_ids();
$navCategories = categories_list();
$currentFile  = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($pageTitle ?? 'Home') . ' | ' . cfg('app.name')) ?></title>
<meta name="description" content="<?= e($pageDescription ?? cfg('app.tagline')) ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><rect width='64' height='64' rx='14' fill='%230b0b0d'/><text x='32' y='45' font-family='Georgia,serif' font-size='38' fill='%23ffc400' text-anchor='middle'>S</text></svg>">
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
<?php if (!empty($extraHead)) {
    echo $extraHead;
} ?>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>

<?php $announcement = trim((string)setting('announcement', '')); ?>
<?php if ($announcement !== ''): ?>
    <div class="announce-bar"><?= e($announcement) ?></div>
<?php endif; ?>

<div class="topbar">
    <div class="container topbar-inner">
        <span class="topbar-item">Free delivery on orders over <?= e(money((float)setting('free_shipping_over', 150))) ?></span>
        <span class="topbar-sep">•</span>
        <span class="topbar-item">30-day returns</span>
        <span class="topbar-spacer"></span>
        <div class="currency-switch" data-currency-switch>
            <?php foreach ([store_currency(), cfg('store.secondary', 'LKR')] as $cur): ?>
                <button type="button"
                        class="currency-btn<?= active_currency() === $cur ? ' is-active' : '' ?>"
                        data-currency="<?= e($cur) ?>"><?= e($cur) ?></button>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<header class="site-header">
    <div class="container header-inner">
        <button class="nav-toggle" type="button" aria-label="Menu" aria-expanded="false" data-nav-toggle>
            <span></span><span></span><span></span>
        </button>

        <a class="logo" href="<?= e(base_url('index.php')) ?>" aria-label="ShopHub home">
            <svg class="logo-mark" viewBox="0 0 48 40" aria-hidden="true">
                <rect x="6" y="6" width="36" height="28" rx="6" fill="#ffc400"/>
                <text x="24" y="28.5" text-anchor="middle" font-family="Georgia, serif" font-weight="700" font-size="19" fill="#0b0b0d">S</text>
            </svg>
            <span class="logo-text">Shop<span>Hub</span></span>
        </a>

        <form class="header-search" action="<?= e(base_url('shop.php')) ?>" method="get" role="search" data-search-form>
            <input type="search" name="q" id="search-input" placeholder="Search products, brands and more…"
                   value="<?= e((string)query('q')) ?>" autocomplete="off"
                   data-suggest-url="<?= e(base_url('ajax/search.php')) ?>">
            <button type="submit" aria-label="Search">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"/><path d="M16.5 16.5 21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
            <div class="suggest" data-suggest hidden></div>
        </form>

        <nav class="header-actions" aria-label="Account">
            <a class="icon-btn" href="<?= e(base_url('wishlist.php')) ?>" title="Wishlist">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20s-7-4.35-7-9.5A4.5 4.5 0 0 1 12 7a4.5 4.5 0 0 1 7 3.5C19 15.65 12 20 12 20z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                <span class="icon-badge" data-wishlist-count <?= $wishlistIds ? '' : 'hidden' ?>><?= count($wishlistIds) ?></span>
            </a>
            <a class="icon-btn" href="<?= e(base_url(auth_check() ? 'account.php' : 'login.php')) ?>" title="<?= auth_check() ? 'My account' : 'Sign in' ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8.5" r="3.6" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M4.8 20c1.2-3.6 4-5.4 7.2-5.4S18 16.4 19.2 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </a>
            <a class="icon-btn cart-btn" href="<?= e(base_url('cart.php')) ?>" title="Cart">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h2l2.2 10.2A2 2 0 0 0 10.16 18h7.2a2 2 0 0 0 1.96-1.62L21 9H7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><circle cx="10.5" cy="20.5" r="1.3" fill="currentColor"/><circle cx="17.5" cy="20.5" r="1.3" fill="currentColor"/></svg>
                <span class="icon-badge" data-cart-count <?= $cartCount ? '' : 'hidden' ?>><?= (int)$cartCount ?></span>
            </a>
        </nav>
    </div>

    <nav class="main-nav" aria-label="Categories">
        <div class="container nav-inner">
            <a class="nav-link<?= $currentFile === 'index.php' ? ' is-active' : '' ?>" href="<?= e(base_url('index.php')) ?>">Home</a>
            <a class="nav-link<?= $currentFile === 'shop.php' && !query('category') ? ' is-active' : '' ?>" href="<?= e(base_url('shop.php')) ?>">Shop All</a>
            <?php foreach (array_slice($navCategories, 0, 6) as $cat): ?>
                <a class="nav-link<?= query('category') === $cat['slug'] ? ' is-active' : '' ?>"
                   href="<?= e(base_url('shop.php?category=' . urlencode($cat['slug']))) ?>">
                    <?= e($cat['name']) ?><span class="nav-count"><?= (int)$cat['product_count'] ?></span>
                </a>
            <?php endforeach; ?>
            <span class="nav-spacer"></span>
            <a class="nav-link nav-sale" href="<?= e(base_url('shop.php?on_sale=1')) ?>">Sale</a>
            <a class="nav-link<?= $currentFile === 'contact.php' ? ' is-active' : '' ?>" href="<?= e(base_url('contact.php')) ?>">Contact</a>
        </div>
    </nav>
</header>

<div class="flash-wrap container">
    <?php foreach (take_flashes() as $flash): ?>
        <div class="flash flash-<?= e($flash['type']) ?>" role="status">
            <span><?= e($flash['message']) ?></span>
            <button type="button" class="flash-close" aria-label="Dismiss">&times;</button>
        </div>
    <?php endforeach; ?>
</div>

<main id="main">
