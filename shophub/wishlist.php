<?php
/**
 * ShopHub — wishlist (works for guests via session, syncs on login)
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/partials.php';

$ids  = wishlist_ids();
$rows = [];
if ($ids) {
    $in   = implode(',', array_fill(0, count($ids), '?'));
    $rows = Database::all(
        "SELECT p.*, c.name AS category_name, c.slug AS category_slug
         FROM products p LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.id IN ($in) AND p.is_active = 1",
        $ids
    );
}

$pageTitle = 'Wishlist';
include __DIR__ . '/includes/header.php';
?>

<section class="page-head">
    <div class="container">
        <nav class="breadcrumb"><a href="<?= e(base_url('index.php')) ?>">Home</a><span>/</span><em>Wishlist</em></nav>
        <h1>Your wishlist</h1>
        <p class="muted">
            <?= count($rows) ?> saved item<?= count($rows) === 1 ? '' : 's' ?>
            <?php if (!auth_check()): ?>
                · <a href="<?= e(base_url('login.php')) ?>">sign in</a> to keep it across devices
            <?php endif; ?>
        </p>
    </div>
</section>

<div class="container section-tight-bottom">
    <?php if (!$rows): ?>
        <?= empty_state('Your wishlist is empty', 'Tap the heart icon on any product and it will wait for you here.', 'Find something you love', base_url('shop.php')) ?>
    <?php else: ?>
        <div class="product-grid">
            <?php foreach ($rows as $p): ?>
                <?= product_card($p, $ids) ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
