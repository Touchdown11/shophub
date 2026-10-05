<?php
/**
 * ShopHub — product detail page (gallery, variants, specs, reviews)
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/partials.php';

$slug = (string)query('slug');
$product = Database::row(
    'SELECT p.*, c.name AS category_name, c.slug AS category_slug
     FROM products p LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.slug = ? AND p.is_active = 1',
    [$slug]
);

if (!$product) {
    http_response_code(404);
    $pageTitle = 'Product not found';
    include __DIR__ . '/includes/header.php';
    echo '<div class="container section">' . empty_state(
        'We could not find that product',
        'It may have sold out or been moved. Try the catalog instead.',
        'Back to shop',
        base_url('shop.php')
    ) . '</div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

/* ---------------------------------------------------------- review submit -- */
if (is_post() && post('action') === 'review') {
    require_csrf();
    if (!auth_check()) {
        flash('warn', 'Please sign in to write a review.');
        redirect('login.php');
    }
    if (verification_required()) {
        flash('warn', 'Please confirm your email address before posting a review.');
        redirect('account.php?tab=profile');
    }
    $rating = max(1, min(5, (int)post('rating', 5)));
    $title  = (string)post('title');
    $body   = (string)post('body');

    if (mb_strlen($body) < 10) {
        flash('danger', 'Please write at least 10 characters in your review.');
    } else {
        $autoApprove = !setting_bool('reviews_require_approval', (bool)cfg('features.reviews_require_approval', true));
        $status = $autoApprove ? 'approved' : 'pending';
        $already = Database::value(
            'SELECT COUNT(*) FROM reviews WHERE product_id = ? AND user_id = ?',
            [(int)$product['id'], auth_id()]
        );
        if ($already) {
            Database::run(
                'UPDATE reviews SET rating = ?, title = ?, body = ?, status = ?, created_at = ? WHERE product_id = ? AND user_id = ?',
                [$rating, $title, $body, $status, now(), (int)$product['id'], auth_id()]
            );
            flash('success', 'Your review has been updated' . ($autoApprove ? '.' : ' and is awaiting approval.'));
        } else {
            Database::insert('reviews', [
                'product_id' => (int)$product['id'],
                'user_id'    => auth_id(),
                'name'       => (string)auth_user()['name'],
                'rating'     => $rating,
                'title'      => $title,
                'body'       => $body,
                'status'     => $status,
                'created_at' => now(),
            ]);
            flash('success', $autoApprove ? 'Thank you — your review is live.' : 'Thanks! Your review is awaiting moderation.');
        }
    }
    redirect('product.php?slug=' . urlencode($slug) . '#reviews');
}

/* ------------------------------------------------------------------- data -- */
$gallery  = product_gallery((int)$product['id']);
$images   = array_column($gallery, 'image_path');
array_unshift($images, $product['image']);
$images   = array_values(array_filter(array_unique($images)));

$options  = product_options((int)$product['id']);
$variants = product_variants((int)$product['id']);
$hasVariants = (bool)$variants;

$rating   = avg_rating((int)$product['id']);
$reviews  = Database::all(
    'SELECT * FROM reviews WHERE product_id = ? AND status = \'approved\' ORDER BY created_at DESC LIMIT 12',
    [(int)$product['id']]
);
$myReview = auth_check()
    ? Database::row('SELECT * FROM reviews WHERE product_id = ? AND user_id = ?', [(int)$product['id'], auth_id()])
    : null;

$related = search_products([
    'category' => (string)($product['category_slug'] ?? ''),
    'exclude'  => (int)$product['id'],
    'limit'    => 4,
])['rows'];

$specs = json_decode((string)($product['specs'] ?? ''), true);
if (!is_array($specs)) {
    $specs = [];
}

$wishlistIds = wishlist_ids();
$inWishlist  = in_array((int)$product['id'], $wishlistIds, true);
$discount    = discount_percent($product);

// With variants the headline price is the cheapest sellable combination.
$displayPrice = $hasVariants ? product_from_price($product) : product_price($product);
$totalStock   = $hasVariants ? variants_stock_total($product) : (int)$product['stock'];
$inStock      = $totalStock > 0;

// Payload the option picker uses to resolve a combination in the browser.
$variantPayload = array_map(static fn($v) => [
    'id'     => (int)$v['id'],
    'options' => $v['options'],
    'label'  => $v['label'],
    'stock'  => (int)$v['stock'],
    'sku'    => (string)$v['sku'],
    'price'  => money(variant_price($v, $product)),
    'image'  => $v['image'] !== '' ? product_image($v['image'], $product['name']) : '',
], $variants);

$pageTitle = $product['name'];
$pageDescription = excerpt($product['short_desc'] ?: $product['description'], 155);

include __DIR__ . '/includes/header.php';
?>

<section class="page-head page-head-tight">
    <div class="container">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= e(base_url('index.php')) ?>">Home</a><span>/</span>
            <a href="<?= e(base_url('shop.php')) ?>">Shop</a><span>/</span>
            <?php if ($product['category_slug']): ?>
                <a href="<?= e(base_url('shop.php?category=' . urlencode((string)$product['category_slug']))) ?>"><?= e($product['category_name']) ?></a><span>/</span>
            <?php endif; ?>
            <em><?= e(excerpt($product['name'], 46)) ?></em>
        </nav>
    </div>
</section>

<div class="container product-layout">
    <div class="product-gallery" data-gallery>
        <div class="gallery-main">
            <img src="<?= e(product_image($images[0] ?? null, $product['name'])) ?>" alt="<?= e($product['name']) ?>" data-gallery-main>
            <?php if ($discount): ?><span class="flag flag-gold gallery-flag">-<?= (int)$discount ?>% today</span><?php endif; ?>
        </div>
        <?php if (count($images) > 1): ?>
            <div class="gallery-thumbs">
                <?php foreach (array_slice($images, 0, 6) as $i => $img): ?>
                    <button type="button" class="gallery-thumb<?= $i === 0 ? ' is-active' : '' ?>"
                            data-gallery-src="<?= e(product_image($img, $product['name'])) ?>"
                            aria-label="View image <?= $i + 1 ?>">
                        <img src="<?= e(product_image($img, $product['name'])) ?>" alt="" loading="lazy">
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="product-info">
        <div class="product-topline">
            <a class="card-cat" href="<?= e(base_url('shop.php?category=' . urlencode((string)$product['category_slug']))) ?>"><?= e($product['category_name'] ?? 'ShopHub') ?></a>
            <?php if ($product['brand']): ?><span class="muted">· <?= e($product['brand']) ?></span><?php endif; ?>
        </div>

        <h1><?= e($product['name']) ?></h1>

        <div class="product-meta">
            <?= stars((float)$rating['avg']) ?>
            <span class="muted"><?= $rating['count'] ? e($rating['avg']) . ' from ' . (int)$rating['count'] . ' review' . ($rating['count'] === 1 ? '' : 's') : 'No reviews yet' ?></span>
            <?php if ($product['sku']): ?><span class="muted">· SKU <?= e($product['sku']) ?></span><?php endif; ?>
        </div>

        <p class="product-lede"><?= e($product['short_desc'] ?: excerpt($product['description'], 180)) ?></p>

        <div class="price-block">
            <span class="price-now price-xl" data-variant-price><?= e(money($displayPrice)) ?></span>
            <?php if ($discount): ?>
                <span class="price-was"><?= e(money((float)$product['price'])) ?></span>
                <span class="save-pill">Save <?= e(money((float)$product['price'] - product_price($product))) ?></span>
            <?php endif; ?>
        </div>
        <?php if (active_currency() !== store_currency()): ?>
            <p class="muted price-note">Charged in <?= e(store_currency()) ?> at checkout — approx. <?= e(money($displayPrice, store_currency())) ?>.</p>
        <?php endif; ?>

        <p class="stock-line <?= $inStock ? 'is-in' : 'is-out' ?>" data-variant-stockline>
            <span class="dot"></span>
            <span data-variant-stock>
                <?php if ($hasVariants): ?>
                    <?= $inStock ? 'In stock — choose your options' : 'Currently sold out' ?>
                <?php else: ?>
                    <?= $inStock ? 'In stock' . ((int)$product['stock'] <= 12 ? ' — only ' . (int)$product['stock'] . ' left' : '') : 'Currently sold out' ?>
                <?php endif; ?>
            </span>
        </p>

        <form class="buy-row" data-add-form <?= $hasVariants ? 'data-variant-form' : '' ?>>
            <?php if ($hasVariants): ?>
                <div class="variant-picker" data-variant-picker>
                    <?php foreach ($options as $option): ?>
                        <div class="variant-group" data-option-group>
                            <span class="variant-label">
                                <?= e($option['name']) ?>:
                                <em data-option-chosen="<?= e($option['name']) ?>"><?= e($option['values'][0] ?? '') ?></em>
                            </span>
                            <?php if (count($option['values']) <= 5): ?>
                                <div class="variant-values">
                                    <?php foreach ($option['values'] as $i => $value): ?>
                                        <button type="button"
                                                class="variant-value<?= $i === 0 ? ' is-active' : '' ?>"
                                                data-option-name="<?= e($option['name']) ?>"
                                                data-option-value="<?= e($value) ?>"><?= e($value) ?></button>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <select data-option-select data-option-name="<?= e($option['name']) ?>">
                                    <?php foreach ($option['values'] as $value): ?>
                                        <option value="<?= e($value) ?>"><?= e($value) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <p class="muted small" data-variant-sku></p>
                </div>
                <input type="hidden" name="variant_id" value="<?= (int)$variants[0]['id'] ?>" data-variant-id>
                <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
            <?php else: ?>
                <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
            <?php endif; ?>

            <div class="qty-stepper" data-qty>
                <button type="button" data-qty-down aria-label="Decrease quantity">−</button>
                <input type="number" name="qty" value="1" min="1" max="<?= max(1, $totalStock) ?>" aria-label="Quantity">
                <button type="button" data-qty-up aria-label="Increase quantity">+</button>
            </div>
            <button class="btn btn-gold btn-lg" type="submit" data-add-to-cart <?= $inStock ? '' : 'disabled' ?>>
                <?= $inStock ? 'Add to cart' : 'Sold out' ?>
            </button>
            <button class="btn btn-outline btn-lg" type="button" data-buy-now <?= $inStock ? '' : 'disabled' ?>>Buy it now</button>
            <button class="icon-btn wish-btn-lg<?= $inWishlist ? ' is-on' : '' ?>" type="button"
                    data-wishlist="<?= (int)$product['id'] ?>" aria-pressed="<?= $inWishlist ? 'true' : 'false' ?>"
                    aria-label="Toggle wishlist">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20s-7-4.35-7-9.5A4.5 4.5 0 0 1 12 7a4.5 4.5 0 0 1 7 3.5C19 15.65 12 20 12 20z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"/></svg>
            </button>
        </form>

        <?php if ($hasVariants): ?>
            <script type="application/json" data-variant-data><?= json_encode($variantPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
        <?php endif; ?>

        <ul class="assurance">
            <li><strong>Free delivery</strong> on orders over <?= e(money((float)setting('free_shipping_over', 150))) ?></li>
            <li><strong>Cash on delivery</strong>, card or bank transfer — your choice at checkout</li>
            <li><strong>30-day returns</strong> with prepaid pickup</li>
            <li><strong>Warranty</strong> handled locally by <?= e(cfg('app.name')) ?></li>
        </ul>
    </div>
</div>

<div class="container product-tabs" id="reviews">
    <div class="tabs" data-tabs>
        <button class="tab is-active" type="button" data-tab="desc">Description</button>
        <?php if ($specs): ?><button class="tab" type="button" data-tab="specs">Specifications</button><?php endif; ?>
        <button class="tab" type="button" data-tab="reviews">Reviews (<?= (int)$rating['count'] ?>)</button>
        <button class="tab" type="button" data-tab="shipping">Shipping &amp; returns</button>
    </div>

    <div class="tab-panel is-active" data-panel="desc">
        <div class="prose rich-text"><?= rich_text((string)$product['description']) ?></div>
    </div>

    <?php if ($specs): ?>
        <div class="tab-panel" data-panel="specs">
            <table class="spec-table">
                <tbody>
                <?php foreach ($specs as $key => $value): ?>
                    <tr><th><?= e((string)$key) ?></th><td><?= e((string)$value) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="tab-panel" data-panel="reviews">
        <div class="reviews-layout">
            <div class="reviews-list">
                <?php if (!$reviews): ?>
                    <p class="muted">No reviews yet. Be the first to share your experience.</p>
                <?php endif; ?>
                <?php foreach ($reviews as $r): ?>
                    <article class="review">
                        <header>
                            <strong><?= e($r['name']) ?></strong>
                            <span class="muted"><?= e(nice_date($r['created_at'])) ?></span>
                        </header>
                        <?= stars((float)$r['rating']) ?>
                        <?php if ($r['title']): ?><h4><?= e($r['title']) ?></h4><?php endif; ?>
                        <p><?= nl2br(e((string)$r['body'])) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>

            <aside class="review-form-wrap">
                <h3>Write a review</h3>
                <?php if (!auth_check()): ?>
                    <p class="muted">You need an account to post a review.</p>
                    <a class="btn btn-gold btn-block" href="<?= e(base_url('login.php')) ?>">Sign in</a>
                <?php else: ?>
                    <form method="post" class="review-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="review">
                        <label>Your rating</label>
                        <div data-star-input>
                            <?= stars((float)($myReview['rating'] ?? 5), true) ?>
                            <input type="hidden" name="rating" value="<?= (int)($myReview['rating'] ?? 5) ?>">
                        </div>
                        <label for="review-title">Headline</label>
                        <input type="text" id="review-title" name="title" maxlength="120" placeholder="Sums up your experience" value="<?= e((string)($myReview['title'] ?? '')) ?>">
                        <label for="review-body">Your review</label>
                        <textarea id="review-body" name="body" rows="4" required minlength="10" placeholder="What did you like or dislike?"><?= e((string)($myReview['body'] ?? '')) ?></textarea>
                        <button class="btn btn-gold btn-block" type="submit"><?= $myReview ? 'Update review' : 'Submit review' ?></button>
                        <?php if ($myReview): ?>
                            <p class="muted small">Status: <?= status_badge((string)$myReview['status']) ?></p>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </aside>
        </div>
    </div>

    <div class="tab-panel" data-panel="shipping">
        <div class="prose">
            <p><strong>Delivery.</strong> Orders placed before 2pm are dispatched the same working day. Standard delivery takes 2–4 working days and is free over <?= e(money((float)setting('free_shipping_over', 150))) ?>; orders below that are charged <?= e(money((float)setting('shipping_flat', 12))) ?>.</p>
            <p><strong>Payment.</strong> Pay by card, cash on delivery, or bank transfer using the reference on your confirmation page. We never store card details.</p>
            <p><strong>Returns.</strong> Changed your mind? Return any unused item within 30 days for a full refund to the original payment method.</p>
        </div>
    </div>
</div>

<?php if ($related): ?>
    <section class="section">
        <div class="container">
            <div class="section-head">
                <div>
                    <span class="eyebrow">You may also like</span>
                    <h2>More from <?= e($product['category_name'] ?? 'ShopHub') ?></h2>
                </div>
            </div>
            <div class="product-grid">
                <?php foreach ($related as $p): ?>
                    <?= product_card($p, $wishlistIds) ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
