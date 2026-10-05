<?php
/**
 * ShopHub admin — single order detail: status, payment, invoice printing
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();


$id    = (int)query('id');
$order = $id ? find_order($id) : null;

if (!$order) {
    flash('danger', 'Order not found.');
    redirect('orders.php');
}

if (is_post()) {
    require_csrf();
    $action = (string)post('action', 'update');
    $previousStatus  = (string)$order['status'];
    $previousPayment = (string)$order['payment_status'];

    if ($action === 'update') {
        $status = (string)post('status');
        $pay    = (string)post('payment_status');

        if (in_array($status, order_statuses(), true) && $status !== $previousStatus) {
            Database::run('UPDATE orders SET status = ?, updated_at = ? WHERE id = ?', [$status, now(), $id]);

            // Milestone mail: processing / shipped / delivered / cancelled / refunded.
            // Nothing is sent when the status did not actually move.
            $template = status_email_template($status, $pay);
            if ($template) {
                $fresh = find_order($id) ?: $order;
                order_send_status_email($fresh, $template);
                flash('success', 'Status set to ' . $status . ' — the customer has been emailed.');
            } else {
                flash('success', 'Status set to ' . $status . '.');
            }
        } elseif ($status === $previousStatus) {
            flash('info', 'Status unchanged.');
        }

        if (in_array($pay, ['paid', 'unpaid', 'refunded', 'failed'], true) && $pay !== $previousPayment) {
            if ($pay === 'paid') {
                // mark_order_paid() flips the flag, promotes pending → processing
                // and sends the receipt exactly once.
                mark_order_paid($id, (string)$order['payment_method']);
                flash('success', 'Payment recorded and the receipt emailed.');
            } else {
                Database::run('UPDATE orders SET payment_status = ?, updated_at = ? WHERE id = ?', [$pay, now(), $id]);
                flash('success', 'Payment marked as ' . $pay . '.');
            }
        }
    }

    if ($action === 'note') {
        $note = trim((string)post('staff_note'));
        if ($note !== '') {
            Database::insert('order_notes', [
                'order_id'   => $id,
                'user_id'    => auth_id(),
                'note'       => $note,
                'created_at' => now(),
            ]);
            flash('success', 'Internal note added.');
        }
    }

    if ($action === 'resend_confirmation') {
        $template = status_email_template((string)$order['status'], (string)$order['payment_status']) ?: 'order_placed';
        order_send_status_email($order, $template);
        flash('success', 'Order email re-sent to ' . $order['email'] . '.');
    }

    redirect('order-view.php?id=' . $id);
}

$items = order_items($id);
$notes = Database::all('SELECT n.*, u.name AS author FROM order_notes n LEFT JOIN users u ON u.id = n.user_id WHERE n.order_id = ? ORDER BY n.created_at DESC', [$id]);
$customer = $order['user_id'] ? Database::row('SELECT * FROM users WHERE id = ?', [(int)$order['user_id']]) : null;
$prevOrders = $order['user_id']
    ? (int)Database::value('SELECT COUNT(*) FROM orders WHERE user_id = ?', [(int)$order['user_id']])
    : 0;

$pageTitle = 'Order ' . $order['reference'];
include __DIR__ . '/includes/header.php';
?>

<div class="admin-order-head">
    <div>
        <a class="link-more" href="<?= e(base_url('orders.php')) ?>">← All orders</a>
        <h2><?= e((string)$order['reference']) ?></h2>
        <p class="muted">Placed <?= e(nice_date((string)$order['created_at'], 'M j, Y \a\t g:i a')) ?> ·
            <?= $order['payment_method'] === 'cod' ? 'Cash on delivery' : 'Bank transfer' ?> ·
            <?= (int)count($items) ?> item(s)</p>
    </div>
    <div class="admin-order-head-actions">
        <button class="btn btn-outline" type="button" onclick="window.print()"><?= icon('print') ?> Print invoice</button>
        <a class="btn btn-ghost" href="<?= e(base_url('customers.php?id=' . (int)($order['user_id'] ?? 0))) ?>">Customer</a>
    </div>
</div>

<div class="admin-cols admin-cols-form">
    <div class="admin-stack">
        <section class="panel">
            <div class="panel-head"><h2>Items</h2></div>
            <table class="table">
                <thead><tr><th>Product</th><th>Variant / SKU</th><th class="num">Unit</th><th class="num">Qty</th><th class="num">Line total</th></tr></thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <?php if ($item['product_id']): ?>
                                <a href="<?= e(base_url('products.php?action=edit&id=' . (int)$item['product_id'])) ?>"><?= e((string)$item['product_name']) ?></a>
                            <?php else: ?>
                                <?= e((string)$item['product_name']) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $variantLabel = trim((string)($item['variant_label'] ?? '')); ?>
                            <?= $variantLabel !== '' ? e($variantLabel) : '<span class="muted">—</span>' ?>
                            <?php if (!empty($item['sku'])): ?><br><span class="muted small"><?= e((string)$item['sku']) ?></span><?php endif; ?>
                        </td>
                        <td class="num"><?= e(money((float)$item['unit_price'], (string)$order['currency'])) ?></td>
                        <td class="num"><?= (int)$item['quantity'] ?></td>
                        <td class="num"><?= e(money((float)$item['line_total'], (string)$order['currency'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr><th colspan="4">Subtotal</th><td class="num"><?= e(money((float)$order['subtotal'], (string)$order['currency'])) ?></td></tr>
                    <?php if ((float)$order['discount'] > 0): ?>
                        <tr class="is-credit"><th colspan="4">Discount <?= $order['coupon_code'] ? '(' . e((string)$order['coupon_code']) . ')' : '' ?></th><td class="num">−<?= e(money((float)$order['discount'], (string)$order['currency'])) ?></td></tr>
                    <?php endif; ?>
                    <tr><th colspan="4">Delivery</th><td class="num"><?= (float)$order['shipping_fee'] > 0 ? e(money((float)$order['shipping_fee'], (string)$order['currency'])) : 'Free' ?></td></tr>
                    <tr><th colspan="4">Tax</th><td class="num"><?= e(money((float)$order['tax'], (string)$order['currency'])) ?></td></tr>
                    <tr class="is-total"><th colspan="4">Total</th><td class="num"><?= e(money((float)$order['total'], (string)$order['currency'])) ?></td></tr>
                </tfoot>
            </table>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Internal notes</h2><span class="muted small">Not visible to customers</span></div>
            <form method="post" class="note-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="note">
                <textarea name="staff_note" rows="2" placeholder="Add a note for the team…"></textarea>
                <button class="btn btn-outline btn-sm" type="submit">Add note</button>
            </form>
            <?php if ($notes): ?>
                <ul class="note-list">
                    <?php foreach ($notes as $n): ?>
                        <li>
                            <p><?= nl2br(e((string)$n['note'])) ?></p>
                            <span class="muted small"><?= e((string)($n['author'] ?? 'System')) ?> · <?= e(nice_date((string)$n['created_at'], 'M j, g:i a')) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <div class="admin-stack">
        <section class="panel">
            <h2>Fulfilment</h2>
            <form method="post" class="stack-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <label class="field"><span>Order status</span>
                    <select name="status">
                        <?php foreach (order_statuses() as $s): ?>
                            <option value="<?= e($s) ?>" <?= $order['status'] === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field"><span>Payment status</span>
                    <select name="payment_status">
                        <?php foreach (['unpaid', 'paid', 'refunded'] as $s): ?>
                            <option value="<?= e($s) ?>" <?= $order['payment_status'] === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="btn btn-gold btn-block" type="submit">Update order</button>
            </form>
        </section>

        <section class="panel">
            <h2>Customer</h2>
            <p>
                <strong><?= e((string)$order['customer_name']) ?></strong><br>
                <a href="mailto:<?= e((string)$order['email']) ?>"><?= e((string)$order['email']) ?></a><br>
                <?= e((string)$order['phone']) ?>
            </p>
            <h3 class="mt">Ship to</h3>
            <p>
                <?= e((string)$order['shipping_address']) ?><br>
                <?= e((string)$order['city']) ?><?= $order['postal_code'] ? ', ' . e((string)$order['postal_code']) : '' ?><br>
                <?= e((string)$order['country']) ?>
            </p>
            <?php if ($order['notes']): ?>
                <h3 class="mt">Customer note</h3>
                <p class="muted"><?= nl2br(e((string)$order['notes'])) ?></p>
            <?php endif; ?>
            <?php if ($customer): ?>
                <p class="muted small">Account: <?= e($customer['name']) ?> · <?= $prevOrders ?> order(s) placed</p>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
