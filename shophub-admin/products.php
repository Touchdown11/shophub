<?php
/**
 * ShopHub staff portal — products
 *
 * List, create and edit products. The editor covers the whole catalogue
 * surface: pricing, stock, specifications, a rich-text description with a
 * WYSIWYG toolbar, drag-to-reorder gallery images and full size/colour
 * variants with their own SKU, price and stock.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$action = (string)query('action', 'list');
$editId = (int)query('id');
$errors = [];

/* ------------------------------------------------------------------ actions -- */

if (is_post()) {
    require_csrf();
    $do = (string)post('do');

    if ($do === 'save') {
        $id    = (int)post('id');
        $name  = trim((string)post('name'));
        $price = (float)post('price');
        $sale  = post('sale_price') !== '' ? (float)post('sale_price') : null;

        if ($name === '') {
            $errors[] = 'Product name is required.';
        }
        if ($price <= 0) {
            $errors[] = 'Price must be greater than zero.';
        }
        if ($sale !== null && $sale >= $price) {
            $errors[] = 'Sale price must be lower than the regular price.';
        }

        // Option groups: name + comma-separated values, one row each.
        $groups = [];
        foreach ((array)post('option_name', []) as $i => $optionName) {
            $optionName = trim((string)$optionName);
            $values = array_filter(array_map('trim', explode(',', (string)((array)post('option_values', []))[$i] ?? '')));
            if ($optionName !== '' && $values) {
                $groups[] = ['name' => $optionName, 'values' => array_values($values)];
            }
        }

        // Variant rows posted by the editor (JS keeps these in sync with the
        // option groups; the values are re-normalised here regardless).
        $variantRows = [];
        foreach ((array)post('variant_options', []) as $i => $options) {
            if (!is_array($options)) {
                continue;
            }
            $variantRows[] = [
                'options'   => $options,
                'sku'       => (string)((array)post('variant_sku', []))[$i] ?? '',
                'price'     => ((array)post('variant_price', []))[$i] ?? null,
                'stock'     => (int)(((array)post('variant_stock', []))[$i] ?? 0),
                'is_active' => !empty(((array)post('variant_active', []))[$i]) ? 1 : 0,
            ];
        }

        if (!$errors) {
            $specs = [];
            foreach ((array)post('spec_key', []) as $i => $key) {
                $key = trim((string)$key);
                if ($key !== '') {
                    $specs[$key] = trim((string)((array)post('spec_value', []))[$i] ?? '');
                }
            }

            $data = [
                'name'        => $name,
                'slug'        => unique_slug('products', (string)(post('slug') ?: $name), $id ?: null),
                'category_id' => (int)post('category_id') ?: null,
                'brand'       => (string)post('brand'),
                'sku'         => (string)post('sku'),
                'short_desc'  => (string)post('short_desc'),
                // WYSIWYG output is stored sanitised — the storefront re-checks it too.
                'description' => sanitize_html((string)post('description')),
                'specs'       => $specs ? json_encode($specs, JSON_UNESCAPED_UNICODE) : null,
                'price'       => $price,
                'sale_price'  => $sale,
                'stock'       => (int)post('stock'),
                'is_active'   => post('is_active') ? 1 : 0,
                'is_featured' => post('is_featured') ? 1 : 0,
                'updated_at'  => now(),
            ];

            $upload = upload_image($_FILES['image'] ?? []);
            if ($upload['ok']) {
                $data['image'] = $upload['path'];
            } elseif (!empty($upload['message']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $errors[] = $upload['message'];
            }

            if (!$errors) {
                // One transaction: a catalogue save either lands completely or not at all.
                $pdo = Database::pdo();
                $pdo->beginTransaction();
                try {
                if ($id) {
                    Database::update('products', $data, 'id = :wid', ['wid' => $id]);
                    flash('success', '“' . $name . '” updated.');
                } else {
                    $data['created_at'] = now();
                    $id = (int)Database::insert('products', $data);
                    flash('success', '“' . $name . '” created.');
                }

                // ---- variants ---------------------------------------------------
                // Keep any uploaded variant image, matched by combination.
                $existingImages = [];
                foreach (product_variants($id) as $variant) {
                    $existingImages[$variant['label']] = $variant['image'];
                }
                foreach ($variantRows as &$row) {
                    $label = variant_label($row['options']);
                    if (isset($existingImages[$label])) {
                        $row['image'] = $existingImages[$label];
                    }
                }
                unset($row);

                $result = save_variants($id, $groups, $variantRows);
                flash('success', $result['count'] . ' variant combination(s) saved' . ($result['count'] ? '' : ' — this is now a simple product.'));

                // ---- gallery uploads -------------------------------------------
                if (!empty($_FILES['gallery']['name'][0])) {
                    $count = count(product_gallery($id));
                    foreach ($_FILES['gallery']['name'] as $i => $filename) {
                        if ($filename === '') {
                            continue;
                        }
                        $file = [
                            'name'     => $filename,
                            'type'     => $_FILES['gallery']['type'][$i] ?? '',
                            'tmp_name' => $_FILES['gallery']['tmp_name'][$i] ?? '',
                            'error'    => $_FILES['gallery']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                            'size'     => $_FILES['gallery']['size'][$i] ?? 0,
                        ];
                        $up = upload_image($file, 'gallery');
                        if ($up['ok']) {
                            Database::insert('product_images', [
                                'product_id' => $id,
                                'image_path' => $up['path'],
                                'sort_order' => $count++,
                            ]);
                        }
                    }
                }

            $pdo->commit();
                    redirect('products.php?action=edit&id=' . $id);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = 'Could not save the product: ' . $e->getMessage();
                }
            }
        }

        $action = $id ? 'edit' : 'new';
        $editId = $id;
    }

    if ($do === 'delete') {
        $id = (int)post('id');
        Database::run('DELETE FROM product_images WHERE product_id = ?', [$id]);
        Database::run('DELETE FROM product_variants WHERE product_id = ?', [$id]);
        Database::run('DELETE FROM product_options WHERE product_id = ?', [$id]);
        Database::run('UPDATE order_items SET product_id = NULL WHERE product_id = ?', [$id]);
        Database::run('DELETE FROM products WHERE id = ?', [$id]);
        flash('success', 'Product deleted.');
        redirect('products.php');
    }

    if ($do === 'toggle') {
        $id = (int)post('id');
        Database::run('UPDATE products SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ?', [$id]);
        json_out(['ok' => true, 'message' => 'Availability updated.']);
    }

    if ($do === 'toggle_variant') {
        $variantId = (int)post('variant_id');
        Database::run('UPDATE product_variants SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ?', [$variantId]);
        $variant = find_variant($variantId);
        if ($variant) {
            sync_product_stock((int)$variant['product_id']);
        }
        json_out(['ok' => true, 'message' => 'Variant availability updated.']);
    }

    /* Drag-to-reorder: the browser sends the new order as a comma-separated id list. */
    if ($do === 'reorder_images') {
        $productId = (int)post('id');
        $order = array_filter(array_map('intval', explode(',', (string)post('order'))));
        foreach (array_values($order) as $position => $imageId) {
            Database::run(
                'UPDATE product_images SET sort_order = ? WHERE id = ? AND product_id = ?',
                [$position, $imageId, $productId]
            );
        }
        json_out(['ok' => true, 'message' => 'Image order saved.']);
    }

    if ($do === 'gallery_upload') {
        $productId = (int)post('id');
        $paths = [];
        $files = $_FILES['gallery'] ?? null;
        if ($productId && $files && !empty($files['name'][0])) {
            $position = count(product_gallery($productId));
            foreach ((array)$files['name'] as $i => $filename) {
                if ($filename === '' || ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                $up = upload_image([
                    'name'     => $filename,
                    'type'     => $files['type'][$i] ?? '',
                    'tmp_name' => $files['tmp_name'][$i] ?? '',
                    'error'    => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $files['size'][$i] ?? 0,
                ], 'gallery');
                if ($up['ok']) {
                    Database::insert('product_images', [
                        'product_id' => $productId,
                        'image_path' => $up['path'],
                        'sort_order' => $position++,
                    ]);
                    $paths[] = $up['path'];
                }
            }
        }
        json_out([
            'ok'      => (bool)$paths,
            'message' => $paths ? count($paths) . ' image(s) uploaded.' : 'No image was uploaded.',
            'images'  => $paths,
        ]);
    }

    if ($do === 'delete_image') {
        $imgId = (int)post('image_id');
        $img   = Database::row('SELECT * FROM product_images WHERE id = ?', [$imgId]);
        if ($img) {
            $path = store_path($img['image_path']);
            if (is_file($path)) {
                @unlink($path);
            }
            Database::run('DELETE FROM product_images WHERE id = ?', [$imgId]);
        }
        flash('success', 'Gallery image removed.');
        redirect('products.php?action=edit&id=' . (int)post('id'));
    }

    if ($do === 'bulk') {
        $ids = array_map('intval', (array)post('ids', []));
        $op  = (string)post('bulk_action');
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            if ($op === 'activate') {
                Database::run("UPDATE products SET is_active = 1 WHERE id IN ($in)", $ids);
            } elseif ($op === 'deactivate') {
                Database::run("UPDATE products SET is_active = 0 WHERE id IN ($in)", $ids);
            } elseif ($op === 'delete') {
                Database::run("DELETE FROM product_images WHERE product_id IN ($in)", $ids);
                Database::run("DELETE FROM product_variants WHERE product_id IN ($in)", $ids);
                Database::run("DELETE FROM product_options WHERE product_id IN ($in)", $ids);
                Database::run("UPDATE order_items SET product_id = NULL WHERE product_id IN ($in)", $ids);
                Database::run("DELETE FROM products WHERE id IN ($in)", $ids);
            }
            flash('success', count($ids) . ' product(s) updated.');
        }
        redirect('products.php');
    }
}

/* ------------------------------------------------------------------- data -- */

$categories = Database::all('SELECT id, name FROM categories ORDER BY name');
$editing    = null;
$gallery    = [];
$optionGroups = [];
$variants     = [];

if ($action === 'edit' && !$editId) {
    $action = 'new';
}

if (in_array($action, ['edit', 'new'], true)) {
    if ($editId) {
        $editing = Database::row('SELECT * FROM products WHERE id = ?', [$editId]);
        if (!$editing) {
            flash('danger', 'That product no longer exists.');
            redirect('products.php');
        }
        $gallery      = product_gallery($editId);
        $optionGroups = product_options($editId);
        $variants     = product_variants($editId);
    }

    $pageTitle = $editing ? 'Edit: ' . $editing['name'] : 'New product';
    $specs = $editing ? (json_decode((string)$editing['specs'], true) ?: []) : [];
    include __DIR__ . '/includes/header.php';
    ?>
    <form method="post" enctype="multipart/form-data" class="admin-form">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="save">
        <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

        <?php foreach ($errors as $err): ?>
            <div class="flash flash-danger"><span><?= e($err) ?></span></div>
        <?php endforeach; ?>

        <div class="admin-cols admin-cols-form">
            <div class="admin-stack">
                <section class="panel">
                    <h2>Product details</h2>
                    <div class="form-grid">
                        <label class="field field-wide"><span>Name *</span>
                            <input type="text" name="name" value="<?= e((string)($editing['name'] ?? '')) ?>" required>
                        </label>
                        <label class="field"><span>Slug (optional)</span>
                            <input type="text" name="slug" value="<?= e((string)($editing['slug'] ?? '')) ?>" placeholder="auto-generated from name">
                        </label>
                        <label class="field"><span>SKU</span>
                            <input type="text" name="sku" value="<?= e((string)($editing['sku'] ?? '')) ?>">
                        </label>
                        <label class="field"><span>Category</span>
                            <select name="category_id">
                                <option value="">— none —</option>
                                <?php foreach ($categories as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>" <?= (int)($editing['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                                        <?= e($c['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="field"><span>Brand</span>
                            <input type="text" name="brand" value="<?= e((string)($editing['brand'] ?? '')) ?>" list="brand-options">
                            <datalist id="brand-options">
                                <?php foreach (brands_list() as $b): ?><option value="<?= e($b) ?>"><?php endforeach; ?>
                            </datalist>
                        </label>
                        <label class="field field-wide"><span>Short description</span>
                            <input type="text" name="short_desc" maxlength="220" value="<?= e((string)($editing['short_desc'] ?? '')) ?>" placeholder="One line shown on cards">
                        </label>
                    </div>

                    <div class="field field-wide">
                        <span>Full description</span>

                        <!-- WYSIWYG editor: the toolbar writes into the editable area,
                             which is mirrored into the hidden textarea that posts. -->
                        <div class="wys" data-wysiwyg>
                            <div class="wys-toolbar" role="toolbar" aria-label="Formatting">
                                <button type="button" data-wys-cmd="bold" title="Bold"><b>B</b></button>
                                <button type="button" data-wys-cmd="italic" title="Italic"><i>I</i></button>
                                <button type="button" data-wys-cmd="underline" title="Underline"><u>U</u></button>
                                <span class="wys-sep"></span>
                                <button type="button" data-wys-block="p" title="Paragraph">¶</button>
                                <button type="button" data-wys-block="h3" title="Heading">H3</button>
                                <button type="button" data-wys-block="h4" title="Sub-heading">H4</button>
                                <span class="wys-sep"></span>
                                <button type="button" data-wys-cmd="insertUnorderedList" title="Bullet list">• List</button>
                                <button type="button" data-wys-cmd="insertOrderedList" title="Numbered list">1. List</button>
                                <button type="button" data-wys-quote="blockquote" title="Quote">❝</button>
                                <span class="wys-sep"></span>
                                <button type="button" data-wys-link title="Insert link">Link</button>
                                <button type="button" data-wys-cmd="removeFormat" title="Clear formatting">Clear</button>
                                <button type="button" data-wys-cmd="undo" title="Undo">↶</button>
                                <span class="wys-spacer"></span>
                                <button type="button" data-wys-toggle title="Edit raw HTML">HTML</button>
                            </div>
                            <div class="wys-area" contenteditable="true" data-wys-area
                                 aria-label="Product description"><?= rich_text((string)($editing['description'] ?? '')) ?></div>
                            <textarea class="wys-source" name="description" data-wys-input hidden><?= e((string)($editing['description'] ?? '')) ?></textarea>
                        </div>
                        <p class="muted small">Allowed when published: paragraphs, headings, lists, quotes, links, bold/italic/underline.</p>
                    </div>
                </section>

                <section class="panel">
                    <h2>Options &amp; variants</h2>
                    <p class="muted small">
                        Add a group per choice — e.g. <em>Size</em> with <code>S, M, L</code> and <em>Colour</em> with
                        <code>Black, Camel</code>. Every combination below gets its own stock; the product's stock
                        becomes the total. Leave the groups empty for a simple product.
                    </p>

                    <div data-variant-editor
                         data-existing="<?= e(json_encode(array_map(static fn($v) => [
                             'options' => $v['options'],
                             'sku' => $v['sku'],
                             'price' => $v['price'] !== null ? number_format((float)$v['price'], 2, '.', '') : '',
                             'stock' => (int)$v['stock'],
                             'active' => (int)$v['is_active'],
                         ], $variants), JSON_UNESCAPED_UNICODE)) ?>">

                        <div data-option-rows>
                            <?php foreach ($optionGroups ?: [['name' => '', 'values' => []]] as $group): ?>
                                <div class="option-row" data-option-row>
                                    <input type="text" name="option_name[]" value="<?= e((string)$group['name']) ?>" placeholder="Option name (Size, Colour…)">
                                    <input type="text" name="option_values[]" value="<?= e(implode(', ', (array)$group['values'])) ?>" placeholder="Values, comma separated">
                                    <button type="button" class="icon-btn" data-remove-row aria-label="Remove option group">×</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn btn-ghost btn-sm" data-add-option><?= admin_icon('plus') ?> Add option group</button>

                        <div class="table-scroll" data-variant-wrap hidden>
                            <table class="admin-table compact" data-variant-table>
                                <thead>
                                <tr>
                                    <th>Combination</th>
                                    <th>SKU</th>
                                    <th class="num">Price (<?= e(store_currency()) ?>)</th>
                                    <th class="num">Stock</th>
                                    <th>Active</th>
                                </tr>
                                </thead>
                                <tbody data-variant-body></tbody>
                            </table>
                            <p class="muted small" data-variant-summary></p>
                        </div>
                        <p class="muted small" data-variant-empty>No variants yet — this is a simple product.</p>
                    </div>
                </section>

                <section class="panel">
                    <h2>Specifications</h2>
                    <p class="muted small">Key/value pairs shown in the Specifications tab.</p>
                    <div data-spec-rows>
                        <?php foreach ($specs as $key => $value): ?>
                            <div class="spec-row">
                                <input type="text" name="spec_key[]" value="<?= e((string)$key) ?>" placeholder="e.g. Battery life">
                                <input type="text" name="spec_value[]" value="<?= e((string)$value) ?>" placeholder="e.g. 30 hours">
                                <button type="button" class="icon-btn" data-remove-row aria-label="Remove row">×</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" data-add-spec><?= admin_icon('plus') ?> Add specification</button>
                </section>
            </div>

            <div class="admin-stack">
                <section class="panel">
                    <h2>Pricing &amp; stock</h2>
                    <div class="form-grid">
                        <label class="field"><span>Price (<?= e(store_currency()) ?>) *</span>
                            <input type="number" step="0.01" min="0" name="price" value="<?= e((string)($editing['price'] ?? '')) ?>" required>
                        </label>
                        <label class="field"><span>Sale price</span>
                            <input type="number" step="0.01" min="0" name="sale_price" value="<?= e((string)($editing['sale_price'] ?? '')) ?>">
                        </label>
                        <label class="field"><span>Stock quantity</span>
                            <input type="number" min="0" name="stock" value="<?= e((string)($editing['stock'] ?? '0')) ?>" <?= $variants ? 'readonly' : '' ?>>
                        </label>
                    </div>
                    <?php if ($variants): ?>
                        <p class="muted small">Stock is managed per variant — the parent total follows automatically.</p>
                    <?php endif; ?>
                    <label class="switch-row">
                        <input type="checkbox" name="is_active" value="1" <?= (int)($editing['is_active'] ?? 1) ? 'checked' : '' ?>>
                        <span>Visible in store</span>
                    </label>
                    <label class="switch-row">
                        <input type="checkbox" name="is_featured" value="1" <?= (int)($editing['is_featured'] ?? 0) ? 'checked' : '' ?>>
                        <span>Feature on homepage</span>
                    </label>
                </section>

                <section class="panel">
                    <h2>Images</h2>
                    <label class="field"><span>Main image</span>
                        <input type="file" name="image" accept="image/*">
                    </label>
                    <?php if (!empty($editing['image'])): ?>
                        <img class="admin-thumb-lg" src="<?= e(product_image($editing['image'], (string)$editing['name'])) ?>" alt="">
                    <?php endif; ?>

                    <label class="field"><span>Add gallery images</span>
                        <input type="file" name="gallery[]" accept="image/*" multiple>
                    </label>

                    <?php if ($gallery): ?>
                        <p class="muted small">Drag to reorder — the first image leads the product page gallery.</p>
                        <ul class="sortable-gallery" data-sortable data-product-id="<?= (int)$editing['id'] ?>">
                            <?php foreach ($gallery as $img): ?>
                                <li class="sortable-item" draggable="true" data-image-id="<?= (int)$img['id'] ?>">
                                    <span class="drag-handle" aria-hidden="true"><?= admin_icon('drag') ?></span>
                                    <img src="<?= e(product_image($img['image_path'], (string)$editing['name'])) ?>" alt="">
                                    <button type="button" class="icon-btn danger" data-delete-image="<?= (int)$img['id'] ?>"
                                            data-product="<?= (int)$editing['id'] ?>" aria-label="Delete image">×</button>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <p class="muted small">JPG, PNG, WEBP or GIF up to 4 MB. Leave empty to keep the generated placeholder.</p>
                </section>

                <div class="admin-actions">
                    <button class="btn btn-gold btn-lg" type="submit"><?= $editing ? 'Save changes' : 'Create product' ?></button>
                    <a class="btn btn-ghost" href="<?= e(base_url('products.php')) ?>">Cancel</a>
                </div>
            </div>
        </div>
    </form>

    <!-- Image deletion is its own form so it can be triggered from the gallery. -->
    <form method="post" id="image-delete-form" hidden>
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="delete_image">
        <input type="hidden" name="image_id" value="">
        <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
    </form>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

/* -------------------------------------------------------------------- list -- */

$search   = (string)query('q');
$catId    = (int)query('category_id');
$stockFil = (string)query('stock', '');
$page     = max(1, (int)query('page', 1));
$perPage  = 20;

$where  = ['1=1'];
$params = [];
if ($search !== '') {
    $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.brand LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
if ($catId) {
    $where[] = 'p.category_id = ?';
    $params[] = $catId;
}
if ($stockFil === 'low') {
    $where[] = 'p.stock <= 5';
}
if ($stockFil === 'out') {
    $where[] = 'p.stock = 0';
}

$whereSql = implode(' AND ', $where);
$total = (int)Database::value("SELECT COUNT(*) FROM products p WHERE $whereSql", $params);
$pager = paginate($total, $perPage, $page, http_build_query(array_filter(['q' => $search, 'category_id' => $catId ?: null, 'stock' => $stockFil])));

$products = Database::all(
    "SELECT p.*, c.name AS category_name,
            (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1) AS variant_count
     FROM products p LEFT JOIN categories c ON c.id = p.category_id
     WHERE $whereSql ORDER BY p.id DESC LIMIT $perPage OFFSET {$pager['offset']}",
    $params
);

$pageTitle = 'Products';
$pageSubtitle = $total . ' in the catalogue';
$topbarActions = '<a class="btn btn-outline btn-sm" href="' . e(base_url('import-export.php')) . '">' . admin_icon('upload') . ' Import / export</a>'
    . '<a class="btn btn-gold btn-sm" href="' . e(base_url('products.php?action=new')) . '">' . admin_icon('plus') . ' New product</a>';
include __DIR__ . '/includes/header.php';
?>

<form method="get" class="admin-toolbar">
    <input type="search" name="q" placeholder="Search name, SKU or brand…" value="<?= e($search) ?>">
    <select name="category_id">
        <option value="">All categories</option>
        <?php foreach ($categories as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $catId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="stock">
        <option value="">Any stock</option>
        <option value="low" <?= $stockFil === 'low' ? 'selected' : '' ?>>Low (≤5)</option>
        <option value="out" <?= $stockFil === 'out' ? 'selected' : '' ?>>Out of stock</option>
    </select>
    <button class="btn btn-outline btn-sm" type="submit">Filter</button>
    <a class="btn btn-ghost btn-sm" href="<?= e(base_url('products.php')) ?>">Reset</a>
    <span class="admin-toolbar-group">
        <select name="bulk_action" form="bulk-form">
            <option value="">Bulk action…</option>
            <option value="activate">Set visible</option>
            <option value="deactivate">Set hidden</option>
            <option value="delete">Delete</option>
        </select>
        <button class="btn btn-outline btn-sm" type="submit" form="bulk-form" data-confirm="Apply this bulk action?">Apply to selected</button>
    </span>
</form>

<form method="post" id="bulk-form">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="bulk">
</form>

<section class="panel">
    <div class="panel-head panel-head-flush">
        <h2><?= count($products) ?> product(s)</h2>
        <input type="search" class="table-filter" placeholder="Filter this list…" data-table-filter="#products-table">
    </div>
    <table class="table table-admin" id="products-table">
        <thead>
        <tr>
            <th class="check-col"><input type="checkbox" data-check-all></th>
            <th>Product</th>
            <th>Category</th>
            <th class="num">Price</th>
            <th class="num">Stock</th>
            <th>Status</th>
            <th class="actions-col">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($products as $p): ?>
            <tr>
                <td><input type="checkbox" name="ids[]" value="<?= (int)$p['id'] ?>" form="bulk-form"></td>
                <td>
                    <div class="cell-product">
                        <img src="<?= e(product_image($p['image'], $p['name'])) ?>" alt="">
                        <div>
                            <a href="<?= e(base_url('products.php?action=edit&id=' . (int)$p['id'])) ?>"><?= e($p['name']) ?></a>
                            <span class="muted small">
                                <?= e($p['sku'] ?: 'no SKU') ?><?= $p['brand'] ? ' · ' . e($p['brand']) : '' ?>
                                <?php if ((int)$p['variant_count']): ?>
                                    · <em class="badge badge-info"><?= (int)$p['variant_count'] ?> variants</em>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </td>
                <td><?= e($p['category_name'] ?? '—') ?></td>
                <td class="num">
                    <?php if ((float)$p['sale_price'] > 0 && (float)$p['sale_price'] < (float)$p['price']): ?>
                        <span class="price-now"><?= e(money(product_price($p))) ?></span>
                        <s class="muted small"><?= e(money((float)$p['price'])) ?></s>
                    <?php else: ?>
                        <?= e(money((float)$p['price'])) ?>
                    <?php endif; ?>
                </td>
                <td class="num <?= (int)$p['stock'] === 0 ? 'text-danger' : ((int)$p['stock'] <= 5 ? 'text-warn' : '') ?>"><?= (int)$p['stock'] ?></td>
                <td><?= (int)$p['is_active'] ? '<span class="badge badge-ok">Visible</span>' : '<span class="badge badge-muted">Hidden</span>' ?></td>
                <td class="actions-col">
                    <button class="icon-btn" type="button" data-toggle-product="<?= (int)$p['id'] ?>" title="Toggle visibility">
                        <?= icon((int)$p['is_active'] ? 'eye' : 'eye-off') ?>
                    </button>
                    <a class="icon-btn" href="<?= e(base_url('products.php?action=edit&id=' . (int)$p['id'])) ?>" title="Edit"><?= icon('edit') ?></a>
                    <form method="post" class="inline-form" data-confirm-form="Delete this product permanently?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="do" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                        <button class="icon-btn danger" type="submit" title="Delete"><?= icon('trash') ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$products): ?>
            <tr><td colspan="7" class="muted">No products match those filters.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <?= pagination_links($pager) ?>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
