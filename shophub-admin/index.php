<?php
/**
 * ShopHub admin — dashboard
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$pageTitle = 'Dashboard';

$revenue     = (float)Database::value("SELECT COALESCE(SUM(total),0) FROM orders WHERE status NOT IN ('cancelled','refunded')");
$monthRevenue = (float)Database::value("SELECT COALESCE(SUM(total),0) FROM orders WHERE status NOT IN ('cancelled','refunded') AND created_at >= ?", [date('Y-m-01 00:00:00')]);
$orderCount  = (int)Database::value('SELECT COUNT(*) FROM orders');
$pending     = (int)Database::value("SELECT COUNT(*) FROM orders WHERE status IN ('pending','processing')");
$customers   = (int)Database::value("SELECT COUNT(*) FROM users WHERE role = 'customer'");
$products    = (int)Database::value('SELECT COUNT(*) FROM products WHERE is_active = 1');
$lowStock    = Database::all('SELECT id, name, stock FROM products WHERE stock <= 5 ORDER BY stock ASC LIMIT 6');
$aov         = $orderCount > 0 ? $revenue / max(1, (int)Database::value("SELECT COUNT(*) FROM orders WHERE status NOT IN ('cancelled','refunded')")) : 0;

$recentOrders = Database::all(
    'SELECT o.*, (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
     FROM orders o ORDER BY o.created_at DESC LIMIT 8'
);

/* 14-day sales sparkline */
$series = [];
for ($i = 13; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $series[$day] = (float)Database::value(
        "SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE(created_at) = ? AND status NOT IN ('cancelled','refunded')",
        [$day]
    );
}
$maxSeries = max(1.0, max($series));

$topProducts = Database::all(
    'SELECT oi.product_name, SUM(oi.quantity) AS qty, SUM(oi.line_total) AS revenue
     FROM order_items oi JOIN orders o ON o.id = oi.order_id
     WHERE o.status NOT IN (\'cancelled\',\'refunded\')
     GROUP BY oi.product_name ORDER BY qty DESC LIMIT 5'
);

$statusBreakdown = Database::all('SELECT status, COUNT(*) c FROM orders GROUP BY status ORDER BY c DESC');

include __DIR__ . '/includes/header.php';
?>

<div class="stat-grid">
    <div class="stat-tile">
        <span class="muted">Revenue (all time)</span>
        <strong><?= e(money($revenue)) ?></strong>
        <em class="up"><?= e(money($monthRevenue)) ?> this month</em>
    </div>
    <div class="stat-tile">
        <span class="muted">Orders</span>
        <strong><?= number_format($orderCount) ?></strong>
        <em><?= (int)$pending ?> awaiting fulfilment</em>
    </div>
    <div class="stat-tile">
        <span class="muted">Average order value</span>
        <strong><?= e(money($aov)) ?></strong>
        <em>across paid-plan orders</em>
    </div>
    <div class="stat-tile">
        <span class="muted">Customers</span>
        <strong><?= number_format($customers) ?></strong>
        <em><?= number_format($products) ?> active products</em>
    </div>
</div>

<div class="admin-cols">
    <section class="panel">
        <div class="panel-head">
            <h2>Sales, last 14 days</h2>
            <span class="muted small">Peak day: <?= e(money($maxSeries)) ?></span>
        </div>
        <div class="spark">
            <?php foreach ($series as $day => $value): ?>
                <div class="spark-col" title="<?= e(date('M j', strtotime($day))) ?>: <?= e(money($value)) ?>">
                    <span style="height: <?= max(2, (int)round($value / $maxSeries * 100)) ?>%"></span>
                    <em><?= e(date('j', strtotime($day))) ?></em>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Order status</h2></div>
        <?php $totalOrders = max(1, array_sum(array_column($statusBreakdown, 'c'))); ?>
        <ul class="breakdown">
            <?php foreach ($statusBreakdown as $row): ?>
                <li>
                    <span><?= status_badge((string)$row['status']) ?></span>
                    <div class="bar"><i style="width: <?= round((int)$row['c'] / $totalOrders * 100) ?>%"></i></div>
                    <strong><?= (int)$row['c'] ?></strong>
                </li>
            <?php endforeach; ?>
            <?php if (!$statusBreakdown): ?><li class="muted">No orders yet.</li><?php endif; ?>
        </ul>
    </section>
</div>

<div class="admin-cols">
    <section class="panel">
        <div class="panel-head">
            <h2>Recent orders</h2>
            <a class="link-more" href="<?= e(base_url('orders.php')) ?>">All orders →</a>
        </div>
        <table class="table">
            <thead><tr><th>Reference</th><th>Customer</th><th>Status</th><th class="num">Total</th></tr></thead>
            <tbody>
            <?php foreach ($recentOrders as $o): ?>
                <tr>
                    <td><a href="<?= e(base_url('order-view.php?id=' . (int)$o['id'])) ?>"><?= e((string)$o['reference']) ?></a></td>
                    <td><?= e((string)$o['customer_name']) ?><br><span class="muted small"><?= e(nice_date((string)$o['created_at'], 'M j, g:i a')) ?></span></td>
                    <td><?= status_badge((string)$o['status']) ?></td>
                    <td class="num"><?= e(money((float)$o['total'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recentOrders): ?><tr><td colspan="4" class="muted">No orders yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>

    <div class="admin-stack">
        <section class="panel">
            <div class="panel-head"><h2>Top sellers</h2></div>
            <ol class="rank-list">
                <?php foreach ($topProducts as $t): ?>
                    <li>
                        <span><?= e(excerpt((string)$t['product_name'], 38)) ?></span>
                        <em><?= (int)$t['qty'] ?> sold · <?= e(money((float)$t['revenue'])) ?></em>
                    </li>
                <?php endforeach; ?>
                <?php if (!$topProducts): ?><li class="muted">No sales yet.</li><?php endif; ?>
            </ol>
        </section>

        <section class="panel">
            <div class="panel-head">
                <h2>Low stock</h2>
                <a class="link-more" href="<?= e(base_url('products.php')) ?>">Manage →</a>
            </div>
            <ul class="rank-list">
                <?php foreach ($lowStock as $p): ?>
                    <li>
                        <span><?= e(excerpt((string)$p['name'], 38)) ?></span>
                        <em class="<?= (int)$p['stock'] === 0 ? 'text-danger' : 'text-warn' ?>"><?= (int)$p['stock'] ?> left</em>
                    </li>
                <?php endforeach; ?>
                <?php if (!$lowStock): ?><li class="muted">Every product has healthy stock.</li><?php endif; ?>
            </ul>
        </section>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
