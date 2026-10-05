<?php
/**
 * ShopHub — checkout
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/orders.php';

$cart = cart_contents();
if (!$cart['items']) {
    flash('warn', 'Your cart is empty — add something before checking out.');
    redirect('cart.php');
}

if (query('cancelled')) {
    flash('warn', 'Payment was cancelled, so your order is still awaiting payment. You can try again from your order page.');
}

$user     = auth_user();
$defaults = [
    'name'           => $user['name'] ?? '',
    'email'          => $user['email'] ?? '',
    'phone'          => $user['phone'] ?? '',
    'address'        => '',
    'city'           => '',
    'postal_code'    => '',
    'country'        => 'Sri Lanka',
    'notes'          => '',
    'payment_method' => 'cod',
];

if ($user) {
    $address = Database::row(
        'SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC LIMIT 1',
        [(int)$user['id']]
    );
    if ($address) {
        $defaults = array_merge($defaults, [
            'name'        => $address['full_name'],
            'phone'       => $address['phone'],
            'address'     => $address['address'],
            'city'        => $address['city'],
            'postal_code' => $address['postal_code'],
            'country'     => $address['country'],
        ]);
    }
}

$errors = [];
$values = $defaults;
$methods = payment_methods_available();
if (!isset($methods[$values['payment_method']])) {
    $values['payment_method'] = array_key_first($methods) ?? 'cod';
}

if (is_post()) {
    require_csrf();
    foreach (array_keys($defaults) as $key) {
        $values[$key] = (string)post($key, $defaults[$key]);
    }

    $result = place_order($values);
    if ($result['ok']) {
        // Gateway orders go straight to the payment page; manual ones are done.
        if (!empty($result['gateway'])) {
            redirect('payment-start.php?order=' . (int)$result['order_id']);
        }
        redirect('order-success.php?order=' . (int)$result['order_id']);
    }
    $errors[] = $result['message'];
    flash('danger', $result['message']);
}

$pageTitle = 'Checkout';
include __DIR__ . '/includes/header.php';
?>

<section class="page-head">
    <div class="container">
        <nav class="breadcrumb"><a href="<?= e(base_url('cart.php')) ?>">Cart</a><span>/</span><em>Checkout</em></nav>
        <h1>Checkout</h1>
        <p class="muted">Pay by card, cash on delivery or bank transfer.</p>
    </div>
</section>

<div class="container checkout-layout">
    <form class="checkout-form" method="post" novalidate>
        <?= csrf_field() ?>

        <?php if ($errors): ?>
            <div class="flash flash-danger">
                <?php foreach ($errors as $err): ?><span><?= e($err) ?></span><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (verification_required()): ?>
            <div class="flash flash-warn">
                <span>Please <a href="<?= e(base_url('account.php?tab=profile')) ?>">confirm your email address</a> before placing an order.</span>
            </div>
        <?php endif; ?>

        <?php if (!auth_check()): ?>
            <div class="panel panel-inline">
                <p>Already have an account? <a href="<?= e(base_url('login.php')) ?>">Sign in</a> for faster checkout and order tracking — or continue as a guest below.</p>
            </div>
        <?php elseif (!auth_verified()): ?>
            <div class="panel panel-inline">
                <p>Your email is not confirmed yet. <a href="<?= e(base_url('account.php?tab=profile')) ?>">Resend the link</a> so we can send you order updates.</p>
            </div>
        <?php endif; ?>

        <div class="panel">
            <h2><span class="step-num">1</span> Contact details</h2>
            <div class="form-grid">
                <label class="field">
                    <span>Full name *</span>
                    <input type="text" name="name" value="<?= e($values['name']) ?>" required>
                </label>
                <label class="field">
                    <span>Email address *</span>
                    <input type="email" name="email" value="<?= e($values['email']) ?>" required>
                </label>
                <label class="field">
                    <span>Phone *</span>
                    <input type="tel" name="phone" value="<?= e($values['phone']) ?>" placeholder="07X XXX XXXX" required>
                </label>
            </div>
        </div>

        <div class="panel">
            <h2><span class="step-num">2</span> Delivery address</h2>
            <div class="form-grid">
                <label class="field field-wide">
                    <span>Street address *</span>
                    <input type="text" name="address" value="<?= e($values['address']) ?>" placeholder="House / street / apartment" required>
                </label>
                <label class="field">
                    <span>City *</span>
                    <input type="text" name="city" value="<?= e($values['city']) ?>" required>
                </label>
                <label class="field">
                    <span>Postal code</span>
                    <input type="text" name="postal_code" value="<?= e($values['postal_code']) ?>">
                </label>
                <label class="field">
                    <span>Country</span>
                    <input type="text" name="country" value="<?= e($values['country']) ?>">
                </label>
                <label class="field field-wide">
                    <span>Order notes (optional)</span>
                    <textarea name="notes" rows="3" placeholder="Gate code, preferred delivery window…"><?= e($values['notes']) ?></textarea>
                </label>
            </div>
        </div>

        <div class="panel">
            <h2><span class="step-num">3</span> Payment method</h2>
            <?php if (!$methods): ?>
                <p class="muted">No payment method is enabled — please contact the store.</p>
            <?php endif; ?>
            <div class="pay-options">
                <?php foreach ($methods as $key => $label): ?>
                    <label class="pay-option<?= $values['payment_method'] === $key ? ' is-active' : '' ?>">
                        <input type="radio" name="payment_method" value="<?= e($key) ?>" <?= $values['payment_method'] === $key ? 'checked' : '' ?>>
                        <div>
                            <strong><?= e($label) ?></strong>
                            <span>
                                <?php if ($key === 'cod'): ?>
                                    Pay the courier when your parcel arrives. Available island-wide.
                                <?php elseif ($key === 'bank_transfer'): ?>
                                    We email account details and ship as soon as funds clear.
                                <?php elseif ($key === 'stripe'): ?>
                                    Secure card payment on Stripe. You will be redirected to complete it.
                                <?php elseif ($key === 'payhere'): ?>
                                    Card, eZ Cash or mobile wallet via PayHere — Sri Lanka's local gateway.
                                <?php endif; ?>
                            </span>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (verification_required()): ?>
            <a class="btn btn-gold btn-lg btn-block" href="<?= e(base_url('account.php?tab=profile')) ?>">Confirm your email to order</a>
        <?php else: ?>
        <button class="btn btn-gold btn-lg btn-block" type="submit" <?= $methods ? '' : 'disabled' ?>>
            Place order · <?= e(money($cart['total'])) ?>
        </button>
        <?php endif; ?>
        <p class="muted small center">By placing this order you agree to our terms of sale and returns policy.</p>
    </form>

    <aside class="summary-card sticky">
        <h2>Your order</h2>
        <ul class="mini-cart-list">
            <?php foreach ($cart['items'] as $item): ?>
                <li>
                    <img src="<?= e(product_image($item['image'], $item['product']['name'])) ?>" alt="">
                    <div>
                        <strong><?= e(excerpt($item['product']['name'], 40)) ?></strong>
                        <span class="muted small">
                            <?= $item['label'] ? e($item['label']) . ' · ' : '' ?>Qty <?= (int)$item['qty'] ?>
                        </span>
                    </div>
                    <span><?= e(money($item['subtotal'])) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <dl class="summary-list">
            <div><dt>Subtotal</dt><dd><?= e(money($cart['subtotal'])) ?></dd></div>
            <?php if ($cart['discount'] > 0): ?>
                <div class="is-credit"><dt>Discount</dt><dd>−<?= e(money($cart['discount'])) ?></dd></div>
            <?php endif; ?>
            <div><dt>Delivery</dt><dd><?= $cart['shipping'] > 0 ? e(money($cart['shipping'])) : 'Free' ?></dd></div>
            <div><dt>Tax</dt><dd><?= e(money($cart['tax'])) ?></dd></div>
            <div class="summary-total"><dt>Total</dt><dd><?= e(money($cart['total'])) ?></dd></div>
        </dl>
        <a class="btn btn-ghost btn-block" href="<?= e(base_url('cart.php')) ?>">Edit cart</a>
    </aside>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
