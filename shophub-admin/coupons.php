<?php
/**
 * ShopHub admin — coupons
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$pageTitle = 'Coupons';

$editId  = (int)query('id');
$editing = $editId ? Database::row('SELECT * FROM coupons WHERE id = ?', [$editId]) : null;

if (is_post()) {
    require_csrf();
    $do = (string)post('do');

    if ($do === 'save') {
        $id   = (int)post('id');
        $code = strtoupper((string)post('code'));
        if ($code === '' || !preg_match('/^[A-Z0-9\-]{3,32}$/', $code)) {
            flash('danger', 'Coupon code must be 3–32 letters, numbers or dashes.');
            redirect('admin/coupons.php');
        }
        $data = [
            'code'         => $code,
            'type'         => post('type') === 'fixed' ? 'fixed' : 'percent',
            'value'        => (float)post('value'),
            'min_subtotal' => (float)post('min_subtotal'),
            'max_uses'     => (int)post('max_uses'),
            'starts_at'    => post('starts_at') ?: null,
            'expires_at'   => post('expires_at') ?: null,
            'is_active'    => post('is_active') ? 1 : 0,
        ];
        if ($id) {
            Database::update('coupons', $data, 'id = :wid', ['wid' => $id]);
            flash('success', 'Coupon updated.');
        } else {
            $exists = Database::value('SELECT COUNT(*) FROM coupons WHERE code = ?', [$code]);
            if ($exists) {
                flash('danger', 'That code already exists.');
            } else {
                Database::insert('coupons', $data);
                flash('success', 'Coupon “' . $code . '” created.');
            }
        }
    } elseif ($do === 'delete') {
        Database::run('DELETE FROM coupons WHERE id = ?', [(int)post('id')]);
        flash('success', 'Coupon deleted.');
    } elseif ($do === 'toggle') {
        Database::run('UPDATE coupons SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ?', [(int)post('id')]);
        flash('success', 'Coupon updated.');
    }
    redirect('admin/coupons.php');
}

$coupons = Database::all('SELECT * FROM coupons ORDER BY is_active DESC, id DESC');

include __DIR__ . '/includes/header.php';
?>

<div class="admin-cols admin-cols-form">
    <section class="panel">
        <div class="panel-head">
            <h2>All coupons</h2>
            <input type="search" class="table-filter" placeholder="Filter this list…" data-table-filter="#coupons-table">
        </div>
        <table class="table table-admin" id="coupons-table">
            <thead><tr><th>Code</th><th>Discount</th><th>Min. spend</th><th>Usage</th><th>Window</th><th>Status</th><th class="actions-col">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($coupons as $c): ?>
                <tr>
                    <td><code><?= e((string)$c['code']) ?></code></td>
                    <td><?= $c['type'] === 'percent' ? (float)$c['value'] . '%' : e(money((float)$c['value'])) ?></td>
                    <td><?= (float)$c['min_subtotal'] > 0 ? e(money((float)$c['min_subtotal'])) : '—' ?></td>
                    <td><?= (int)$c['used_count'] ?><?= (int)$c['max_uses'] ? ' / ' . (int)$c['max_uses'] : '' ?></td>
                    <td class="muted small">
                        <?= $c['starts_at'] ? e(nice_date((string)$c['starts_at'])) : 'now' ?>
                        → <?= $c['expires_at'] ? e(nice_date((string)$c['expires_at'])) : '∞' ?>
                    </td>
                    <td><?= (int)$c['is_active'] ? '<span class="badge badge-ok">Active</span>' : '<span class="badge badge-muted">Disabled</span>' ?></td>
                    <td class="actions-col">
                        <a class="icon-btn" href="?id=<?= (int)$c['id'] ?>" title="Edit"><?= icon('edit') ?></a>
                        <form method="post" class="inline-form"><?= csrf_field() ?>
                            <input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <button class="btn btn-ghost btn-sm" type="submit"><?= (int)$c['is_active'] ? 'Disable' : 'Enable' ?></button>
                        </form>
                        <form method="post" class="inline-form" data-confirm-form="Delete this coupon?"><?= csrf_field() ?>
                            <input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <button class="icon-btn danger" type="submit" title="Delete"><?= icon('trash') ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="panel">
        <h2><?= $editing ? 'Edit coupon' : 'New coupon' ?></h2>
        <form method="post" class="stack-form">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="save">
            <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
            <label class="field"><span>Code *</span>
                <input type="text" name="code" value="<?= e((string)($editing['code'] ?? '')) ?>" placeholder="WELCOME15" required style="text-transform:uppercase">
            </label>
            <div class="form-grid">
                <label class="field"><span>Type</span>
                    <select name="type">
                        <option value="percent" <?= ($editing['type'] ?? 'percent') === 'percent' ? 'selected' : '' ?>>Percent off</option>
                        <option value="fixed" <?= ($editing['type'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed amount</option>
                    </select>
                </label>
                <label class="field"><span>Value *</span>
                    <input type="number" step="0.01" min="0" name="value" value="<?= e((string)($editing['value'] ?? '15')) ?>" required>
                </label>
                <label class="field"><span>Minimum subtotal</span>
                    <input type="number" step="0.01" min="0" name="min_subtotal" value="<?= e((string)($editing['min_subtotal'] ?? '0')) ?>">
                </label>
                <label class="field"><span>Max uses (0 = ∞)</span>
                    <input type="number" min="0" name="max_uses" value="<?= (int)($editing['max_uses'] ?? 0) ?>">
                </label>
                <label class="field"><span>Starts</span>
                    <input type="datetime-local" name="starts_at" value="<?= e(!empty($editing['starts_at']) ? date('Y-m-d\TH:i', strtotime((string)$editing['starts_at'])) : '') ?>">
                </label>
                <label class="field"><span>Expires</span>
                    <input type="datetime-local" name="expires_at" value="<?= e(!empty($editing['expires_at']) ? date('Y-m-d\TH:i', strtotime((string)$editing['expires_at'])) : '') ?>">
                </label>
            </div>
            <label class="switch-row">
                <input type="checkbox" name="is_active" value="1" <?= (int)($editing['is_active'] ?? 1) ? 'checked' : '' ?>>
                <span>Active</span>
            </label>
            <div class="admin-actions">
                <button class="btn btn-gold" type="submit"><?= $editing ? 'Save coupon' : 'Create coupon' ?></button>
                <?php if ($editing): ?><a class="btn btn-ghost" href="<?= e(base_url('coupons.php')) ?>">Cancel</a><?php endif; ?>
            </div>
        </form>
    </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
