<?php
/**
 * ShopHub — retry a gateway payment for an order that is still unpaid.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/orders.php';
require_once __DIR__ . '/includes/payments.php';

$orderId = (int)query('order');
$order   = $orderId ? find_order($orderId) : null;

if (!$order) {
    flash('warn', 'We could not find that order.');
    redirect('index.php');
}

$owns = (isset($_SESSION['last_order_id']) && (int)$_SESSION['last_order_id'] === $orderId)
    || (auth_check() && (int)($order['user_id'] ?? 0) === auth_id());
if (!$owns) {
    flash('warn', 'That order is not available in this session.');
    redirect('index.php');
}

if ($order['payment_status'] === 'paid' || $order['status'] === 'cancelled') {
    redirect('order-success.php?order=' . $orderId);
}

// Let the shopper switch to a manual method if the gateway keeps failing.
if (is_post()) {
    require_csrf();
    $choice = (string)post('payment_method');
    $available = payment_methods_available();
    if (isset($available[$choice]) && !is_gateway_method($choice)) {
        Database::run('UPDATE orders SET payment_method = ? WHERE id = ?', [$choice, $orderId]);
        flash('success', 'Payment method updated to ' . payment_method_label($choice) . '.');
        redirect('order-success.php?order=' . $orderId);
    }
    redirect('payment-start.php?order=' . $orderId);
}

$pageTitle = 'Retry payment';
include __DIR__ . '/includes/header.php';
?>

<section class="section">
    <div class="container narrow">
        <div class="panel">
            <span class="eyebrow">Order <?= e((string)$order['reference']) ?></span>
            <h1>Complete your payment</h1>
            <p class="muted">
                This order is still awaiting payment of
                <strong><?= e(money((float)$order['total'], (string)$order['currency'])) ?></strong>
                via <?= e(payment_method_label((string)$order['payment_method'])) ?>.
            </p>

            <div class="success-actions" style="justify-content:flex-start">
                <a class="btn btn-gold btn-lg" href="<?= e(base_url('payment-start.php?order=' . $orderId)) ?>">Pay now</a>
                <a class="btn btn-outline" href="<?= e(base_url('account.php?tab=orders')) ?>">My orders</a>
            </div>

            <hr class="rule">

            <h3>Prefer to pay another way?</h3>
            <form method="post" class="stack-form">
                <?= csrf_field() ?>
                <div class="pay-options">
                    <?php foreach (payment_methods_available() as $key => $label): ?>
                        <?php if (is_gateway_method($key)) { continue; } ?>
                        <label class="pay-option">
                            <input type="radio" name="payment_method" value="<?= e($key) ?>" required>
                            <div><strong><?= e($label) ?></strong><span>Switch this order to <?= e(strtolower($label)) ?>.</span></div>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button class="btn btn-outline" type="submit">Switch payment method</button>
            </form>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
