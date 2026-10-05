<?php
/**
 * ShopHub admin — categories
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$pageTitle = 'Categories';
$editId   = (int)query('id');
$editing  = $editId ? Database::row('SELECT * FROM categories WHERE id = ?', [$editId]) : null;

if (is_post()) {
    require_csrf();
    $do = (string)post('do');

    if ($do === 'save') {
        $id   = (int)post('id');
        $name = (string)post('name');
        if ($name === '') {
            flash('danger', 'Category name is required.');
            redirect('admin/categories.php');
        }
        $data = [
            'name'        => $name,
            'slug'        => unique_slug('categories', (string)(post('slug') ?: $name), $id ?: null),
            'description' => (string)post('description'),
            'image'       => (string)post('image'),
            'parent_id'   => (int)post('parent_id') ?: null,
            'sort_order'  => (int)post('sort_order'),
            'is_active'   => post('is_active') ? 1 : 0,
        ];
        $upload = upload_image($_FILES['image_file'] ?? [], 'category');
        if ($upload['ok']) {
            $data['image'] = $upload['path'];
        }
        if ($id) {
            Database::update('categories', $data, 'id = :wid', ['wid' => $id]);
            flash('success', 'Category updated.');
        } else {
            Database::insert('categories', $data);
            flash('success', 'Category created.');
        }
    } elseif ($do === 'delete') {
        $id = (int)post('id');
        Database::run('UPDATE products SET category_id = NULL WHERE category_id = ?', [$id]);
        Database::run('UPDATE categories SET parent_id = NULL WHERE parent_id = ?', [$id]);
        Database::run('DELETE FROM categories WHERE id = ?', [$id]);
        flash('success', 'Category deleted — its products are now uncategorised.');
    }
    redirect('admin/categories.php');
}

$rows = Database::all(
    'SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
     FROM categories c ORDER BY c.sort_order, c.name'
);

include __DIR__ . '/includes/header.php';
?>

<div class="admin-cols admin-cols-form">
    <section class="panel">
        <div class="panel-head"><h2>All categories</h2><span class="muted small"><?= count($rows) ?> total</span></div>
        <table class="table table-admin">
            <thead><tr><th>Name</th><th>Slug</th><th class="num">Products</th><th>Visible</th><th class="actions-col">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $c): ?>
                <tr>
                    <td>
                        <div class="cell-product">
                            <img src="<?= e(product_image($c['image'], (string)$c['name'])) ?>" alt="">
                            <div>
                                <strong><?= e($c['name']) ?></strong>
                                <span class="muted small"><?= e(excerpt((string)$c['description'], 60)) ?></span>
                            </div>
                        </div>
                    </td>
                    <td><code><?= e($c['slug']) ?></code></td>
                    <td class="num"><?= (int)$c['product_count'] ?></td>
                    <td><?= (int)$c['is_active'] ? '<span class="badge badge-ok">Yes</span>' : '<span class="badge badge-muted">No</span>' ?></td>
                    <td class="actions-col">
                        <a class="icon-btn" href="?id=<?= (int)$c['id'] ?>" title="Edit"><?= icon('edit') ?></a>
                        <form method="post" class="inline-form" data-confirm-form="Delete this category?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="do" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <button class="icon-btn danger" type="submit" title="Delete"><?= icon('trash') ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="panel">
        <h2><?= $editing ? 'Edit category' : 'New category' ?></h2>
        <form method="post" enctype="multipart/form-data" class="stack-form">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="save">
            <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
            <label class="field"><span>Name *</span><input type="text" name="name" value="<?= e((string)($editing['name'] ?? '')) ?>" required></label>
            <label class="field"><span>Slug</span><input type="text" name="slug" value="<?= e((string)($editing['slug'] ?? '')) ?>" placeholder="auto"></label>
            <label class="field"><span>Description</span><textarea name="description" rows="3"><?= e((string)($editing['description'] ?? '')) ?></textarea></label>
            <label class="field"><span>Image URL</span><input type="text" name="image" value="<?= e((string)($editing['image'] ?? '')) ?>" placeholder="https://… or upload below"></label>
            <label class="field"><span>Or upload image</span><input type="file" name="image_file" accept="image/*"></label>
            <div class="form-grid">
                <label class="field"><span>Parent</span>
                    <select name="parent_id">
                        <option value="">— none —</option>
                        <?php foreach ($rows as $c): if ((int)$c['id'] === (int)($editing['id'] ?? 0)) continue; ?>
                            <option value="<?= (int)$c['id'] ?>" <?= (int)($editing['parent_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field"><span>Sort order</span><input type="number" name="sort_order" value="<?= (int)($editing['sort_order'] ?? 0) ?>"></label>
            </div>
            <label class="switch-row">
                <input type="checkbox" name="is_active" value="1" <?= (int)($editing['is_active'] ?? 1) ? 'checked' : '' ?>>
                <span>Visible in store</span>
            </label>
            <div class="admin-actions">
                <button class="btn btn-gold" type="submit"><?= $editing ? 'Save category' : 'Create category' ?></button>
                <?php if ($editing): ?><a class="btn btn-ghost" href="<?= e(base_url('categories.php')) ?>">Cancel</a><?php endif; ?>
            </div>
        </form>
    </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
