<?php
/**
 * ShopHub — shopping cart (products and product variants)
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/partials.php';

if (is_post()) {
    require_csrf();
    $action = (string)post('action');

    if ($action === 'apply_coupon') {
        $code   = strtoupper((string)post('code'));
        $coupon = $code !== '' ? coupon_find($code) : null;
        if (!$coupon) {
            flash('danger', 'That coupon code is not valid or has expired.');
        } elseif (cart_contents()['subtotal'] < (float)$coupon['min_subtotal']) {
            flash('warn', 'This coupon needs a subtotal of at least ' . money((float)$coupon['min_subtotal']) . '.');
        } else {
            $_SESSION['coupon'] = $coupon['code'];
            flash('success', 'Coupon “' . $coupon['code'] . '” applied.');
        }
    } elseif ($action === 'remove_coupon') {
        unset($_SESSION['coupon']);
        flash('success', 'Coupon removed.');
    } elseif ($action === 'update') {
        foreach ((array)post('qty', []) as $key => $qty) {
            $key = (string)$key;
            $qty = (int)$qty;
            if (!isset($_SESSION['cart'][$key])) {
                continue;
            }
            if ($qty <= 0) {
                unset($_SESSION['cart'][$key]);
            } else {
                $_SESSION['cart'][$key] = $qty;
            }
        }
        flash('success', 'Cart updated.');
    } elseif ($action === 'clear') {
        unset($_SESSION['cart'], $_SESSION['coupon']);
        flash('success', 'Cart emptied.');
    }
    redirect('cart.php');
}

$cart = cart_contents();
$pageTitle = 'Your cart';

include __DIR__ . '/includes/header.php';
?>

<section class="page-head">
    <div class="container">
        <nav class="breadcrumb"><a href="<?= e(base_url('index.php')) ?>">Home</a><span>/</span><em>Cart</em></nav>
        <h1>Shopping cart</h1>
        <p class="muted" data-cart-count-label><?= (int)$cart['count'] ?> item<?= $cart['count'] === 1 ? '' : 's' ?> in your bag</p>
    </div>
</section>

<div class="container">
<?php if (!$cart['items']): ?>
    <?= empty_state('Your cart is empty', 'Browse the collection and add something you love.', 'Start shopping', base_url('shop.php')) ?>
<?php else: ?>
    <div class="cart-layout">
        <form method="post" class="cart-lines">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">

            <?php foreach ($cart['items'] as $item): $p = $item['product']; ?>
                <article class="cart-line" data-line="<?= e($item['key']) ?>">
                    <a class="cart-thumb" href="<?= e(base_url('product.php?slug=' . urlencode((string)$p['slug']))) ?>">
                        <img src="<?= e(product_image($item['image'], $p['name'])) ?>" alt="<?= e($p['name']) ?>">
                    </a>
                    <div class="cart-line-body">
                        <span class="card-cat"><?= e($p['brand'] ?: 'ShopHub') ?></span>
                        <h3><a href="<?= e(base_url('product.php?slug=' . urlencode((string)$p['slug']))) ?>"><?= e($p['name']) ?></a></h3>
                        <?php if ($item['label']): ?>
                            <p class="variant-chip"><?= e($item['label']) ?></p>
                        <?php endif; ?>
                        <p class="muted small"><?= e(excerpt($p['short_desc'], 90)) ?></p>
                        <?php if ($item['sku']): ?>
                            <p class="muted small">SKU <?= e($item['sku']) ?></p>
                        <?php endif; ?>
                        <div class="cart-line-controls">
                            <div class="qty-stepper" data-qty>
                                <button type="button" data-qty-down aria-label="Decrease">−</button>
                                <input type="number" name="qty[<?= e($item['key']) ?>]" value="<?= (int)$item['qty'] ?>"
                                       min="0" max="<?= max(1, (int)$item['stock']) ?>" aria-label="Quantity for <?= e($p['name']) ?>">
                                <button type="button" data-qty-up aria-label="Increase">+</button>
                            </div>
                            <button class="link-danger" type="button" data-remove-line="<?= e($item['key']) ?>">Remove</button>
                        </div>
                    </div>
                    <div class="cart-line-price">
                        <span class="price-now"><?= e(money($item['subtotal'])) ?></span>
                        <?php if ($item['qty'] > 1): ?>
                            <span class="muted small"><?= e(money($item['price'])) ?> each</span>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>

            <div class="cart-actions">
                <button class="btn btn-outline" type="submit">Update cart</button>
                <a class="btn btn-ghost" href="<?= e(base_url('shop.php')) ?>">Continue shopping</a>
                <button class="link-danger" type="submit" name="action" value="clear">Empty cart</button>
            </div>
        </form>

        <aside class="summary-card">
            <h2>Order summary</h2>
            <dl class="summary-list">
                <div><dt>Subtotal</dt><dd data-cart-subtotal><?= e(money($cart['subtotal'])) ?></dd></div>
                <?php if ($cart['discount'] > 0): ?>
                    <div class="is-credit"><dt>Discount <span class="badge badge-gold"><?= e((string)$cart['coupon']['code']) ?></span></dt><dd data-cart-discount>−<?= e(money($cart['discount'])) ?></dd></div>
                <?php endif; ?>
                <div><dt>Delivery</dt><dd data-cart-shipping><?= $cart['shipping'] > 0 ? e(money($cart['shipping'])) : 'Free' ?></dd></div>
                <div><dt>Tax (<?= e(number_format((float)setting('tax_percent', 0), 0)) ?>%)</dt><dd data-cart-tax><?= e(money($cart['tax'])) ?></dd></div>
                <div class="summary-total"><dt>Total</dt><dd data-cart-total><?= e(money($cart['total'])) ?></dd></div>
            </dl>

            <a class="btn btn-gold btn-block btn-lg" href="<?= e(base_url('checkout.php')) ?>">Proceed to checkout</a>

            <form method="post" class="coupon-form">
                <?= csrf_field() ?>
                <?php if ($cart['coupon']): ?>
                    <input type="hidden" name="action" value="remove_coupon">
                    <div class="coupon-applied">
                        <span>Code <strong><?= e((string)$cart['coupon']['code']) ?></strong> applied</span>
                        <button class="link-danger" type="submit">Remove</button>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="action" value="apply_coupon">
                    <label for="coupon">Have a coupon?</label>
                    <div class="coupon-row">
                        <input type="text" id="coupon" name="code" placeholder="WELCOME15">
                        <button class="btn btn-outline" type="submit">Apply</button>
                    </div>
                    <p class="muted small">Try <code>WELCOME15</code> for 15% off your first order.</p>
                <?php endif; ?>
            </form>
        </aside>
    </div>
<?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
