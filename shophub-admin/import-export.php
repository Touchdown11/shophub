<?php
/**
 * ShopHub staff portal — CSV import & export
 *
 * Export: products (with variants flattened), orders, customers, reviews.
 * Import: products (create or update by SKU), with a dry-run preview first.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/csv.php';
require_admin();

/* ------------------------------------------------------------------ columns -- */

const PRODUCT_CSV_HEADERS = [
    'sku', 'name', 'slug', 'category', 'brand', 'price', 'sale_price', 'stock',
    'short_description', 'description', 'is_featured', 'is_active',
    'option_names', 'option_values', 'variant_sku', 'variant_price', 'variant_stock',
];

const ORDER_CSV_HEADERS = [
    'reference', 'placed_at', 'status', 'payment_method', 'payment_status', 'paid_at',
    'customer_name', 'email', 'phone', 'address', 'city', 'postal_code', 'country',
    'items', 'subtotal', 'discount', 'shipping', 'tax', 'total', 'currency', 'coupon', 'notes',
];

/* ------------------------------------------------------------------ exports -- */

$export = (string)query('export');
if ($export !== '') {
    switch ($export) {
        case 'products':
            $rows = [];
            foreach (Database::all('SELECT * FROM products ORDER BY id') as $p) {
                $category = $p['category_id']
                    ? (string)Database::value('SELECT name FROM categories WHERE id = ?', [(int)$p['category_id']])
                    : '';
                $variants = product_variants((int)$p['id']);

                if (!$variants) {
                    $rows[] = [
                        $p['sku'], $p['name'], $p['slug'], $category, $p['brand'],
                        $p['price'], $p['sale_price'], $p['stock'],
                        $p['short_desc'], $p['description'], $p['is_featured'], $p['is_active'],
                        '', '', '', '', '',
                    ];
                    continue;
                }

                // One row per variant; the product columns repeat so the file
                // round-trips straight back through the importer.
                $optionNames  = implode('|', array_keys($variants[0]['options']));
                foreach ($variants as $v) {
                    $rows[] = [
                        $p['sku'], $p['name'], $p['slug'], $category, $p['brand'],
                        $p['price'], $p['sale_price'], variants_stock_total($p),
                        $p['short_desc'], $p['description'], $p['is_featured'], $p['is_active'],
                        $optionNames,
                        implode('|', array_values($v['options'])),
                        $v['sku'],
                        $v['price'] !== null ? number_format((float)$v['price'], 2, '.', '') : '',
                        (int)$v['stock'],
                    ];
                }
            }
            csv_stream('shophub-products_' . date('Y-m-d') . '.csv', PRODUCT_CSV_HEADERS, $rows);

        case 'orders':
            $rows = [];
            foreach (Database::all('SELECT * FROM orders ORDER BY created_at DESC') as $o) {
                $items = array_map(
                    static fn($i) => $i['quantity'] . '× ' . $i['product_name']
                        . ($i['variant_label'] !== '' ? ' (' . $i['variant_label'] . ')' : ''),
                    order_items((int)$o['id'])
                );
                $rows[] = [
                    $o['reference'], $o['created_at'], $o['status'],
                    payment_method_label((string)$o['payment_method']), $o['payment_status'], (string)$o['paid_at'],
                    $o['customer_name'], $o['email'], $o['phone'], $o['shipping_address'], $o['city'],
                    $o['postal_code'], $o['country'], implode(' | ', $items),
                    $o['subtotal'], $o['discount'], $o['shipping_fee'], $o['tax'], $o['total'],
                    $o['currency'], (string)$o['coupon_code'], (string)$o['notes'],
                ];
            }
            csv_stream('shophub-orders_' . date('Y-m-d') . '.csv', ORDER_CSV_HEADERS, $rows);

        case 'customers':
            $rows = array_map(static fn($u) => [
                $u['name'], $u['email'], (string)$u['phone'], $u['role'], $u['status'],
                $u['email_verified_at'] ? 'verified' : 'unconfirmed',
                (int)Database::value('SELECT COUNT(*) FROM orders WHERE email = ?', [$u['email']]),
                number_format((float)Database::value(
                    'SELECT COALESCE(SUM(total),0) FROM orders WHERE email = ? AND status NOT IN (\'cancelled\',\'refunded\')',
                    [$u['email']]
                ), 2, '.', ''),
                $u['created_at'],
            ], Database::all('SELECT * FROM users ORDER BY id'));
            csv_stream(
                'shophub-customers_' . date('Y-m-d') . '.csv',
                ['name', 'email', 'phone', 'role', 'status', 'email_verified', 'orders', 'lifetime_spend', 'joined'],
                $rows
            );

        case 'reviews':
            $rows = array_map(static fn($r) => [
                $r['product_name'] ?? (string)Database::value('SELECT name FROM products WHERE id = ?', [(int)$r['product_id']]),
                $r['name'], (int)$r['rating'], (string)$r['title'], (string)$r['body'], $r['status'], $r['created_at'],
            ], Database::all('SELECT r.*, p.name AS product_name FROM reviews r LEFT JOIN products p ON p.id = r.product_id ORDER BY r.created_at DESC'));
            csv_stream(
                'shophub-reviews_' . date('Y-m-d') . '.csv',
                ['product', 'reviewer', 'rating', 'title', 'body', 'status', 'created_at'],
                $rows
            );

        case 'template':
            csv_stream('shophub-product-import-template.csv', PRODUCT_CSV_HEADERS, [[
                'MER-3121', 'Merino Beanie', '', 'Fashion', 'Northbound',
                '39.00', '', '24', 'Soft merino beanie', '<p>Fine-gauge merino, one size.</p>', '0', '1',
                'Colour', 'Black', 'MER-3121-BLK', '', '12',
            ], [
                'MER-3121', 'Merino Beanie', '', 'Fashion', 'Northbound',
                '39.00', '', '24', 'Soft merino beanie', '<p>Fine-gauge merino, one size.</p>', '0', '1',
                'Colour', 'Camel', 'MER-3121-CAM', '', '12',
            ]]);
    }
    flash('warn', 'Unknown export type.');
    redirect('import-export.php');
}

/* ------------------------------------------------------------------------------
 * Import. Two phases so nothing is written before the operator sees the plan:
 *   ?import_step=preview  → parse the upload, store it, show what will happen
 *   POST action=commit    → apply the stored plan
 * --------------------------------------------------------------------------- */

$report = null;
$errors = [];

if (is_post() && (string)post('action') === 'commit') {
    require_csrf();
    $plan = $_SESSION['csv_plan'] ?? null;
    if (!$plan) {
        $errors[] = 'That import plan has expired — please upload the file again.';
    } else {
        $report = csv_apply_product_import($plan);
        unset($_SESSION['csv_plan']);
    }
}

if (is_post() && (string)post('action') === 'preview') {
    require_csrf();
    $file = $_FILES['csv'] ?? null;

    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Choose a CSV file first.';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'The upload failed (error code ' . (int)$file['error'] . ').';
    } elseif (($file['size'] ?? 0) > 4 * 1024 * 1024) {
        $errors[] = 'Keep import files under 4 MB.';
    } else {
        $parsed = csv_parse_product_file((string)$file['tmp_name']);
        if (!$parsed['ok']) {
            $errors[] = $parsed['message'];
        } else {
            $_SESSION['csv_plan'] = $parsed['plan'];
            $_SESSION['csv_filename'] = (string)$file['name'];
            $report = ['mode' => 'preview', 'plan' => $parsed['plan'], 'filename' => (string)$file['name']];
        }
    }
}

$pageTitle = 'Import / export';
$pageSubtitle = 'Move catalogue and order data in and out as CSV';
include __DIR__ . '/includes/header.php';
?>

<div class="split-2">
    <section class="panel">
        <h2>Export</h2>
        <p class="muted">Everything downloads as UTF-8 CSV that opens cleanly in Excel, Numbers and Sheets.</p>
        <div class="export-grid">
            <a class="export-card" href="<?= e(base_url('import-export.php?export=products')) ?>">
                <?= admin_icon('box') ?>
                <strong>Products</strong>
                <em class="muted small">Including every variant row</em>
            </a>
            <a class="export-card" href="<?= e(base_url('import-export.php?export=orders')) ?>">
                <?= admin_icon('truck') ?>
                <strong>Orders</strong>
                <em class="muted small">With line items and payment state</em>
            </a>
            <a class="export-card" href="<?= e(base_url('import-export.php?export=customers')) ?>">
                <?= admin_icon('users') ?>
                <strong>Customers</strong>
                <em class="muted small">Orders, spend and verification</em>
            </a>
            <a class="export-card" href="<?= e(base_url('import-export.php?export=reviews')) ?>">
                <?= admin_icon('star') ?>
                <strong>Reviews</strong>
                <em class="muted small">Moderation state included</em>
            </a>
        </div>
        <p class="muted small" style="margin-top:1rem">
            Need date-bounded numbers? Use the CSV buttons on
            <a href="<?= e(base_url('reports.php')) ?>">Sales reports</a>.
        </p>
    </section>

    <section class="panel">
        <h2>Import products</h2>
        <p class="muted">
            Upload a CSV to create new products or refresh existing ones. Rows are matched on <strong>SKU</strong>;
            an existing SKU updates in place, a new one is created. Variant rows share a product SKU and differ in
            <code>option_values</code>.
        </p>

        <?php foreach ($errors as $err): ?>
            <div class="flash flash-danger"><span><?= e($err) ?></span></div>
        <?php endforeach; ?>

        <form method="post" enctype="multipart/form-data" class="stack-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="preview">
            <label class="field">
                <span>CSV file</span>
                <input type="file" name="csv" accept=".csv,text/csv" required>
            </label>
            <div class="form-actions">
                <button class="btn btn-gold" type="submit"><?= admin_icon('upload') ?> Preview import</button>
                <a class="btn btn-ghost" href="<?= e(base_url('import-export.php?export=template')) ?>"><?= admin_icon('download') ?> Download template</a>
            </div>
        </form>

        <details class="admin-details">
            <summary>Column reference</summary>
            <div class="table-scroll">
                <table class="admin-table compact">
                    <thead><tr><th>Column</th><th>Notes</th></tr></thead>
                    <tbody>
                        <tr><td><code>sku</code></td><td>Required. The match key for updates.</td></tr>
                        <tr><td><code>name</code></td><td>Required product title.</td></tr>
                        <tr><td><code>slug</code></td><td>Optional; generated from the name when blank.</td></tr>
                        <tr><td><code>category</code></td><td>Category name — created if it does not exist.</td></tr>
                        <tr><td><code>price</code></td><td>Number, no currency symbol.</td></tr>
                        <tr><td><code>sale_price</code></td><td>Leave blank for no discount.</td></tr>
                        <tr><td><code>stock</code></td><td>Ignored for variant rows (variant stock wins).</td></tr>
                        <tr><td><code>is_featured</code> / <code>is_active</code></td><td><code>1</code> or <code>0</code>.</td></tr>
                        <tr><td><code>option_names</code></td><td>Pipe-separated, e.g. <code>Size|Colour</code>. Empty for a simple product.</td></tr>
                        <tr><td><code>option_values</code></td><td>Pipe-separated, same order — e.g. <code>M|Black</code>.</td></tr>
                        <tr><td><code>variant_sku</code> / <code>variant_price</code> / <code>variant_stock</code></td><td>One row per combination.</td></tr>
                    </tbody>
                </table>
            </div>
        </details>
    </section>
</div>

<?php if ($report && ($report['mode'] ?? '') === 'preview'): ?>
    <?php $plan = $report['plan']; ?>
    <section class="panel">
        <div class="panel-head">
            <h2>Preview — <?= e($report['filename']) ?></h2>
            <span class="badge badge-gold"><?= count($plan['products']) ?> product group(s)</span>
        </div>

        <div class="stat-cards">
            <div class="stat-card"><span class="muted">New products</span><strong><?= count($plan['create']) ?></strong></div>
            <div class="stat-card"><span class="muted">Updates</span><strong><?= count($plan['update']) ?></strong></div>
            <div class="stat-card"><span class="muted">Variant rows</span><strong><?= (int)$plan['variant_rows'] ?></strong></div>
            <div class="stat-card"><span class="muted">Skipped rows</span><strong><?= count($plan['skipped']) ?></strong></div>
        </div>

        <div class="table-scroll">
            <table class="admin-table">
                <thead><tr><th>Action</th><th>SKU</th><th>Name</th><th>Category</th><th class="num">Price</th><th class="num">Variants</th></tr></thead>
                <tbody>
                <?php foreach ($plan['products'] as $sku => $group): $isNew = isset($plan['create'][$sku]); ?>
                    <tr>
                        <td><span class="badge <?= $isNew ? 'badge-ok' : 'badge-info' ?>"><?= $isNew ? 'Create' : 'Update' ?></span></td>
                        <td><code><?= e((string)$sku) ?></code></td>
                        <td><?= e((string)$group['name']) ?></td>
                        <td class="muted"><?= e((string)$group['category']) ?></td>
                        <td class="num"><?= e(money((float)$group['price'])) ?></td>
                        <td class="num"><?= count($group['variants']) ?: '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($plan['skipped']): ?>
            <details class="admin-details">
                <summary><?= count($plan['skipped']) ?> row(s) will be skipped</summary>
                <ul class="muted small">
                    <?php foreach (array_slice($plan['skipped'], 0, 25) as $skip): ?>
                        <li>Line <?= (int)$skip['line'] ?>: <?= e($skip['reason']) ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>

        <form method="post" class="form-actions">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="commit">
            <button class="btn btn-gold btn-lg" type="submit"><?= admin_icon('check') ?> Import <?= count($plan['products']) ?> product(s)</button>
            <a class="btn btn-ghost" href="<?= e(base_url('import-export.php')) ?>">Cancel</a>
        </form>
    </section>
<?php endif; ?>

<?php if ($report && ($report['mode'] ?? '') === 'committed'): ?>
    <section class="panel">
        <h2>Import complete</h2>
        <div class="stat-cards">
            <div class="stat-card"><span class="muted">Created</span><strong><?= (int)$report['created'] ?></strong></div>
            <div class="stat-card"><span class="muted">Updated</span><strong><?= (int)$report['updated'] ?></strong></div>
            <div class="stat-card"><span class="muted">Variants saved</span><strong><?= (int)$report['variants'] ?></strong></div>
            <div class="stat-card"><span class="muted">Skipped</span><strong><?= count($report['skipped']) ?></strong></div>
        </div>
        <?php if ($report['messages']): ?>
            <ul class="muted small">
                <?php foreach (array_slice($report['messages'], 0, 30) as $line): ?><li><?= e($line) ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <div class="form-actions">
            <a class="btn btn-gold" href="<?= e(base_url('products.php')) ?>">View products</a>
            <a class="btn btn-ghost" href="<?= e(base_url('import-export.php')) ?>">Import another file</a>
        </div>
    </section>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
