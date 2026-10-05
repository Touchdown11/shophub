<?php
/**
 * ShopHub — order confirmation
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/orders.php';

$orderId = (int)query('order');
$order   = $orderId ? find_order($orderId) : null;

if (!$order || !isset($_SESSION['last_order_id']) || (int)$_SESSION['last_order_id'] !== $orderId) {
    flash('warn', 'That order could not be found.');
    redirect('index.php');
}

$unpaidGateway = $order['payment_status'] !== 'paid' && is_gateway_method((string)$order['payment_method']);

$pageTitle = 'Order confirmed';
include __DIR__ . '/includes/header.php';
?>

<section class="section section-tight">
    <div class="container">
        <div class="success-hero">
            <div class="success-check" aria-hidden="true">
                <svg viewBox="0 0 48 48"><circle cx="24" cy="24" r="21" fill="none" stroke="currentColor" stroke-width="2.5"/><path d="M15 25l6 6 12-13" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </div>
            <h1>Thank you, <?= e(explode(' ', (string)$order['customer_name'])[0]) ?>!</h1>
            <p class="muted">
                Your order is confirmed. A receipt is on its way to <?= e((string)$order['email']) ?>.
                Keep your reference handy for any enquiries.
            </p>

            <?php if ($unpaidGateway): ?>
                <div class="flash flash-warn" style="text-align:left">
                    <span>
                        This order is <strong>awaiting payment</strong> — your items are reserved.
                        Complete it now to start fulfilment.
                    </span>
                </div>
            <?php endif; ?>

            <div class="success-actions">
                <?php if ($unpaidGateway): ?>
                    <a class="btn btn-gold" href="<?= e(base_url('payment-retry.php?order=' . $orderId)) ?>">Pay now</a>
                <?php endif; ?>
                <a class="btn btn-outline" href="<?= e(base_url('shop.php')) ?>">Continue shopping</a>
                <?php if (auth_check()): ?>
                    <a class="btn btn-ghost" href="<?= e(base_url('account.php?tab=orders')) ?>">View my orders</a>
                <?php else: ?>
                    <a class="btn btn-ghost" href="<?= e(base_url('register.php')) ?>">Create an account</a>
                <?php endif; ?>
            </div>
        </div>

        <?= order_confirmation_html($order) ?>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
