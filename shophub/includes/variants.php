<?php
/**
 * ShopHub — product variants (size / colour / material …)
 *
 * A product may define option groups (product_options) whose values combine
 * into variants (product_variants), each with its own SKU, price, image and
 * stock. Products without variants keep using products.stock (the parent
 * column mirrors the sum of active variant stock for listings and search).
 *
 * Option values are stored as a JSON map so a label can be rebuilt anywhere:
 *   {"Size":"M","Colour":"Black"}  →  "M / Black"
 */
declare(strict_types=1);

/* ------------------------------------------------------------------ option groups -- */

/**
 * Option groups for a product.
 *
 * @return array<int, array{id:int, product_id:int, name:string, values:string[], sort_order:int}>
 */
function product_options(int $productId): array
{
    $rows = Database::all(
        'SELECT * FROM product_options WHERE product_id = ? ORDER BY sort_order, id',
        [$productId]
    );

    foreach ($rows as &$row) {
        $values = json_decode((string)$row['values_json'], true);
        if (!is_array($values) || !$values) {
            // Fall back to whatever the variants actually use.
            $values = [];
            foreach (product_variants($productId) as $variant) {
                $value = $variant['options'][$row['name']] ?? null;
                if ($value !== null && !in_array($value, $values, true)) {
                    $values[] = (string)$value;
                }
            }
        }
        $row['id']         = (int)$row['id'];
        $row['product_id'] = (int)$row['product_id'];
        $row['sort_order'] = (int)$row['sort_order'];
        $row['values']     = array_values(array_filter(
            array_map(static fn($v) => trim((string)$v), $values),
            static fn($v) => $v !== ''
        ));
    }
    return $rows;
}

/** Option groups keyed by name — handy for edit forms. */
function product_options_map(int $productId): array
{
    $map = [];
    foreach (product_options($productId) as $option) {
        $map[$option['name']] = $option['values'];
    }
    return $map;
}

function product_option_save(int $productId, string $name, array $values, int $sortOrder = 0): int
{
    $name = trim($name);
    if ($name === '') {
        return 0;
    }

    $values = variant_clean_values($values);

    $existing = Database::row(
        'SELECT * FROM product_options WHERE product_id = ? AND name = ?',
        [$productId, $name]
    );
    $payload = [
        'product_id'  => $productId,
        'name'        => $name,
        'values_json' => json_encode($values, JSON_UNESCAPED_UNICODE),
        'sort_order'  => $sortOrder,
    ];

    if ($existing) {
        Database::update('product_options', $payload, 'id = :wid', ['wid' => (int)$existing['id']]);
        return (int)$existing['id'];
    }
    return (int)Database::insert('product_options', $payload);
}

/**
 * Save every option group for a product at once.
 *
 * Accepts either ['Size' => ['S','M'], 'Colour' => ['Black']] or
 * [['name' => 'Size', 'values' => ['S','M']], …].
 * Groups that are no longer present — and any keys the caller did not
 * mention — are deleted, so the picker always mirrors the real variants.
 */
function product_options_save(int $productId, array $groups): void
{
    $kept = [];
    foreach (variant_normalise_groups($groups) as $order => $group) {
        if ($group['name'] === '' || !$group['values']) {
            continue;
        }
        product_option_save($productId, $group['name'], $group['values'], $order);
        $kept[] = $group['name'];
    }
    product_options_delete_unused($productId, $kept);
}

function product_options_delete_unused(int $productId, array $keepNames): void
{
    foreach (product_options($productId) as $option) {
        if (!in_array($option['name'], $keepNames, true)) {
            Database::run('DELETE FROM product_options WHERE id = ?', [(int)$option['id']]);
        }
    }
}

function variant_clean_values(array $values): array
{
    $clean = [];
    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value !== '' && !in_array($value, $clean, true)) {
            $clean[] = $value;
        }
    }
    return $clean;
}

/* ------------------------------------------------------------------- variants -- */

/**
 * @return array<int, array{id:int, product_id:int, options:array<string,string>, label:string,
 *                          sku:string, price:float|null, stock:int, image:string, is_active:int}>
 */
function product_variants(int $productId, bool $onlyActive = false): array
{
    $sql = 'SELECT * FROM product_variants WHERE product_id = ?';
    if ($onlyActive) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY id';

    $rows = Database::all($sql, [$productId]);
    foreach ($rows as &$row) {
        $row = variant_normalise($row);
    }
    return $rows;
}

function variant_normalise(array $row): array
{
    $options = json_decode((string)($row['options_json'] ?? ''), true);
    if (!is_array($options)) {
        $options = [];
    }

    $row['id']         = (int)$row['id'];
    $row['product_id'] = (int)$row['product_id'];
    $row['stock']      = (int)$row['stock'];
    $row['is_active']  = (int)$row['is_active'];
    $row['price']      = ($row['price'] === null || $row['price'] === '') ? null : round((float)$row['price'], 2);
    $row['options']    = $options;
    $row['label']      = variant_label($options);
    $row['sku']        = (string)($row['sku'] ?? '');
    $row['image']      = (string)($row['image'] ?? '');
    return $row;
}

function find_variant(int $variantId): ?array
{
    $row = Database::row('SELECT * FROM product_variants WHERE id = ?', [$variantId]);
    return $row ? variant_normalise($row) : null;
}

function find_variant_by_options(int $productId, array $options): ?array
{
    $needle = variant_clean_options($options);
    foreach (product_variants($productId) as $variant) {
        if (variant_clean_options($variant['options']) == $needle) {
            return $variant;
        }
    }
    return null;
}

function variant_clean_options(array $options): array
{
    $clean = [];
    foreach ($options as $name => $value) {
        $name  = trim((string)$name);
        $value = trim((string)$value);
        if ($name !== '' && $value !== '') {
            $clean[$name] = $value;
        }
    }
    return $clean;
}

function product_has_variants(int $productId): bool
{
    return (int)Database::value(
        'SELECT COUNT(*) FROM product_variants WHERE product_id = ? AND is_active = 1',
        [$productId]
    ) > 0;
}

/** "M / Black" — used in cart lines, order items and the staff tables. */
function variant_label(array $options): string
{
    $values = array_values(variant_clean_options($options));
    return implode(' / ', $values);
}

/** "Size: M | Colour: Black" — used where the option names matter. */
function variant_label_full(array $options): string
{
    $parts = [];
    foreach (variant_clean_options($options) as $name => $value) {
        $parts[] = $name . ': ' . $value;
    }
    return implode(' | ', $parts);
}

/** Effective price of a variant: its own price, else the parent price. */
function variant_price(array $variant, array $product): float
{
    if (isset($variant['price']) && $variant['price'] !== null && (float)$variant['price'] > 0) {
        return round((float)$variant['price'], 2);
    }
    return product_price($product);
}

function variant_stock(int $variantId): int
{
    return (int)Database::value('SELECT stock FROM product_variants WHERE id = ?', [$variantId]);
}

function variants_stock_total(array $product): int
{
    return (int)Database::value(
        'SELECT COALESCE(SUM(stock), 0) FROM product_variants WHERE product_id = ? AND is_active = 1',
        [(int)$product['id']]
    );
}

/** Cheapest sellable price — powers "from $x" on cards and search results. */
function product_from_price(array $product): float
{
    $variants = product_variants((int)$product['id'], true);
    if (!$variants) {
        return product_price($product);
    }
    $prices = array_map(static fn($v) => variant_price($v, $product), $variants);
    return round(min($prices), 2);
}

/* ------------------------------------------------------- combinations & saving -- */

/**
 * Normalise option groups into a list of ['name' => string, 'values' => string[]].
 * Accepts a map (name => values) or a list of definitions.
 */
function variant_normalise_groups(array $groups): array
{
    $out = [];
    foreach ($groups as $key => $group) {
        if (is_array($group) && isset($group['name'])) {
            $out[] = [
                'name'   => trim((string)$group['name']),
                'values' => variant_clean_values((array)($group['values'] ?? [])),
            ];
        } else {
            $out[] = [
                'name'   => trim((string)$key),
                'values' => variant_clean_values((array)$group),
            ];
        }
    }
    return $out;
}

/**
 * Every combination of the given option groups.
 * [['name'=>'Size','values'=>['S','M']], ['name'=>'Colour','values'=>['Black','Tan']]]
 *   → [['Size'=>'S','Colour'=>'Black'], ['Size'=>'S','Colour'=>'Tan'], …]
 */
function variant_combinations(array $groups): array
{
    $combos = [[]];
    foreach (variant_normalise_groups($groups) as $group) {
        if ($group['name'] === '' || !$group['values']) {
            continue;
        }
        $next = [];
        foreach ($combos as $combo) {
            foreach ($group['values'] as $value) {
                $next[] = $combo + [$group['name'] => $value];
            }
        }
        $combos = $next;
    }
    return $combos;
}

/**
 * Replace a product's variants with $rows.
 *
 * $groups: [['name'=>'Size','values'=>['S','M']], …] (or the map form)
 * $rows:   [['options' => ['Size'=>'M','Colour'=>'Black'], 'sku' => 'X', 'stock' => 5,
 *            'price' => null|float, 'price_delta' => float, 'image' => '', 'is_active' => 1], …]
 *
 * Combinations that are no longer listed are deleted; combinations that still
 * exist are updated in place so ids — and the order_items rows that reference
 * them — survive an edit.
 */
function save_variants(int $productId, array $groups, array $rows): array
{
    $groups  = variant_normalise_groups($groups);
    $order   = array_column($groups, 'name');
    $parent  = Database::row('SELECT * FROM products WHERE id = ?', [$productId]);

    // Index the existing variants by their canonical label.
    $existing = [];
    foreach (Database::all('SELECT * FROM product_variants WHERE product_id = ?', [$productId]) as $row) {
        $existing[variant_label(json_decode((string)$row['options_json'], true) ?: [])] = $row;
    }

    $kept    = [];
    $created = 0;
    $updated = 0;

    // Two rows describing the same combination would fight over one grid slot —
    // the last one in the file/form wins.
    $byKey = [];
    foreach ($rows as $row) {
        $clean = variant_clean_options((array)($row['options'] ?? []));
        if (!$clean) {
            continue;
        }
        ksort($clean);   // order-insensitive: {"Colour":"Black","Size":"M"} == {"Size":"M","Colour":"Black"}
        $byKey[json_encode($clean, JSON_UNESCAPED_UNICODE)] = $row;
    }
    $rows = array_values($byKey);

    foreach ($rows as $row) {
        $options = variant_clean_options((array)($row['options'] ?? []));
        if (!$options) {
            continue;
        }

        // Keep the option order stable so labels never churn between saves.
        $ordered = [];
        foreach ($order as $name) {
            if (isset($options[$name])) {
                $ordered[$name] = $options[$name];
            }
        }
        foreach ($options as $name => $value) {
            $ordered[$name] ??= $value;
        }

        $price = $row['price'] ?? null;
        if (($price === null || $price === '') && isset($row['price_delta']) && $parent) {
            $delta = (float)$row['price_delta'];
            $price = $delta !== 0.0 ? round(product_price($parent) + $delta, 2) : null;
        }
        $price = ($price === null || $price === '') ? null : max(0, round((float)$price, 2));

        $label  = variant_label($ordered);
        $values = [
            'product_id'   => $productId,
            'options_json' => json_encode($ordered, JSON_UNESCAPED_UNICODE),
            'sku'          => trim((string)($row['sku'] ?? '')),
            'price'        => $price,
            'stock'        => max(0, (int)($row['stock'] ?? 0)),
            'image'        => (string)($row['image'] ?? ''),
            'is_active'    => (int)($row['is_active'] ?? 1),
        ];

        if (isset($existing[$label])) {
            Database::update('product_variants', $values, 'id = :wid', ['wid' => (int)$existing[$label]['id']]);
            $updated++;
        } else {
            $values['created_at'] = now();
            Database::insert('product_variants', $values);
            $created++;
        }
        $kept[] = $label;
    }

    foreach ($existing as $label => $row) {
        if (!in_array($label, $kept, true)) {
            Database::run('DELETE FROM product_variants WHERE id = ?', [(int)$row['id']]);
        }
    }

    product_options_save($productId, $groups);
    sync_product_stock($productId);

    return ['created' => $created, 'updated' => $updated, 'removed' => count($existing) - ($updated), 'count' => count($kept)];
}

/** Parent stock mirrors the sum of active variant stock. */
function sync_product_stock(int $productId): int
{
    $has = (int)Database::value('SELECT COUNT(*) FROM product_variants WHERE product_id = ?', [$productId]);
    if (!$has) {
        return (int)Database::value('SELECT stock FROM products WHERE id = ?', [$productId]);
    }
    $total = variants_stock_total(['id' => $productId]);
    Database::run('UPDATE products SET stock = ?, updated_at = ? WHERE id = ?', [$total, now(), $productId]);
    return $total;
}

/**
 * Stock left for a cart line — the variant ledger when the line is a variant,
 * otherwise the parent product column.
 */
function cart_line_stock(array $item): int
{
    return $item['variant'] ? (int)$item['variant']['stock'] : (int)$item['product']['stock'];
}

/* ------------------------------------------------------------ cart plumbing -- */

/** Split a cart key back into ['type' => 'product|variant', 'id' => int]. */
function cart_key_parse(string $key): array
{
    if (preg_match('/^([pv])(\d+)$/', $key, $m)) {
        return ['type' => $m[1] === 'v' ? 'variant' : 'product', 'id' => (int)$m[2]];
    }
    return ['type' => 'product', 'id' => 0];
}
