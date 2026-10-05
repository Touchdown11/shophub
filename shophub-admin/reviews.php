<?php
/**
 * ShopHub admin — review moderation
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$pageTitle = 'Reviews';

if (is_post()) {
    require_csrf();
    $id = (int)post('id');
    $do = (string)post('do');
    if ($do === 'approve') {
        Database::run("UPDATE reviews SET status = 'approved' WHERE id = ?", [$id]);
        flash('success', 'Review published.');
    } elseif ($do === 'reject') {
        Database::run("UPDATE reviews SET status = 'rejected' WHERE id = ?", [$id]);
        flash('success', 'Review hidden.');
    } elseif ($do === 'delete') {
        Database::run('DELETE FROM reviews WHERE id = ?', [$id]);
        flash('success', 'Review deleted.');
    }
    redirect('admin/reviews.php?status=' . urlencode((string)query('status', 'pending')));
}

$status = (string)query('status', 'pending');
$where  = in_array($status, ['pending', 'approved', 'rejected'], true) ? 'WHERE r.status = ?' : '';
$params = $where ? [$status] : [];

$rows = Database::all(
    "SELECT r.*, p.name AS product_name, p.slug AS product_slug
     FROM reviews r LEFT JOIN products p ON p.id = r.product_id
     $where ORDER BY r.created_at DESC LIMIT 100",
    $params
);

$counts = [];
foreach (Database::all('SELECT status, COUNT(*) c FROM reviews GROUP BY status') as $row) {
    $counts[$row['status']] = (int)$row['c'];
}

include __DIR__ . '/includes/header.php';
?>

<nav class="admin-tabs">
    <?php foreach (['pending' => 'Pending', 'approved' => 'Published', 'rejected' => 'Hidden', '' => 'All'] as $key => $label): ?>
        <a class="admin-tab<?= $status === $key ? ' is-active' : '' ?>" href="?status=<?= e($key) ?>">
            <?= e($label) ?><?= isset($counts[$key]) ? ' <em>' . (int)$counts[$key] . '</em>' : '' ?>
        </a>
    <?php endforeach; ?>
</nav>

<section class="panel">
    <table class="table table-admin">
        <thead><tr><th>Product</th><th>Reviewer</th><th>Rating</th><th>Review</th><th>Status</th><th class="actions-col">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td>
                    <?php if ($r['product_slug']): ?>
                        <a href="<?= e(base_url('product.php?slug=' . urlencode((string)$r['product_slug']))) ?>" target="_blank" rel="noopener"><?= e(excerpt((string)$r['product_name'], 40)) ?></a>
                    <?php else: ?>
                        <span class="muted">Deleted product</span>
                    <?php endif; ?>
                </td>
                <td><?= e((string)$r['name']) ?><br><span class="muted small"><?= e(nice_date((string)$r['created_at'])) ?></span></td>
                <td><?= stars((float)$r['rating']) ?></td>
                <td>
                    <?php if ($r['title']): ?><strong><?= e((string)$r['title']) ?></strong><br><?php endif; ?>
                    <span class="muted small"><?= e(excerpt((string)$r['body'], 140)) ?></span>
                </td>
                <td><?= status_badge((string)$r['status']) ?></td>
                <td class="actions-col">
                    <?php if ($r['status'] !== 'approved'): ?>
                        <form method="post" class="inline-form"><?= csrf_field() ?>
                            <input type="hidden" name="do" value="approve"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-outline btn-sm" type="submit">Approve</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($r['status'] !== 'rejected'): ?>
                        <form method="post" class="inline-form"><?= csrf_field() ?>
                            <input type="hidden" name="do" value="reject"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-ghost btn-sm" type="submit">Hide</button>
                        </form>
                    <?php endif; ?>
                    <form method="post" class="inline-form" data-confirm-form="Delete this review permanently?"><?= csrf_field() ?>
                        <input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="icon-btn danger" type="submit" title="Delete"><?= icon('trash') ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="muted">Nothing here.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
