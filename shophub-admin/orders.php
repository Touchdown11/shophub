<?php
/**
 * ShopHub admin — orders list
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$pageTitle = 'Orders';

if (is_post()) {
    require_csrf();
    $id     = (int)post('id');
    $status = (string)post('status');
    $pay    = (string)post('payment_status');

    if (in_array($status, order_statuses(), true)) {
        Database::run('UPDATE orders SET status = ? WHERE id = ?', [$status, $id]);
        flash('success', 'Order status updated.');
    }
    if (in_array($pay, ['paid', 'unpaid', 'refunded'], true)) {
        Database::run('UPDATE orders SET payment_status = ? WHERE id = ?', [$pay, $id]);
        flash('success', 'Payment status updated.');
    }
    redirect('admin/orders.php');
}

$status = (string)query('status', '');
$search = (string)query('q', '');
$page   = max(1, (int)query('page', 1));
$perPage = 20;

$where  = ['1=1'];
$params = [];
if ($status !== '' && in_array($status, order_statuses(), true)) {
    $where[]  = 'o.status = ?';
    $params[] = $status;
}
if ($search !== '') {
    $where[] = '(o.reference LIKE ? OR o.customer_name LIKE ? OR o.email LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
$whereSql = implode(' AND ', $where);

$total = (int)Database::value("SELECT COUNT(*) FROM orders o WHERE $whereSql", $params);
$pager = paginate($total, $perPage, $page, http_build_query(array_filter(['status' => $status, 'q' => $search])));
$orders = Database::all(
    "SELECT o.*, (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
     FROM orders o WHERE $whereSql ORDER BY o.created_at DESC LIMIT $perPage OFFSET {$pager['offset']}",
    $params
);

$tabs = ['' => 'All'] + array_combine(order_statuses(), array_map('ucfirst', order_statuses()));
$counts = [];
foreach (Database::all('SELECT status, COUNT(*) c FROM orders GROUP BY status') as $row) {
    $counts[$row['status']] = (int)$row['c'];
}

include __DIR__ . '/includes/header.php';
?>

<nav class="admin-tabs">
    <?php foreach ($tabs as $value => $label): ?>
        <a class="admin-tab<?= $status === $value ? ' is-active' : '' ?>"
           href="?<?= e(http_build_query(array_filter(['status' => $value, 'q' => $search]))) ?>">
            <?= e($label) ?><?= isset($counts[$value]) ? ' <em>' . (int)$counts[$value] . '</em>' : '' ?>
        </a>
    <?php endforeach; ?>
</nav>

<form method="get" class="admin-toolbar">
    <input type="search" name="q" placeholder="Search reference, name or email…" value="<?= e($search) ?>">
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <button class="btn btn-outline btn-sm" type="submit">Search</button>
    <span class="admin-toolbar-spacer"></span>
    <span class="muted small"><?= (int)$total ?> orders · <?= e(money((float)Database::value("SELECT COALESCE(SUM(total),0) FROM orders o WHERE $whereSql", $params))) ?> in this view</span>
</form>

<section class="panel">
    <div class="panel-head panel-head-flush">
        <h2>Orders</h2>
        <input type="search" class="table-filter" placeholder="Filter this list…" data-table-filter="#orders-table">
    </div>
    <table class="table table-admin" id="orders-table">
        <thead><tr><th>Reference</th><th>Customer</th><th>Date</th><th>Payment</th><th>Status</th><th class="num">Items</th><th class="num">Total</th><th class="actions-col">View</th></tr></thead>
        <tbody>
        <?php foreach ($orders as $o): ?>
            <tr>
                <td><a href="<?= e(base_url('order-view.php?id=' . (int)$o['id'])) ?>"><strong><?= e((string)$o['reference']) ?></strong></a></td>
                <td><?= e((string)$o['customer_name']) ?><br><span class="muted small"><?= e((string)$o['email']) ?></span></td>
                <td><?= e(nice_date((string)$o['created_at'], 'M j, Y')) ?><br><span class="muted small"><?= e(nice_date((string)$o['created_at'], 'g:i a')) ?></span></td>
                <td>
                    <?= $o['payment_method'] === 'cod' ? 'COD' : 'Bank' ?>
                    <br><span class="badge badge-<?= $o['payment_status'] === 'paid' ? 'ok' : 'warn' ?>"><?= e(ucfirst((string)$o['payment_status'])) ?></span>
                </td>
                <td><?= status_badge((string)$o['status']) ?></td>
                <td class="num"><?= (int)$o['item_count'] ?></td>
                <td class="num"><?= e(money((float)$o['total'], (string)$o['currency'])) ?></td>
                <td class="actions-col"><a class="btn btn-outline btn-sm" href="<?= e(base_url('order-view.php?id=' . (int)$o['id'])) ?>">Open</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$orders): ?><tr><td colspan="8" class="muted">No orders found.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <?= pagination_links($pager) ?>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
