<?php
/**
 * ShopHub — the gateway sent the shopper back.
 *
 * Nothing here is trusted until it is verified against the gateway API
 * (Stripe) or the signed notification (PayHere, see payment-notify.php).
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/orders.php';
require_once __DIR__ . '/includes/payments.php';

$orderId = (int)query('order');
$gateway = (string)query('gateway');
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

$note = '';

/* ------------------------------------------------------------------ Stripe -- */
if ($gateway === 'stripe') {
    $sessionId = (string)query('session_id');
    if ($sessionId !== '') {
        $check = stripe_verify_session($sessionId);
        if ($check['ok'] && !empty($check['paid'])) {
            Database::run('UPDATE orders SET payment_ref = ? WHERE id = ?', [$sessionId, $orderId]);
            mark_order_paid($orderId, 'stripe');
            flash('success', 'Payment received — thank you! Your order is confirmed.');
            redirect('order-success.php?order=' . $orderId);
        }
        $note = $check['ok']
            ? 'Stripe has not confirmed this payment yet (' . $check['message'] . ').'
            : 'We could not reach Stripe to confirm the payment: ' . $check['message'];
    } else {
        $note = 'Stripe did not return a session reference.';
    }
}

/* ----------------------------------------------------------------- PayHere -- */
if ($gateway === 'payhere' && !$note) {
    // PayHere returns the shopper here with its own POST fields; trust the
    // signed values only (the definitive confirmation is payment-notify.php).
    $posted = $_POST ?: $_GET;
    if (!empty($posted['md5sig']) && payhere_verify_notify($posted)) {
        if ((string)($posted['status_code'] ?? '') === '2') {
            Database::run('UPDATE orders SET payment_ref = ? WHERE id = ?', [(string)($posted['payment_id'] ?? ''), $orderId]);
            mark_order_paid($orderId, 'payhere');
            flash('success', 'Payment received — thank you! Your order is confirmed.');
            redirect('order-success.php?order=' . $orderId);
        }
        $note = 'PayHere reported status “' . e((string)($posted['status_message'] ?? 'unknown')) . '”, so the order is still awaiting payment.';
    } else {
        $note = 'We are waiting for the gateway to confirm this payment. Your order stays reserved — refresh in a moment.';
    }
}

/* Re-check the record: a webhook may have landed while the shopper was away. */
$fresh = find_order($orderId);
if ($fresh && $fresh['payment_status'] === 'paid') {
    redirect('order-success.php?order=' . $orderId);
}

$pageTitle = 'Payment status';
include __DIR__ . '/includes/header.php';
?>

<section class="section">
    <div class="container narrow">
        <div class="panel center-panel">
            <span class="eyebrow">Order <?= e((string)$order['reference']) ?></span>
            <h1>Payment not completed yet</h1>
            <p class="muted"><?= e($note ?: 'The payment has not been confirmed.') ?></p>
            <p class="muted small">
                Your items are still reserved. You can retry the payment, or choose cash on delivery
                instead by contacting us with your reference.
            </p>
            <div class="success-actions">
                <a class="btn btn-gold" href="<?= e(base_url('payment-retry.php?order=' . $orderId)) ?>">Retry payment</a>
                <a class="btn btn-outline" href="<?= e(base_url('account.php?tab=orders')) ?>">View my orders</a>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
