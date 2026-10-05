<?php
/**
 * ShopHub staff portal — CSV import helpers
 *
 * Parsing is deliberately split from applying:
 *
 *   csv_parse_product_file() → a plan (nothing written)
 *   csv_apply_product_import() → runs the plan, returns a report
 *
 * so the operator always sees a preview before the catalogue changes.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** Human-friendly header lookup: "Sale Price", "sale_price" and "SALE-PRICE" all match. */
function csv_key(string $header): string
{
    return strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($header)) ?? '');
}

/**
 * Read a CSV into associative rows.
 *
 * @return array{0: string[], 1: array<int, array<string,string>>} [headers, rows]
 */
function csv_rows(string $path): array
{
    $handle = fopen($path, 'r');
    if (!$handle) {
        return [[], []];
    }

    $headers = [];
    $rows    = [];
    $first   = true;

    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        if ($first) {
            // Excel loves to prepend a UTF-8 BOM.
            if (isset($row[0])) {
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$row[0]);
            }
            $headers = array_map(static fn($h) => csv_key((string)$h), $row);
            $first   = false;
            continue;
        }
        if ($row === [null] || (count($row) === 1 && trim((string)$row[0]) === '')) {
            continue;
        }
        $assoc = [];
        foreach ($headers as $i => $key) {
            if ($key === '') {
                continue;
            }
            $assoc[$key] = trim((string)($row[$i] ?? ''));
        }
        $rows[] = $assoc;
    }

    fclose($handle);
    return [$headers, $rows];
}

function csv_bool(string $value, bool $default = false): bool
{
    $value = strtolower(trim($value));
    if ($value === '') {
        return $default;
    }
    return in_array($value, ['1', 'true', 'yes', 'y', 'on'], true);
}

function csv_number(string $value): ?float
{
    $clean = preg_replace('/[^0-9.\-]/', '', trim($value)) ?? '';
    return ($clean === '' || $clean === '-') ? null : (float)$clean;
}

/**
 * Parse an uploaded product CSV into a plan.
 *
 * @return array{ok: bool, message: string, plan: array}
 */
function csv_parse_product_file(string $path): array
{
    [$keys, $rows] = csv_rows($path);

    if (!$rows) {
        return ['ok' => false, 'message' => 'That file looks empty — nothing to import.', 'plan' => []];
    }
    foreach (['sku', 'name', 'price'] as $required) {
        if (!in_array($required, $keys, true)) {
            return ['ok' => false, 'message' => 'Missing the “' . $required . '” column. Download the template for the expected layout.', 'plan' => []];
        }
    }

    $products = [];   // sku => group
    $skipped  = [];
    $variantRows = 0;

    foreach ($rows as $index => $row) {
        $line = $index + 2;   // +1 header, +1 for 1-based line numbers
        $sku  = trim((string)($row['sku'] ?? ''));

        if ($sku === '') {
            $skipped[] = ['line' => $line, 'reason' => 'No SKU.'];
            continue;
        }
        if (trim((string)($row['name'] ?? '')) === '' && !isset($products[$sku])) {
            $skipped[] = ['line' => $line, 'reason' => 'No product name on the first row for SKU ' . $sku . '.'];
            continue;
        }
        if (csv_number((string)($row['price'] ?? '')) === null && !isset($products[$sku])) {
            $skipped[] = ['line' => $line, 'reason' => 'Price is not a number for SKU ' . $sku . '.'];
            continue;
        }

        if (!isset($products[$sku])) {
            $products[$sku] = [
                'sku'        => $sku,
                'name'       => trim((string)($row['name'] ?? '')),
                'slug'       => trim((string)($row['slug'] ?? '')),
                'category'   => trim((string)($row['category'] ?? '')),
                'brand'      => trim((string)($row['brand'] ?? '')),
                'price'      => (float)csv_number((string)($row['price'] ?? '0')),
                'sale_price' => csv_number((string)($row['sale_price'] ?? '')),
                'stock'      => (int)csv_number((string)($row['stock'] ?? '0')),
                'short_desc' => trim((string)($row['short_description'] ?? '')),
                'description' => trim((string)($row['description'] ?? '')),
                'is_featured' => csv_bool((string)($row['is_featured'] ?? '')),
                'is_active'   => csv_bool((string)($row['is_active'] ?? ''), true),
                'options'    => [],
                'variants'   => [],
                'lines'      => [],
            ];
        }

        $group = &$products[$sku];
        $group['lines'][] = $line;

        // Variant row? Needs both the option names and the matching values.
        $optionNames  = trim((string)($row['option_names'] ?? ''));
        $optionValues = trim((string)($row['option_values'] ?? ''));

        if ($optionNames !== '' && $optionValues !== '') {
            $names  = array_values(array_filter(array_map('trim', explode('|', $optionNames)), static fn($v) => $v !== ''));
            $values = array_values(array_filter(array_map('trim', explode('|', $optionValues)), static fn($v) => $v !== ''));

            if (count($names) !== count($values)) {
                $skipped[] = ['line' => $line, 'reason' => 'Option names (' . count($names) . ') and values (' . count($values) . ') must line up.'];
                continue;
            }

            $options = [];
            foreach ($names as $i => $name) {
                $options[$name] = $values[$i];
                // Every value seen in the file becomes an option choice.
                $group['options'][$name] ??= [];
                if (!in_array($values[$i], $group['options'][$name], true)) {
                    $group['options'][$name][] = $values[$i];
                }
            }

            $group['variants'][] = [
                'options' => $options,
                'sku'     => trim((string)($row['variant_sku'] ?? '')),
                'price'   => csv_number((string)($row['variant_price'] ?? '')),
                'stock'   => (int)(csv_number((string)($row['variant_stock'] ?? '0')) ?? 0),
            ];
            $variantRows++;
        } elseif (trim((string)($row['variant_sku'] ?? '')) !== '') {
            $skipped[] = ['line' => $line, 'reason' => 'variant_sku given without option_names/option_values.'];
        }
    }

    if (!$products) {
        return ['ok' => false, 'message' => 'No usable rows found in that file.', 'plan' => []];
    }

    // Work out which SKUs already exist so the preview can label each row.
    $create = [];
    $update = [];
    foreach (array_keys($products) as $sku) {
        $existing = Database::row('SELECT id, name FROM products WHERE sku = ?', [$sku]);
        if ($existing) {
            $update[$sku] = ['id' => (int)$existing['id'], 'name' => (string)$existing['name']];
        } else {
            $create[$sku] = true;
        }
    }

    return ['ok' => true, 'message' => 'File parsed.', 'plan' => [
        'products'     => $products,
        'create'       => $create,
        'update'       => $update,
        'skipped'      => $skipped,
        'variant_rows' => $variantRows,
    ]];
}

/**
 * Apply a parsed plan inside a transaction.
 *
 * @return array{mode: string, created: int, updated: int, variants: int, skipped: array, messages: string[]}
 */
function csv_apply_product_import(array $plan): array
{
    $created = 0;
    $updated = 0;
    $variantsSaved = 0;
    $messages = [];

    $pdo = Database::pdo();
    $pdo->beginTransaction();

    try {
        foreach ($plan['products'] as $sku => $group) {
            // Category by name — created on demand so a fresh import can seed
            // its own taxonomy.
            $categoryId = null;
            if ($group['category'] !== '') {
                $categoryId = Database::value('SELECT id FROM categories WHERE name = ?', [$group['category']]);
                if (!$categoryId) {
                    $categoryId = Database::insert('categories', [
                        'name'        => $group['category'],
                        'slug'        => unique_slug('categories', $group['category']),
                        'description' => '',
                        'is_active'   => 1,
                        'sort_order'  => 99,
                        'created_at'  => now(),
                    ]);
                    $messages[] = 'Created category “' . $group['category'] . '”.';
                }
                $categoryId = (int)$categoryId;
            }

            $existing = Database::row('SELECT * FROM products WHERE sku = ?', [$sku]);

            $payload = [
                'name'        => $group['name'],
                'category_id' => $categoryId,
                'brand'       => $group['brand'],
                'price'       => $group['price'],
                'sale_price'  => $group['sale_price'] !== null && $group['sale_price'] > 0 ? $group['sale_price'] : null,
                'stock'       => $group['stock'],
                'short_desc'  => $group['short_desc'],
                'description' => $group['description'],
                'is_featured' => $group['is_featured'] ? 1 : 0,
                'is_active'   => $group['is_active'] ? 1 : 0,
                'updated_at'  => now(),
            ];

            if ($existing) {
                $productId = (int)$existing['id'];
                if ($group['slug'] !== '' && $group['slug'] !== $existing['slug']) {
                    $payload['slug'] = unique_slug('products', $group['slug'], $productId);
                }
                Database::update('products', $payload, 'id = :wid', ['wid' => $productId]);
                $updated++;
            } else {
                $payload['sku']        = $sku;
                $payload['slug']       = unique_slug('products', $group['slug'] !== '' ? $group['slug'] : $group['name']);
                $payload['image']      = '';
                $payload['created_at'] = now();
                $productId = (int)Database::insert('products', $payload);
                $created++;
            }

            // Variants: the file is the source of truth for this SKU.
            if ($group['variants']) {
                $groups = [];
                foreach ($group['options'] as $name => $values) {
                    $groups[] = ['name' => $name, 'values' => $values];
                }
                $rows = array_map(static fn($v) => [
                    'options' => $v['options'],
                    'sku'     => $v['sku'] !== '' ? $v['sku'] : $sku . '-' . strtoupper(substr(md5(variant_label($v['options'])), 0, 4)),
                    'price'   => $v['price'],
                    'stock'   => $v['stock'],
                    'is_active' => 1,
                ], $group['variants']);

                $result = save_variants($productId, $groups, $rows);
                $variantsSaved += $result['count'];
                $messages[] = 'SKU ' . $sku . ': ' . $result['count'] . ' variant combination(s).';
            }

            // Uploaded files may contain HTML — keep it to the safe subset.
            Database::run('UPDATE products SET description = ? WHERE id = ?', [
                sanitize_html($group['description']), $productId
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'mode' => 'committed', 'created' => 0, 'updated' => 0, 'variants' => 0,
            'skipped' => $plan['skipped'], 'messages' => ['Import failed and was rolled back: ' . $e->getMessage()],
        ];
    }

    return [
        'mode' => 'committed', 'created' => $created, 'updated' => $updated,
        'variants' => $variantsSaved, 'skipped' => $plan['skipped'], 'messages' => $messages,
    ];
}
