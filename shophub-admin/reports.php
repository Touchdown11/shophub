<?php
/**
 * ShopHub staff portal — sales reports
 *
 * Revenue and order volume for any date range, broken down by day, status,
 * payment method, product and category. Every table can be exported to CSV.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

/* ------------------------------------------------------------------- range -- */

$preset = (string)query('preset', '30d');
$today  = date('Y-m-d');

$presets = [
    'today'   => ['Today',        $today,                 $today],
    '7d'      => ['Last 7 days',  date('Y-m-d', strtotime('-6 days')),  $today],
    '30d'     => ['Last 30 days', date('Y-m-d', strtotime('-29 days')), $today],
    'mtd'     => ['Month to date', date('Y-m-01'),         $today],
    '90d'     => ['Last 90 days', date('Y-m-d', strtotime('-89 days')), $today],
    'ytd'     => ['Year to date', date('Y-01-01'),         $today],
    'all'     => ['All time',     '2000-01-01',            $today],
];

$preset = isset($presets[$preset]) ? $preset : '30d';

$from = (string)query('from', $presets[$preset][1]);
$to   = (string)query('to',   $presets[$preset][2]);

// Accept dd/mm/yyyy too, and never let a bad range 500 the page.
$normalise = static function (string $date, string $fallback): string {
    $date = trim($date);
    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
        $parsed = DateTime::createFromFormat($format, $date);
        if ($parsed && $parsed->format($format) === $date) {
            return $parsed->format('Y-m-d');
        }
    }
    return $fallback;
};
$from = $normalise($from, $presets[$preset][1]);
$to   = $normalise($to, $presets[$preset][2]);
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$start = $from . ' 00:00:00';
$end   = $to   . ' 23:59:59';

// Excluded statuses = money we never actually earned.
$voided = "('cancelled','refunded')";

$revenue = (float)Database::value(
    "SELECT COALESCE(SUM(total),0) FROM orders WHERE created_at BETWEEN ? AND ? AND status NOT IN $voided",
    [$start, $end]
);
$orders = (int)Database::value(
    "SELECT COUNT(*) FROM orders WHERE created_at BETWEEN ? AND ? AND status NOT IN $voided",
    [$start, $end]
);
$units = (int)Database::value(
    "SELECT COALESCE(SUM(oi.quantity),0) FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     WHERE o.created_at BETWEEN ? AND ? AND o.status NOT IN $voided",
    [$start, $end]
);
$discounts = (float)Database::value(
    "SELECT COALESCE(SUM(discount),0) FROM orders WHERE created_at BETWEEN ? AND ? AND status NOT IN $voided",
    [$start, $end]
);
$shipping = (float)Database::value(
    "SELECT COALESCE(SUM(shipping_fee),0) FROM orders WHERE created_at BETWEEN ? AND ? AND status NOT IN $voided",
    [$start, $end]
);
$tax = (float)Database::value(
    "SELECT COALESCE(SUM(tax),0) FROM orders WHERE created_at BETWEEN ? AND ? AND status NOT IN $voided",
    [$start, $end]
);
$newCustomers = (int)Database::value(
    "SELECT COUNT(*) FROM users WHERE role = 'customer' AND created_at BETWEEN ? AND ?",
    [$start, $end]
);

$avgOrder = $orders > 0 ? $revenue / $orders : 0.0;

/* ------------------------------------------------------------- broken down -- */

$byDay = Database::all(
    "SELECT DATE(created_at) AS day,
            COUNT(*) AS orders,
            COALESCE(SUM(total),0) AS revenue
     FROM orders
     WHERE created_at BETWEEN ? AND ? AND status NOT IN $voided
     GROUP BY DATE(created_at)
     ORDER BY day DESC",
    [$start, $end]
);

$byStatus = Database::all(
    "SELECT status, COUNT(*) AS orders, COALESCE(SUM(total),0) AS revenue
     FROM orders WHERE created_at BETWEEN ? AND ?
     GROUP BY status ORDER BY revenue DESC",
    [$start, $end]
);

$byPayment = Database::all(
    "SELECT payment_method, payment_status, COUNT(*) AS orders, COALESCE(SUM(total),0) AS revenue
     FROM orders WHERE created_at BETWEEN ? AND ?
     GROUP BY payment_method, payment_status ORDER BY revenue DESC",
    [$start, $end]
);

$topProducts = Database::all(
    "SELECT oi.product_name, oi.sku, SUM(oi.quantity) AS units, SUM(oi.line_total) AS revenue,
            COUNT(DISTINCT oi.order_id) AS orders
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     WHERE o.created_at BETWEEN ? AND ? AND o.status NOT IN $voided
     GROUP BY oi.product_name, oi.sku
     ORDER BY revenue DESC LIMIT 15",
    [$start, $end]
);

$byCategory = Database::all(
    "SELECT COALESCE(NULLIF(c.name,''), 'Uncategorised') AS category,
            SUM(oi.quantity) AS units, SUM(oi.line_total) AS revenue
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     LEFT JOIN products p ON p.id = oi.product_id
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE o.created_at BETWEEN ? AND ? AND o.status NOT IN $voided
     GROUP BY category ORDER BY revenue DESC",
    [$start, $end]
);

$byCustomer = Database::all(
    "SELECT COALESCE(u.name, o.customer_name) AS customer, o.email,
            COUNT(*) AS orders, COALESCE(SUM(o.total),0) AS revenue
     FROM orders o LEFT JOIN users u ON u.id = o.user_id
     WHERE o.created_at BETWEEN ? AND ? AND o.status NOT IN $voided
     GROUP BY o.email, customer ORDER BY revenue DESC LIMIT 15",
    [$start, $end]
);

$couponUse = Database::all(
    "SELECT coupon_code, COUNT(*) AS orders, COALESCE(SUM(discount),0) AS discount_given
     FROM orders
     WHERE created_at BETWEEN ? AND ? AND coupon_code IS NOT NULL AND coupon_code <> ''
     GROUP BY coupon_code ORDER BY discount_given DESC",
    [$start, $end]
);

/* ---------------------------------------------------------------- CSV export -- */

$export = (string)query('export');
if ($export !== '') {
    $label = $from . '_to_' . $to;
    switch ($export) {
        case 'summary':
            csv_stream('shophub-sales-summary_' . $label . '.csv', ['Metric', 'Value'], [
                ['Range',            $from . ' → ' . $to],
                ['Revenue',          number_format($revenue, 2, '.', '')],
                ['Orders',           $orders],
                ['Units sold',       $units],
                ['Average order',    number_format($avgOrder, 2, '.', '')],
                ['Discounts given',  number_format($discounts, 2, '.', '')],
                ['Shipping collected', number_format($shipping, 2, '.', '')],
                ['Tax collected',    number_format($tax, 2, '.', '')],
                ['New customers',    $newCustomers],
            ]);

        case 'daily':
            csv_stream('shophub-sales-daily_' . $label . '.csv', ['Date', 'Orders', 'Revenue'],
                array_map(static fn($r) => [$r['day'], (int)$r['orders'], number_format((float)$r['revenue'], 2, '.', '')], $byDay));

        case 'products':
            csv_stream('shophub-sales-products_' . $label . '.csv',
                ['Product', 'SKU', 'Units sold', 'Orders', 'Revenue'],
                array_map(static fn($r) => [
                    $r['product_name'], $r['sku'], (int)$r['units'], (int)$r['orders'],
                    number_format((float)$r['revenue'], 2, '.', ''),
                ], $topProducts));

        case 'categories':
            csv_stream('shophub-sales-categories_' . $label . '.csv', ['Category', 'Units', 'Revenue'],
                array_map(static fn($r) => [$r['category'], (int)$r['units'], number_format((float)$r['revenue'], 2, '.', '')], $byCategory));

        case 'customers':
            csv_stream('shophub-sales-customers_' . $label . '.csv', ['Customer', 'Email', 'Orders', 'Revenue'],
                array_map(static fn($r) => [$r['customer'], $r['email'], (int)$r['orders'], number_format((float)$r['revenue'], 2, '.', '')], $byCustomer));

        case 'orders':
            $rows = Database::all(
                "SELECT * FROM orders WHERE created_at BETWEEN ? AND ? ORDER BY created_at DESC",
                [$start, $end]
            );
            csv_stream('shophub-orders_' . $label . '.csv', [
                'Reference', 'Date', 'Customer', 'Email', 'Phone', 'City', 'Country',
                'Status', 'Payment method', 'Payment status', 'Subtotal', 'Discount',
                'Shipping', 'Tax', 'Total', 'Currency', 'Coupon',
            ], array_map(static fn($o) => [
                $o['reference'], $o['created_at'], $o['customer_name'], $o['email'], $o['phone'],
                $o['city'], $o['country'], $o['status'], payment_method_label((string)$o['payment_method']),
                $o['payment_status'], number_format((float)$o['subtotal'], 2, '.', ''),
                number_format((float)$o['discount'], 2, '.', ''), number_format((float)$o['shipping_fee'], 2, '.', ''),
                number_format((float)$o['tax'], 2, '.', ''), number_format((float)$o['total'], 2, '.', ''),
                $o['currency'], (string)$o['coupon_code'],
            ], $rows));
    }
    redirect('reports.php?preset=' . urlencode($preset) . '&from=' . urlencode($from) . '&to=' . urlencode($to));
}

/* --------------------------------------------------------------- chart data -- */

// Oldest → newest for the sparkline, capped at 60 points.
$chart = array_reverse(array_slice($byDay, 0, 60));
$chartMax = 0.0;
foreach ($chart as $point) {
    $chartMax = max($chartMax, (float)$point['revenue']);
}

$query = http_build_query(['preset' => $preset, 'from' => $from, 'to' => $to]);
$exportUrl = static fn(string $what): string => base_url('reports.php?' . $query . '&export=' . $what);

$pageTitle = 'Sales reports';
$pageSubtitle = nice_date($from . ' 00:00:00') . ' → ' . nice_date($to . ' 00:00:00');
include __DIR__ . '/includes/header.php';
?>

<section class="panel report-controls">
    <form method="get" class="report-filter">
        <div class="preset-chips">
            <?php foreach ($presets as $key => [$label, $pFrom, $pTo]): ?>
                <a class="chip<?= $preset === $key ? ' is-active' : '' ?>"
                   href="<?= e(base_url('reports.php?preset=' . $key)) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="report-dates">
            <label class="field field-inline"><span>From</span><input type="date" name="from" value="<?= e($from) ?>"></label>
            <label class="field field-inline"><span>To</span><input type="date" name="to" value="<?= e($to) ?>"></label>
            <input type="hidden" name="preset" value="custom">
            <button class="btn btn-gold" type="submit">Apply</button>
            <a class="btn btn-outline" href="<?= e($exportUrl('orders')) ?>"><?= admin_icon('download') ?> Export orders (CSV)</a>
            <a class="btn btn-ghost" href="<?= e($exportUrl('summary')) ?>"><?= admin_icon('download') ?> Summary (CSV)</a>
        </div>
    </form>
</section>

<section class="stat-cards">
    <div class="stat-card">
        <span class="muted">Revenue</span>
        <strong><?= e(money($revenue)) ?></strong>
        <em class="muted small"><?= $orders ?> order<?= $orders === 1 ? '' : 's' ?> · avg <?= e(money($avgOrder)) ?></em>
    </div>
    <div class="stat-card">
        <span class="muted">Units sold</span>
        <strong><?= number_format($units) ?></strong>
        <em class="muted small"><?= count($topProducts) ?> products moved</em>
    </div>
    <div class="stat-card">
        <span class="muted">Discounts given</span>
        <strong><?= e(money($discounts)) ?></strong>
        <em class="muted small"><?= $couponUse ? count($couponUse) . ' coupon(s) used' : 'no coupons used' ?></em>
    </div>
    <div class="stat-card">
        <span class="muted">Tax + shipping</span>
        <strong><?= e(money($tax + $shipping)) ?></strong>
        <em class="muted small"><?= e(money($tax)) ?> tax · <?= e(money($shipping)) ?> shipping</em>
    </div>
    <div class="stat-card">
        <span class="muted">New customers</span>
        <strong><?= number_format($newCustomers) ?></strong>
        <em class="muted small">in this range</em>
    </div>
</section>

<?php if ($chart): ?>
    <section class="panel">
        <div class="panel-head">
            <h2>Daily revenue</h2>
            <a class="link-more" href="<?= e($exportUrl('daily')) ?>">Download CSV</a>
        </div>
        <div class="spark" role="img" aria-label="Daily revenue chart">
            <?php foreach ($chart as $point): $height = $chartMax > 0 ? max(2, (int)round((float)$point['revenue'] / $chartMax * 100)) : 2; ?>
                <span class="spark-bar" title="<?= e(nice_date($point['day'] . ' 00:00:00')) ?>: <?= e(money((float)$point['revenue'])) ?> (<?= (int)$point['orders'] ?> orders)">
                    <i style="height:<?= $height ?>%"></i>
                </span>
            <?php endforeach; ?>
        </div>
        <p class="muted small">Peak day: <?= e(money($chartMax)) ?>. Hover a bar for the day's takings.</p>
    </section>
<?php endif; ?>

<div class="report-grid">
    <section class="panel">
        <div class="panel-head">
            <h2>Top products</h2>
            <a class="link-more" href="<?= e($exportUrl('products')) ?>">CSV</a>
        </div>
        <table class="admin-table">
            <thead><tr><th>Product</th><th>SKU</th><th class="num">Units</th><th class="num">Revenue</th></tr></thead>
            <tbody>
            <?php foreach ($topProducts as $row): ?>
                <tr>
                    <td><?= e($row['product_name']) ?></td>
                    <td class="muted small"><?= e((string)$row['sku']) ?></td>
                    <td class="num"><?= (int)$row['units'] ?></td>
                    <td class="num"><?= e(money((float)$row['revenue'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$topProducts): ?><tr><td colspan="4" class="muted">Nothing sold in this range.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>By category</h2>
            <a class="link-more" href="<?= e($exportUrl('categories')) ?>">CSV</a>
        </div>
        <table class="admin-table">
            <thead><tr><th>Category</th><th class="num">Units</th><th class="num">Revenue</th></tr></thead>
            <tbody>
            <?php foreach ($byCategory as $row): ?>
                <tr>
                    <td><?= e($row['category']) ?></td>
                    <td class="num"><?= (int)$row['units'] ?></td>
                    <td class="num"><?= e(money((float)$row['revenue'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$byCategory): ?><tr><td colspan="3" class="muted">No sales yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Payment mix</h2>
        </div>
        <table class="admin-table">
            <thead><tr><th>Method</th><th>Payment</th><th class="num">Orders</th><th class="num">Value</th></tr></thead>
            <tbody>
            <?php foreach ($byPayment as $row): ?>
                <tr>
                    <td><?= e(payment_method_label((string)$row['payment_method'])) ?></td>
                    <td><?= status_badge((string)$row['payment_status']) ?></td>
                    <td class="num"><?= (int)$row['orders'] ?></td>
                    <td class="num"><?= e(money((float)$row['revenue'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$byPayment): ?><tr><td colspan="4" class="muted">No orders in this range.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Order status</h2>
        </div>
        <table class="admin-table">
            <thead><tr><th>Status</th><th class="num">Orders</th><th class="num">Value</th></tr></thead>
            <tbody>
            <?php foreach ($byStatus as $row): ?>
                <tr>
                    <td><?= status_badge((string)$row['status']) ?></td>
                    <td class="num"><?= (int)$row['orders'] ?></td>
                    <td class="num"><?= e(money((float)$row['revenue'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Best customers</h2>
            <a class="link-more" href="<?= e($exportUrl('customers')) ?>">CSV</a>
        </div>
        <table class="admin-table">
            <thead><tr><th>Customer</th><th class="num">Orders</th><th class="num">Spend</th></tr></thead>
            <tbody>
            <?php foreach ($byCustomer as $row): ?>
                <tr>
                    <td>
                        <?= e($row['customer']) ?>
                        <em class="muted small d-block"><?= e($row['email']) ?></em>
                    </td>
                    <td class="num"><?= (int)$row['orders'] ?></td>
                    <td class="num"><?= e(money((float)$row['revenue'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$byCustomer): ?><tr><td colspan="3" class="muted">No customers yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Coupon performance</h2>
        </div>
        <table class="admin-table">
            <thead><tr><th>Code</th><th class="num">Orders</th><th class="num">Discount</th></tr></thead>
            <tbody>
            <?php foreach ($couponUse as $row): ?>
                <tr>
                    <td><code><?= e((string)$row['coupon_code']) ?></code></td>
                    <td class="num"><?= (int)$row['orders'] ?></td>
                    <td class="num"><?= e(money((float)$row['discount_given'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$couponUse): ?><tr><td colspan="3" class="muted">No coupons redeemed in this range.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
