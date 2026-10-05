<?php
/**
 * AJAX — live search suggestions
 */
require_once __DIR__ . '/../includes/functions.php';

$term = trim((string)query('q'));
header('Content-Type: application/json; charset=utf-8');

if (mb_strlen($term) < 2) {
    json_out(['ok' => true, 'items' => []]);
}

$like = '%' . $term . '%';
$rows = Database::all(
    'SELECT p.id, p.name, p.slug, p.price, p.sale_price, p.image, c.name AS category_name
     FROM products p LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.is_active = 1 AND (p.name LIKE ? OR p.brand LIKE ? OR p.short_desc LIKE ?)
     ORDER BY p.is_featured DESC, p.id DESC
     LIMIT 6',
    [$like, $like, $like]
);

$items = array_map(static fn($r) => [
    'id'       => (int)$r['id'],
    'name'     => $r['name'],
    'url'      => base_url('product.php?slug=' . urlencode((string)$r['slug'])),
    'image'    => product_image($r['image'], (string)$r['name']),
    'category' => $r['category_name'] ?? 'ShopHub',
    'price'    => money(product_price($r)),
], $rows);

json_out(['ok' => true, 'items' => $items]);
