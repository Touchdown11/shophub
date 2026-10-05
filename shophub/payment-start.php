<?php
/**
 * ShopHub — hand the customer to the payment gateway.
 *
 * Reached straight after checkout for gateway orders, and again from
 * payment-retry.php when a previous attempt was cancelled.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/orders.php';
require_once __DIR__ . '/includes/payments.php';

$orderId = (int)query('order');
$order   = $orderId ? find_order($orderId) : null;

if (!$order) {
    flash('warn', 'We could not find that order.');
    redirect('index.php');
}

// Only the shopper who placed it (or a guest holding the same session) may pay.
$owns = (isset($_SESSION['last_order_id']) && (int)$_SESSION['last_order_id'] === $orderId)
    || (auth_check() && (int)($order['user_id'] ?? 0) === auth_id());
if (!$owns) {
    flash('warn', 'That order is not available in this session.');
    redirect('index.php');
}

if ($order['payment_status'] === 'paid') {
    redirect('order-success.php?order=' . $orderId);
}

$method = (string)$order['payment_method'];
if (!is_gateway_method($method)) {
    redirect('order-success.php?order=' . $orderId);
}

$pageTitle = 'Complete your payment';
include __DIR__ . '/includes/header.php';

$error = '';

if ($method === 'stripe') {
    $session = stripe_create_checkout($order);
    if ($session['ok'] && !empty($session['url'])) {
        Database::run('UPDATE orders SET payment_ref = ? WHERE id = ?', [(string)($session['url'] ?? ''), $orderId]);
        redirect_absolute($session['url']);
    }
    $error = $session['message'];
} elseif ($method === 'payhere') {
    // Auto-submitting signed form to PayHere.
    $form = payment_gateway_form($order, $method);
}
?>

<section class="section">
    <div class="container narrow">
        <div class="panel center-panel">
            <span class="eyebrow">Order <?= e((string)$order['reference']) ?></span>
            <h1>Completing payment…</h1>
            <p class="muted">
                You are being handed to <strong><?= e(payment_method_label($method)) ?></strong> to pay
                <strong><?= e(money((float)$order['total'], (string)$order['currency'])) ?></strong>.
                Do not close this window.
            </p>

            <?php if ($error): ?>
                <div class="flash flash-danger"><span><?= e($error) ?></span></div>
                <a class="btn btn-gold" href="<?= e(base_url('payment-retry.php?order=' . $orderId)) ?>">Try again</a>
                <a class="btn btn-outline" href="<?= e(base_url('account.php?tab=orders')) ?>">View my orders</a>
            <?php elseif ($method === 'payhere'): ?>
                <p class="muted small">If nothing happens within a few seconds, press the button below.</p>
                <form method="post" action="<?= e(payhere_endpoint()) ?>">
                    <?php foreach (payhere_fields($order) as $name => $value): ?>
                        <input type="hidden" name="<?= e($name) ?>" value="<?= e((string)$value) ?>">
                    <?php endforeach; ?>
                    <button class="btn btn-gold btn-lg" type="submit">Continue to PayHere</button>
                </form>
                <script>document.addEventListener('DOMContentLoaded',function(){document.forms[0].submit();});</script>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
