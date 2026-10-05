<?php
/**
 * ShopHub admin — customers
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$pageTitle = 'Customers';

if (is_post()) {
    require_csrf();
    $do = (string)post('do');
    $id = (int)post('id');

    if ($do === 'toggle_status') {
        $row = Database::row('SELECT role, status FROM users WHERE id = ?', [$id]);
        if ($row && $row['role'] === 'admin') {
            flash('danger', 'Administrator accounts cannot be suspended here.');
        } else {
            Database::run("UPDATE users SET status = CASE WHEN status = 'active' THEN 'suspended' ELSE 'active' END WHERE id = ?", [$id]);
            flash('success', 'Customer status updated.');
        }
    } elseif ($do === 'make_admin') {
        Database::run("UPDATE users SET role = 'admin' WHERE id = ?", [$id]);
        flash('success', 'User promoted to administrator.');
    } elseif ($do === 'delete') {
        $row = Database::row('SELECT role FROM users WHERE id = ?', [$id]);
        if ($row && $row['role'] === 'admin') {
            flash('danger', 'Administrators cannot be deleted from this screen.');
        } else {
            Database::run('DELETE FROM addresses WHERE user_id = ?', [$id]);
            Database::run('DELETE FROM wishlists WHERE user_id = ?', [$id]);
            Database::run('UPDATE orders SET user_id = NULL WHERE user_id = ?', [$id]);
            Database::run('DELETE FROM users WHERE id = ?', [$id]);
            flash('success', 'Customer deleted. Their orders remain for your records.');
        }
    }
    redirect('admin/customers.php');
}

$search = (string)query('q', '');
$where  = "role = 'customer'";
$params = [];
if ($search !== '') {
    $where .= ' AND (name LIKE ? OR email LIKE ? OR phone LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}

$customers = Database::all(
    "SELECT u.*,
            (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id) AS order_count,
            (SELECT COALESCE(SUM(o.total),0) FROM orders o WHERE o.user_id = u.id AND o.status NOT IN ('cancelled','refunded')) AS spend
     FROM users u WHERE $where ORDER BY u.created_at DESC LIMIT 200",
    $params
);

$detail = null;
if ((int)query('id')) {
    $detail = Database::row('SELECT * FROM users WHERE id = ?', [(int)query('id')]);
}

include __DIR__ . '/includes/header.php';
?>

<?php if ($detail): ?>
    <?php
    $detailOrders = Database::all('SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC', [(int)$detail['id']]);
    $detailAddresses = Database::all('SELECT * FROM addresses WHERE user_id = ?', [(int)$detail['id']]);
    ?>
    <section class="panel">
        <div class="panel-head">
            <h2><?= e($detail['name']) ?></h2>
            <a class="link-more" href="<?= e(base_url('customers.php')) ?>">← All customers</a>
        </div>
        <div class="admin-cols">
            <div>
                <p>
                    <strong>Email</strong> <a href="mailto:<?= e($detail['email']) ?>"><?= e($detail['email']) ?></a><br>
                    <strong>Phone</strong> <?= e((string)$detail['phone'] ?: '—') ?><br>
                    <strong>Joined</strong> <?= e(nice_date((string)$detail['created_at'])) ?><br>
                    <strong>Last login</strong> <?= e(nice_date((string)$detail['last_login'], 'M j, Y g:i a')) ?><br>
                    <strong>Status</strong> <?= status_badge((string)$detail['status']) ?>
                </p>
            </div>
            <div>
                <h3>Addresses (<?= count($detailAddresses) ?>)</h3>
                <?php foreach ($detailAddresses as $a): ?>
                    <p class="muted small"><?= e($a['address']) ?>, <?= e($a['city']) ?> · <?= e($a['phone']) ?></p>
                <?php endforeach; ?>
                <?php if (!$detailAddresses): ?><p class="muted small">No saved addresses.</p><?php endif; ?>
            </div>
        </div>
        <h3 class="mt">Orders (<?= count($detailOrders) ?>)</h3>
        <table class="table">
            <thead><tr><th>Reference</th><th>Date</th><th>Status</th><th class="num">Total</th></tr></thead>
            <tbody>
            <?php foreach ($detailOrders as $o): ?>
                <tr>
                    <td><a href="<?= e(base_url('order-view.php?id=' . (int)$o['id'])) ?>"><?= e((string)$o['reference']) ?></a></td>
                    <td><?= e(nice_date((string)$o['created_at'])) ?></td>
                    <td><?= status_badge((string)$o['status']) ?></td>
                    <td class="num"><?= e(money((float)$o['total'], (string)$o['currency'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$detailOrders): ?><tr><td colspan="4" class="muted">No orders yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>

<form method="get" class="admin-toolbar">
    <input type="search" name="q" placeholder="Search name, email or phone…" value="<?= e($search) ?>">
    <button class="btn btn-outline btn-sm" type="submit">Search</button>
    <span class="admin-toolbar-spacer"></span>
    <span class="muted small"><?= count($customers) ?> customer(s)</span>
</form>

<section class="panel">
    <div class="panel-head panel-head-flush">
        <h2>Customers</h2>
        <input type="search" class="table-filter" placeholder="Filter this list…" data-table-filter="#customers-table">
    </div>
    <table class="table table-admin" id="customers-table">
        <thead><tr><th>Customer</th><th>Contact</th><th class="num">Orders</th><th class="num">Lifetime spend</th><th>Status</th><th class="actions-col">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($customers as $c): ?>
            <tr>
                <td>
                    <a href="?id=<?= (int)$c['id'] ?>"><strong><?= e($c['name']) ?></strong></a><br>
                    <span class="muted small">Joined <?= e(nice_date((string)$c['created_at'])) ?></span>
                </td>
                <td><?= e($c['email']) ?><br><span class="muted small"><?= e((string)$c['phone'] ?: '—') ?></span></td>
                <td class="num"><?= (int)$c['order_count'] ?></td>
                <td class="num"><?= e(money((float)$c['spend'])) ?></td>
                <td><?= status_badge((string)$c['status']) ?></td>
                <td class="actions-col">
                    <form method="post" class="inline-form"><?= csrf_field() ?>
                        <input type="hidden" name="do" value="toggle_status"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                        <button class="btn btn-ghost btn-sm" type="submit"><?= $c['status'] === 'active' ? 'Suspend' : 'Reactivate' ?></button>
                    </form>
                    <form method="post" class="inline-form"><?= csrf_field() ?>
                        <input type="hidden" name="do" value="make_admin"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                        <button class="btn btn-ghost btn-sm" type="submit" data-confirm="Give this user administrator access?">Make admin</button>
                    </form>
                    <form method="post" class="inline-form" data-confirm-form="Delete this customer? Orders are kept."><?= csrf_field() ?>
                        <input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                        <button class="icon-btn danger" type="submit" title="Delete"><?= icon('trash') ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$customers): ?><tr><td colspan="6" class="muted">No customers match.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
