<?php
/**
 * ShopHub — catalog with filtering, sorting and pagination
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/partials.php';

$q        = (string)query('q');
$catSlug  = (string)query('category');
$brand    = (string)query('brand');
$min      = query('min') !== '' ? (float)query('min') : null;
$max      = query('max') !== '' ? (float)query('max') : null;
$sort     = (string)query('sort', 'featured');
$onSale   = (bool)query('on_sale');
$inStock  = (bool)query('in_stock');
$page     = max(1, (int)query('page', 1));

$perPage   = (int)setting('items_per_page', 12);
$categories = categories_list();
$brands     = brands_list();

$currentCategory = null;
foreach ($categories as $c) {
    if ($c['slug'] === $catSlug) {
        $currentCategory = $c;
    }
}

$result = search_products([
    'q'        => $q,
    'category' => $catSlug,
    'brand'    => $brand,
    'min'      => $min,
    'max'      => $max,
    'on_sale'  => $onSale,
    'in_stock' => $inStock,
    'sort'     => $sort,
    'limit'    => $perPage,
    'offset'   => ($page - 1) * $perPage,
]);

$pager = paginate($result['total'], $perPage, $page, http_build_query(array_filter([
    'q' => $q, 'category' => $catSlug, 'brand' => $brand,
    'min' => $min, 'max' => $max, 'sort' => $sort,
    'on_sale' => $onSale ? 1 : null, 'in_stock' => $inStock ? 1 : null,
], static fn($v) => $v !== null && $v !== '')));

$pageTitle = $currentCategory ? $currentCategory['name'] : ($q ? 'Search: ' . $q : 'Shop all products');
$wishlistIds = wishlist_ids();

$sortOptions = [
    'featured'   => 'Featured',
    'newest'     => 'Newest',
    'price_asc'  => 'Price: low to high',
    'price_desc' => 'Price: high to low',
    'rating'     => 'Top rated',
    'name'       => 'A → Z',
];

include __DIR__ . '/includes/header.php';
?>

<section class="page-head">
    <div class="container">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= e(base_url('index.php')) ?>">Home</a>
            <span>/</span>
            <?php if ($currentCategory): ?>
                <a href="<?= e(base_url('shop.php')) ?>">Shop</a><span>/</span><em><?= e($currentCategory['name']) ?></em>
            <?php else: ?>
                <em><?= $q ? 'Search results' : 'Shop all' ?></em>
            <?php endif; ?>
        </nav>
        <h1><?= e($pageTitle) ?></h1>
        <p class="muted">
            <?php if ($currentCategory && $currentCategory['description']): ?>
                <?= e($currentCategory['description']) ?>
            <?php elseif ($q): ?>
                <?= (int)$result['total'] ?> result<?= $result['total'] === 1 ? '' : 's' ?> for “<?= e($q) ?>”
            <?php else: ?>
                <?= (int)$result['total'] ?> products available · free delivery over <?= e(money((float)setting('free_shipping_over', 150))) ?>
            <?php endif; ?>
        </p>
    </div>
</section>

<div class="container shop-layout">
    <aside class="filters" data-filters>
        <button class="filters-toggle" type="button" data-filters-toggle>Filters <span>▾</span></button>

        <form class="filters-body" method="get" action="<?= e(base_url('shop.php')) ?>">
            <?php if ($q): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
            <input type="hidden" name="sort" value="<?= e($sort) ?>">

            <div class="filter-block">
                <h3>Category</h3>
                <label class="check">
                    <input type="radio" name="category" value="" <?= $catSlug === '' ? 'checked' : '' ?>>
                    <span>All categories</span>
                </label>
                <?php foreach ($categories as $c): ?>
                    <label class="check">
                        <input type="radio" name="category" value="<?= e($c['slug']) ?>" <?= $catSlug === $c['slug'] ? 'checked' : '' ?>>
                        <span><?= e($c['name']) ?></span>
                        <em><?= (int)$c['product_count'] ?></em>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="filter-block">
                <h3>Price (<?= e(store_currency()) ?>)</h3>
                <div class="price-inputs">
                    <input type="number" name="min" min="0" step="1" placeholder="Min" value="<?= $min !== null ? e((string)$min) : '' ?>">
                    <span>—</span>
                    <input type="number" name="max" min="0" step="1" placeholder="Max" value="<?= $max !== null ? e((string)$max) : '' ?>">
                </div>
            </div>

            <div class="filter-block">
                <h3>Brand</h3>
                <div class="brand-list">
                    <?php foreach (array_slice($brands, 0, 10) as $b): ?>
                        <label class="check">
                            <input type="radio" name="brand" value="<?= e($b) ?>" <?= $brand === $b ? 'checked' : '' ?>>
                            <span><?= e($b) ?></span>
                        </label>
                    <?php endforeach; ?>
                    <?php if ($brand): ?>
                        <a class="link-more" href="<?= e(base_url('shop.php?' . http_build_query(array_filter(['q' => $q, 'category' => $catSlug, 'sort' => $sort === 'featured' ? '' : $sort], static fn($v) => $v !== '' && $v !== null)))) ?>">Clear brand</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="filter-block">
                <h3>Availability</h3>
                <label class="check">
                    <input type="checkbox" name="in_stock" value="1" <?= $inStock ? 'checked' : '' ?>>
                    <span>In stock only</span>
                </label>
                <label class="check">
                    <input type="checkbox" name="on_sale" value="1" <?= $onSale ? 'checked' : '' ?>>
                    <span>On sale</span>
                </label>
            </div>

            <div class="filter-actions">
                <button class="btn btn-gold btn-block" type="submit">Apply filters</button>
                <a class="btn btn-ghost btn-block" href="<?= e(base_url('shop.php')) ?>">Reset</a>
            </div>
        </form>
    </aside>

    <section class="shop-main">
        <div class="shop-toolbar">
            <span class="result-count"><strong><?= (int)$result['total'] ?></strong> items</span>
            <div class="active-chips">
                <?php if ($q): ?><a class="chip" href="<?= e(base_url('shop.php?' . http_build_query(array_filter(['category' => $catSlug, 'sort' => $sort === 'featured' ? '' : $sort], static fn($v) => $v !== '' && $v !== null)))) ?>">“<?= e($q) ?>” ×</a><?php endif; ?>
                <?php if ($brand): ?><a class="chip" href="<?= e(base_url('shop.php?' . http_build_query(array_filter(['q' => $q, 'category' => $catSlug, 'sort' => $sort === 'featured' ? '' : $sort], static fn($v) => $v !== '' && $v !== null)))) ?>"><?= e($brand) ?> ×</a><?php endif; ?>
                <?php if ($onSale): ?><a class="chip" href="<?= e(base_url('shop.php?' . http_build_query(array_filter(['q' => $q, 'category' => $catSlug], static fn($v) => $v !== '' && $v !== null)))) ?>">On sale ×</a><?php endif; ?>
            </div>
            <form class="sort-form" method="get" action="<?= e(base_url('shop.php')) ?>">
                <?php foreach (['q', 'category', 'brand', 'min', 'max', 'on_sale', 'in_stock'] as $keep): ?>
                    <?php if (query($keep) !== '' && query($keep) !== null): ?>
                        <input type="hidden" name="<?= e($keep) ?>" value="<?= e((string)query($keep)) ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <label for="sort">Sort</label>
                <select name="sort" id="sort" data-auto-submit>
                    <?php foreach ($sortOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $sort === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <?php if (!$result['rows']): ?>
            <?= empty_state('No products matched', 'Try widening your filters or searching for something else.', 'Browse all products', base_url('shop.php')) ?>
        <?php else: ?>
            <div class="product-grid">
                <?php foreach ($result['rows'] as $p): ?>
                    <?= product_card($p, $wishlistIds) ?>
                <?php endforeach; ?>
            </div>
            <?= pagination_links($pager) ?>
        <?php endif; ?>
    </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
