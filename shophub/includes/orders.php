<?php
/**
 * ShopHub — order placement, fulfilment emails and the confirmation view.
 */
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/variants.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/payments.php';

/**
 * Persist the current cart as an order (transactional).
 *
 * @return array{ok: bool, order_id?: int, reference?: string, gateway?: ?string, message: string}
 */
function place_order(array $data): array
{
    $cart = cart_contents();
    if (!$cart['items']) {
        return ['ok' => false, 'message' => 'Your cart is empty.'];
    }

    foreach (['name' => 'Full name', 'email' => 'Email address', 'phone' => 'Phone number',
              'address' => 'Street address', 'city' => 'City'] as $field => $label) {
        if (trim((string)($data[$field] ?? '')) === '') {
            return ['ok' => false, 'message' => $label . ' is required.'];
        }
    }
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Please provide a valid email address.'];
    }

    if (verification_required()) {
        return ['ok' => false, 'message' => 'Please confirm your email address before placing an order.'];
    }

    $available = payment_methods_available();
    $method    = isset($available[$data['payment_method'] ?? '']) ? (string)$data['payment_method'] : 'cod';

    // Re-check stock (products AND variants) before committing anything.
    foreach ($cart['items'] as $item) {
        if ($item['stock'] < $item['qty']) {
            $name = $item['product']['name'] . ($item['label'] ? ' (' . $item['label'] . ')' : '');
            return ['ok' => false, 'message' => '“' . $name . '” just sold out. Please adjust your cart.'];
        }
    }

    $pdo = Database::pdo();
    try {
        $pdo->beginTransaction();

        $reference = 'SH-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $orderId = Database::insert('orders', [
            'user_id'          => auth_id(),
            'reference'        => $reference,
            'email'            => strtolower(trim((string)$data['email'])),
            'customer_name'    => trim((string)$data['name']),
            'phone'            => trim((string)$data['phone']),
            'shipping_address' => trim((string)$data['address']),
            'city'             => trim((string)$data['city']),
            'postal_code'      => trim((string)($data['postal_code'] ?? '')),
            'country'          => trim((string)($data['country'] ?? 'Sri Lanka')),
            'notes'            => trim((string)($data['notes'] ?? '')),
            'subtotal'         => $cart['subtotal'],
            'discount'         => $cart['discount'],
            'shipping_fee'     => $cart['shipping'],
            'tax'              => $cart['tax'],
            'total'            => $cart['total'],
            'coupon_code'      => $cart['coupon']['code'] ?? null,
            'currency'         => store_currency(),
            'payment_method'   => $method,
            'payment_status'   => 'unpaid',
            'status'           => 'pending',
            'created_at'       => now(),
        ]);

        foreach ($cart['items'] as $item) {
            Database::insert('order_items', [
                'order_id'      => $orderId,
                'product_id'    => (int)$item['product']['id'],
                'variant_id'    => $item['variant_id'],
                'product_name'  => $item['product']['name'],
                'variant_label' => $item['label'],
                'sku'           => (string)$item['sku'],
                'unit_price'    => $item['price'],
                'quantity'      => (int)$item['qty'],
                'line_total'    => $item['subtotal'],
            ]);

            if ($item['variant_id']) {
                Database::run(
                    'UPDATE product_variants SET stock = CASE WHEN stock >= ? THEN stock - ? ELSE 0 END WHERE id = ?',
                    [(int)$item['qty'], (int)$item['qty'], (int)$item['variant_id']]
                );
                // Keep the parent total in step with the variant ledger.
                Database::run(
                    'UPDATE products SET stock = (SELECT COALESCE(SUM(stock),0) FROM product_variants WHERE product_id = ? AND is_active = 1) WHERE id = ?',
                    [(int)$item['product']['id'], (int)$item['product']['id']]
                );
            } else {
                Database::run(
                    'UPDATE products SET stock = CASE WHEN stock >= ? THEN stock - ? ELSE 0 END WHERE id = ?',
                    [(int)$item['qty'], (int)$item['qty'], (int)$item['product']['id']]
                );
            }
        }

        if (!empty($cart['coupon']['code'])) {
            Database::run('UPDATE coupons SET used_count = used_count + 1 WHERE code = ?', [$cart['coupon']['code']]);
        }

        if (auth_check()) {
            $userId = auth_id();
            $hasDefault = (int)Database::value('SELECT COUNT(*) FROM addresses WHERE user_id = ? AND is_default = 1', [$userId]);
            Database::insert('addresses', [
                'user_id'     => $userId,
                'label'       => 'Shipping',
                'full_name'   => trim((string)$data['name']),
                'phone'       => trim((string)$data['phone']),
                'address'     => trim((string)$data['address']),
                'city'        => trim((string)$data['city']),
                'postal_code' => trim((string)($data['postal_code'] ?? '')),
                'country'     => trim((string)($data['country'] ?? 'Sri Lanka')),
                'is_default'  => $hasDefault ? 0 : 1,
                'created_at'  => now(),
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'We could not place your order: ' . $e->getMessage()];
    }

    $_SESSION['last_order_id'] = $orderId;
    unset($_SESSION['cart'], $_SESSION['coupon']);

    // Manual methods get the "order confirmed" email straight away; gateway
    // orders get it once payment clears, so the customer isn't told twice.
    $order = find_order($orderId);
    if ($order) {
        if (!is_gateway_method($method)) {
            order_send_status_email($order, 'order_placed');
        }
        order_notify_admin($order);
    }

    return [
        'ok'        => true,
        'order_id'  => $orderId,
        'reference' => $reference,
        'gateway'   => is_gateway_method($method) ? $method : null,
        'message'   => 'Order placed.',
    ];
}

/* ---------------------------------------------------------------- retrieval -- */

function find_order(int $id): ?array
{
    return Database::row('SELECT * FROM orders WHERE id = ?', [$id]);
}

function find_order_by_reference(string $reference): ?array
{
    return Database::row('SELECT * FROM orders WHERE reference = ?', [$reference]);
}

function order_items(int $orderId): array
{
    return Database::all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$orderId]);
}

/* -------------------------------------------------------------------- emails -- */

/** Shared template variables for anything order-related. */
function order_mail_vars(array $order): array
{
    $currency = (string)($order['currency'] ?? store_currency());
    $items    = order_items((int)$order['id']);

    return [
        'name'           => (string)$order['customer_name'],
        'first_name'     => explode(' ', trim((string)$order['customer_name']))[0],
        'email'          => (string)$order['email'],
        'reference'      => (string)$order['reference'],
        'total'          => money((float)$order['total'], $currency),
        'subtotal'       => money((float)$order['subtotal'], $currency),
        'address'        => (string)$order['shipping_address'],
        'city'           => (string)$order['city'],
        'payment_method' => payment_method_label((string)$order['payment_method']),
        'items'          => mail_items_table($items, $currency) . mail_totals_block($order),
        'order_url'      => store_url('order-success.php?order=' . (int)$order['id']),
        'orders_url'     => store_url('account.php?tab=orders'),
        'review_url'     => store_url('account.php?tab=orders'),
        'admin_url'      => admin_link('order-view.php?id=' . (int)$order['id']),
        'store_url'      => store_url(''),
    ];
}

/** Fire one of the lifecycle emails. Returns false when it was skipped. */
function order_send_status_email(array $order, string $template): bool
{
    if (!$order) {
        return false;
    }
    $result = send_template(
        (string)$order['email'],
        $template,
        order_mail_vars($order),
        (string)$order['customer_name']
    );
    return $result['ok'];
}

function order_notify_admin(array $order): void
{
    if (!setting_bool('notify_admin_on_order', (bool)cfg('mail.notify_admin_on_order', true))) {
        return;
    }
    $to = (string)setting('mail_from', cfg('mail.from'));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    send_template($to, 'admin_new_order', order_mail_vars($order), (string)setting('store_name', 'ShopHub'));
}

/** The template that matches a status transition (null = nothing to send). */
function status_email_template(string $status, string $paymentStatus = ''): ?string
{
    return match ($status) {
        'processing' => 'order_processing',
        'shipped'    => 'order_shipped',
        'delivered'  => 'order_delivered',
        'cancelled'  => 'order_cancelled',
        'refunded'   => 'order_refunded',
        default      => $paymentStatus === 'paid' ? 'payment_received' : null,
    };
}

/* --------------------------------------------------------------- rendering -- */

function order_confirmation_html(array $order, bool $compact = false): string
{
    $items = order_items((int)$order['id']);
    $currency = (string)$order['currency'];
    $method = (string)$order['payment_method'];
    ob_start(); ?>
    <div class="confirm-card<?= $compact ? ' is-compact' : '' ?>">
        <header class="confirm-head">
            <div>
                <span class="eyebrow">Order reference</span>
                <h2><?= e((string)$order['reference']) ?></h2>
                <p class="muted">Placed <?= e(nice_date((string)$order['created_at'], 'M j, Y · g:i a')) ?></p>
            </div>
            <div class="confirm-badges">
                <?= status_badge((string)$order['status']) ?>
                <span class="badge badge-<?= $order['payment_status'] === 'paid' ? 'ok' : 'warn' ?>">
                    <?= e(payment_method_label($method)) ?>
                </span>
            </div>
        </header>

        <div class="confirm-grid">
            <div>
                <h3>Delivering to</h3>
                <p>
                    <strong><?= e((string)$order['customer_name']) ?></strong><br>
                    <?= e((string)$order['shipping_address']) ?><br>
                    <?= e((string)$order['city']) ?><?= $order['postal_code'] ? ', ' . e((string)$order['postal_code']) : '' ?><br>
                    <?= e((string)$order['country']) ?><br>
                    <?= e((string)$order['phone']) ?> · <?= e((string)$order['email']) ?>
                </p>
            </div>
            <div>
                <h3>Payment</h3>
                <?php if ($order['payment_status'] === 'paid'): ?>
                    <p>Paid in full — thank you. <?= $order['paid_at'] ? 'Received ' . e(nice_date((string)$order['paid_at'], 'M j, Y g:i a')) . '.' : '' ?></p>
                <?php elseif ($method === 'cod'): ?>
                    <p>Please have <strong><?= e(money((float)$order['total'], $currency)) ?></strong> ready in cash when the courier arrives.</p>
                <?php elseif ($method === 'bank_transfer'): ?>
                    <p>Transfer <strong><?= e(money((float)$order['total'], $currency)) ?></strong> to<br>
                    <strong><?= e((string)setting('bank_account_name', 'ShopHub Trading')) ?></strong> ·
                    <?= e((string)setting('bank_account_number', '0012 3456 7890')) ?><br>
                    <?= e((string)setting('bank_name', 'Bank of Ceylon, Colombo 03')) ?><?php
                        $branch = trim((string)setting('bank_branch', ''));
                        echo $branch !== '' ? ' · ' . e($branch) : '';
                    ?><br>
                    Use <strong><?= e((string)$order['reference']) ?></strong> as the reference.</p>
                    <?php if (trim((string)setting('bank_instructions', '')) !== ''): ?>
                        <p><?= nl2br(e((string)setting('bank_instructions', ''))) ?></p>
                    <?php endif; ?>
                <?php else: ?>
                    <p>This order is awaiting payment. <a href="<?= e(store_url('payment-retry.php?order=' . (int)$order['id'])) ?>">Complete payment →</a></p>
                <?php endif; ?>
            </div>
        </div>

        <table class="table confirm-table">
            <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Total</th></tr></thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td>
                        <?= e((string)$item['product_name']) ?>
                        <?php if (!empty($item['variant_label'])): ?>
                            <br><span class="muted small"><?= e((string)$item['variant_label']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($item['sku'])): ?>
                            <br><span class="muted small">SKU <?= e((string)$item['sku']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= (int)$item['quantity'] ?></td>
                    <td class="num"><?= e(money((float)$item['unit_price'], $currency)) ?></td>
                    <td class="num"><?= e(money((float)$item['line_total'], $currency)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><th colspan="3">Subtotal</th><td class="num"><?= e(money((float)$order['subtotal'], $currency)) ?></td></tr>
                <?php if ((float)$order['discount'] > 0): ?>
                    <tr class="is-credit"><th colspan="3">Discount <?= $order['coupon_code'] ? '(' . e((string)$order['coupon_code']) . ')' : '' ?></th><td class="num">−<?= e(money((float)$order['discount'], $currency)) ?></td></tr>
                <?php endif; ?>
                <tr><th colspan="3">Delivery</th><td class="num"><?= (float)$order['shipping_fee'] > 0 ? e(money((float)$order['shipping_fee'], $currency)) : 'Free' ?></td></tr>
                <tr><th colspan="3">Tax</th><td class="num"><?= e(money((float)$order['tax'], $currency)) ?></td></tr>
                <tr class="is-total"><th colspan="3">Total</th><td class="num"><?= e(money((float)$order['total'], $currency)) ?></td></tr>
            </tfoot>
        </table>

        <?php if ($order['notes']): ?>
            <p class="muted small"><strong>Your note:</strong> <?= e((string)$order['notes']) ?></p>
        <?php endif; ?>
    </div>
    <?php
    return (string)ob_get_clean();
}
