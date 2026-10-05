<?php
/**
 * ShopHub — storefront home
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/partials.php';

$pageTitle       = 'Premium marketplace for everyday luxury';
$pageDescription = 'Shop electronics, fashion, home and beauty at ShopHub. Cash on delivery, 30-day returns and free shipping over ' . money((float)setting('free_shipping_over', 150)) . '.';

$wishlistIds = wishlist_ids();
$categories  = categories_list();
$featured    = search_products(['featured' => 1, 'limit' => 8])['rows'];
$newArrivals = search_products(['sort' => 'newest', 'limit' => 8])['rows'];
$topRated    = search_products(['sort' => 'rating', 'limit' => 4])['rows'];

if (count($featured) < 4) {
    $featured = search_products(['limit' => 8])['rows'];
}

$stats = [
    'products' => (int)Database::value('SELECT COUNT(*) FROM products WHERE is_active = 1'),
    'orders'   => (int)Database::value('SELECT COUNT(*) FROM orders'),
    'rating'   => round((float)Database::value('SELECT AVG(rating) FROM reviews WHERE status = \'approved\''), 1),
];

include __DIR__ . '/includes/header.php';
?>

<section class="hero">
    <div class="hero-glow" aria-hidden="true"></div>
    <div class="container hero-grid">
        <div class="hero-copy">
            <span class="eyebrow">New season · Curated weekly</span>
            <h1>Luxury that <em>earns</em> its price.</h1>
            <p class="hero-sub">
                Hand-picked electronics, fashion, home and beauty — vetted for build quality,
                priced without the boutique mark-up.
            </p>
            <div class="hero-cta">
                <a class="btn btn-gold btn-lg" href="<?= e(base_url('shop.php')) ?>">
                    Shop the collection
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h13M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
                <a class="btn btn-outline btn-lg" href="<?= e(base_url('shop.php?on_sale=1')) ?>">View offers</a>
            </div>
            <ul class="hero-stats">
                <li><strong><?= number_format($stats['products']) ?>+</strong><span>Products in stock</span></li>
                <li><strong><?= number_format($stats['orders']) ?>+</strong><span>Orders delivered</span></li>
                <li><strong><?= $stats['rating'] ?: '5.0' ?>/5</strong><span>Average rating</span></li>
            </ul>
        </div>

        <div class="hero-visual">
            <?php $heroProduct = $featured[0] ?? null; ?>
            <?php if ($heroProduct): ?>
                <?php $d = discount_percent($heroProduct); ?>
                <a class="hero-card" href="<?= e(base_url('product.php?slug=' . urlencode((string)$heroProduct['slug']))) ?>">
                    <img src="<?= e(product_image($heroProduct['image'] ?? null, $heroProduct['name'])) ?>" alt="<?= e($heroProduct['name']) ?>">
                    <div class="hero-card-body">
                        <span class="eyebrow">Editor's pick</span>
                        <h3><?= e($heroProduct['name']) ?></h3>
                        <div class="hero-card-price">
                            <span class="price-now"><?= e(money(product_price($heroProduct))) ?></span>
                            <?php if ($d): ?><span class="price-was"><?= e(money((float)$heroProduct['price'])) ?></span><?php endif; ?>
                        </div>
                    </div>
                </a>
                <?php foreach (array_slice($featured, 1, 2) as $mini): ?>
                    <a class="hero-mini" href="<?= e(base_url('product.php?slug=' . urlencode((string)$mini['slug']))) ?>">
                        <img src="<?= e(product_image($mini['image'] ?? null, $mini['name'])) ?>" alt="<?= e($mini['name']) ?>">
                        <div>
                            <strong><?= e($mini['name']) ?></strong>
                            <span class="price-now"><?= e(money(product_price($mini))) ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="trust-strip">
    <div class="container trust-grid">
        <div class="trust-item">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><circle cx="7" cy="18.5" r="1.7" fill="currentColor"/><circle cx="17.5" cy="18.5" r="1.7" fill="currentColor"/></svg>
            <div><strong>Free delivery</strong><span>On orders over <?= e(money((float)setting('free_shipping_over', 150))) ?></span></div>
        </div>
        <div class="trust-item">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l7 3v6c0 4-3 7-7 9-4-2-7-5-7-9V6z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9 12l2 2 4-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            <div><strong>Buyer protection</strong><span>Full refund guarantee</span></div>
        </div>
        <div class="trust-item">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16v10H4z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M4 10h16" stroke="currentColor" stroke-width="1.8"/></svg>
            <div><strong>Cash on delivery</strong><span>Pay when it arrives</span></div>
        </div>
        <div class="trust-item">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.3-5.6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M20 4v5h-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <div><strong>30-day returns</strong><span>No questions asked</span></div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="eyebrow">Browse by category</span>
                <h2>Shop the departments</h2>
            </div>
            <a class="link-more" href="<?= e(base_url('shop.php')) ?>">All departments →</a>
        </div>
        <div class="cat-grid">
            <?php foreach ($categories as $cat): ?>
                <a class="cat-card" href="<?= e(base_url('shop.php?category=' . urlencode((string)$cat['slug']))) ?>">
                    <img src="<?= e(product_image($cat['image'] ?? null, (string)$cat['name'])) ?>" alt="<?= e($cat['name']) ?>" loading="lazy">
                    <div class="cat-body">
                        <h3><?= e($cat['name']) ?></h3>
                        <span><?= (int)$cat['product_count'] ?> products</span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section section-tight">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="eyebrow">Handpicked</span>
                <h2>Featured this week</h2>
            </div>
            <div class="carousel-nav">
                <button type="button" class="round-btn" data-scroll="featured-rail" data-dir="-1" aria-label="Scroll left">‹</button>
                <button type="button" class="round-btn" data-scroll="featured-rail" data-dir="1" aria-label="Scroll right">›</button>
            </div>
        </div>
        <div class="rail" id="featured-rail">
            <?php foreach ($featured as $p): ?>
                <div class="rail-item"><?= product_card($p, $wishlistIds) ?></div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="promo-split">
            <div class="promo-gold">
                <span class="eyebrow dark">Members only</span>
                <h2>Take <em>15%</em> off your first order</h2>
                <p>Create an account and use the code below at checkout. Applies to everything in the store.</p>
                <div class="coupon-pill" data-copy="WELCOME15">
                    <code>WELCOME15</code>
                    <button type="button" data-copy-target="WELCOME15" aria-label="Copy coupon code">Copy</button>
                </div>
            </div>
            <div class="promo-dark">
                <span class="eyebrow">Best sellers</span>
                <h2>What shoppers are buying most</h2>
                <div class="mini-list">
                    <?php foreach ($topRated as $t): ?>
                        <a class="mini-row" href="<?= e(base_url('product.php?slug=' . urlencode((string)$t['slug']))) ?>">
                            <img src="<?= e(product_image($t['image'] ?? null, $t['name'])) ?>" alt="">
                            <div class="mini-row-body">
                                <strong><?= e(excerpt($t['name'], 34)) ?></strong>
                                <?= stars((float)($t['rating_avg'] ?? 0)) ?>
                            </div>
                            <span class="price-now"><?= e(money(product_price($t))) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section section-tight">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="eyebrow">Just landed</span>
                <h2>New arrivals</h2>
            </div>
            <a class="link-more" href="<?= e(base_url('shop.php?sort=newest')) ?>">See everything new →</a>
        </div>
        <div class="product-grid">
            <?php foreach (array_slice($newArrivals, 0, 8) as $p): ?>
                <?= product_card($p, $wishlistIds) ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="testimonial-grid">
            <?php
            $quotes = [
                ['Amara P.', 'Colombo', 'The packaging alone felt premium. Ordered on Tuesday, delivered Thursday — beautifully done.'],
                ['Ruwan S.', 'Kandy', 'Cash on delivery with zero fuss. The headphones were exactly as described and cheaper than the mall.'],
                ['Nadia F.', 'Galle', 'Returns were painless when I ordered the wrong size. Refund landed in two days. Customer for life.'],
            ];
            foreach ($quotes as $q): ?>
                <blockquote class="testimonial">
                    <?= stars(5) ?>
                    <p>“<?= e($q[2]) ?>”</p>
                    <footer><strong><?= e($q[0]) ?></strong><span class="muted"> — <?= e($q[1]) ?></span></footer>
                </blockquote>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
