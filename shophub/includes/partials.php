<?php
/**
 * ShopHub — reusable storefront partials (product card, empty state…)
 */
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/variants.php';

function product_card(array $p, array $wishlistIds = []): string
{
    $inWishlist = in_array((int)$p['id'], $wishlistIds, true);
    $discount   = discount_percent($p);
    $rating     = round((float)($p['rating_avg'] ?? 0), 1);
    $hasVariants = (int)($p['variant_count'] ?? 0) > 0;
    // product_from_price() walks the variants; skip that query when we know the
    // product has none.
    $price      = $hasVariants ? product_from_price($p) : product_price($p);
    $stock      = $hasVariants ? variants_stock_total($p) : (int)$p['stock'];
    $url        = base_url('product.php?slug=' . urlencode((string)$p['slug']));

    ob_start(); ?>
    <article class="product-card" data-product-id="<?= (int)$p['id'] ?>">
        <div class="card-media">
            <a href="<?= e($url) ?>" aria-label="<?= e($p['name']) ?>">
                <img src="<?= e(product_image($p['image'] ?? null, $p['name'])) ?>" alt="<?= e($p['name']) ?>" loading="lazy">
            </a>
            <div class="card-flags">
                <?php if ($discount): ?><span class="flag flag-gold">-<?= (int)$discount ?>%</span><?php endif; ?>
                <?php if (!empty($p['is_featured'])): ?><span class="flag flag-dark">Featured</span><?php endif; ?>
                <?php if ($stock <= 0): ?><span class="flag flag-danger">Sold out</span><?php endif; ?>
            </div>
            <button class="wish-btn<?= $inWishlist ? ' is-on' : '' ?>" type="button"
                    data-wishlist="<?= (int)$p['id'] ?>"
                    aria-label="Add <?= e($p['name']) ?> to wishlist" aria-pressed="<?= $inWishlist ? 'true' : 'false' ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20s-7-4.35-7-9.5A4.5 4.5 0 0 1 12 7a4.5 4.5 0 0 1 7 3.5C19 15.65 12 20 12 20z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"/></svg>
            </button>
        </div>
        <div class="card-body">
            <span class="card-cat"><?= e($p['category_name'] ?? 'ShopHub') ?></span>
            <h3 class="card-title"><a href="<?= e($url) ?>"><?= e($p['name']) ?></a></h3>
            <div class="card-rating">
                <?= stars($rating) ?>
                <span class="muted">(<?= (int)($p['review_count'] ?? 0) ?>)</span>
            </div>
            <div class="card-price">
                <?php if ($discount): ?>
                    <span class="price-was"><?= e(money((float)$p['price'])) ?></span>
                <?php endif; ?>
                <?php if ($hasVariants): ?>
                    <span class="price-now"><small class="price-prefix">from</small> <?= e(money($price)) ?></span>
                <?php else: ?>
                    <span class="price-now"><?= e(money($price)) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($hasVariants): ?>
                <span class="muted small"><?= (int)$p['variant_count'] ?> option<?= (int)$p['variant_count'] === 1 ? '' : 's' ?> available</span>
            <?php endif; ?>
        </div>
        <div class="card-foot">
            <?php if ($hasVariants): ?>
                <a class="btn btn-outline btn-block" href="<?= e($url) ?>">Choose options</a>
            <?php else: ?>
                <button class="btn btn-gold btn-block" type="button"
                        data-add-to-cart="<?= (int)$p['id'] ?>" <?= $stock <= 0 ? 'disabled' : '' ?>>
                    <?= $stock <= 0 ? 'Out of stock' : 'Add to cart' ?>
                </button>
            <?php endif; ?>
        </div>
    </article>
    <?php
    return (string)ob_get_clean();
}

function empty_state(string $title, string $message, string $ctaLabel = '', string $ctaHref = ''): string
{
    ob_start(); ?>
    <div class="empty-state">
        <svg viewBox="0 0 120 120" aria-hidden="true" class="empty-art">
            <circle cx="60" cy="60" r="46" fill="none" stroke="#ffc400" stroke-opacity=".35" stroke-width="2"/>
            <path d="M42 52h36l-4 30H46z" fill="none" stroke="#ffc400" stroke-width="2.4" stroke-linejoin="round"/>
            <path d="M52 52v-6a8 8 0 0 1 16 0v6" fill="none" stroke="#ffc400" stroke-width="2.4"/>
        </svg>
        <h3><?= e($title) ?></h3>
        <p class="muted"><?= e($message) ?></p>
        <?php if ($ctaLabel): ?>
            <a class="btn btn-gold" href="<?= e($ctaHref) ?>"><?= e($ctaLabel) ?></a>
        <?php endif; ?>
    </div>
    <?php
    return (string)ob_get_clean();
}
