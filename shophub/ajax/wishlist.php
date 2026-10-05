<?php
/**
 * AJAX — toggle wishlist items
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (!is_post() || !is_ajax()) {
    json_out(['ok' => false, 'message' => 'Bad request.'], 400);
}
require_csrf();

$id = (int)post('product_id');
$product = Database::row('SELECT id, name FROM products WHERE id = ? AND is_active = 1', [$id]);
if (!$product) {
    json_out(['ok' => false, 'message' => 'Product not found.'], 404);
}

$ids  = wishlist_ids();
$isOn = in_array($id, $ids, true);

if (auth_check()) {
    if ($isOn) {
        Database::run('DELETE FROM wishlists WHERE user_id = ? AND product_id = ?', [auth_id(), $id]);
    } else {
        wishlist_add((int)auth_id(), $id);
    }
} else {
    $guest = array_values(array_unique(array_map('intval', $_SESSION['guest_wishlist'] ?? [])));
    if ($isOn) {
        $guest = array_values(array_diff($guest, [$id]));
    } else {
        $guest[] = $id;
    }
    $_SESSION['guest_wishlist'] = $guest;
}

$newIds = wishlist_ids();
json_out([
    'ok'       => true,
    'added'    => !$isOn,
    'count'    => count($newIds),
    'message'  => (!$isOn ? 'Saved ' : 'Removed ') . $product['name'] . (!$isOn ? ' to your wishlist.' : ' from your wishlist.'),
    'needAuth' => !auth_check(),
]);
