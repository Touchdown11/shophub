<?php
/**
 * AJAX — cart operations
 * POST actions: add | set | remove | clear | count
 *
 * The cart is keyed by "p{productId}" or "v{variantId}" so a specific
 * size/colour combination is its own line.
 */
require_once __DIR__ . '/../includes/functions.php';

if (!is_post() || !is_ajax()) {
    json_out(['ok' => false, 'message' => 'Bad request.'], 400);
}
require_csrf();

$action = (string)post('action', 'add');
$_SESSION['cart'] ??= [];

/** Slim payload used to refresh badges and totals without a reload. */
function cart_payload(array $cart): array
{
    return [
        'count'    => (int)$cart['count'],
        'subtotal' => money($cart['subtotal']),
        'discount' => money($cart['discount']),
        'shipping' => $cart['shipping'] > 0 ? money($cart['shipping']) : 'Free',
        'tax'      => money($cart['tax']),
        'total'    => money($cart['total']),
        'lines'    => array_map(static fn($i) => [
            'key'      => $i['key'],
            'qty'      => (int)$i['qty'],
            'subtotal' => money($i['subtotal']),
        ], $cart['items']),
    ];
}

switch ($action) {
    case 'add':
    case 'set':
        $variantId = (int)post('variant_id', 0);
        $productId = (int)post('product_id', 0);
        $qty       = max(1, (int)post('qty', 1));

        // The cart page addresses lines by their key (p{id} / v{id}).
        $key = (string)post('key', '');
        if ($key !== '') {
            $parsed = cart_key_parse($key);
            if ($parsed['type'] === 'variant') {
                $variantId = $parsed['id'];
            } else {
                $productId = $parsed['id'];
            }
        }

        if ($variantId > 0) {
            $variant = find_variant($variantId);
            if (!$variant || !(int)$variant['is_active']) {
                json_out(['ok' => false, 'message' => 'That option combination is no longer available.'], 404);
            }
            $product = Database::row('SELECT * FROM products WHERE id = ? AND is_active = 1', [(int)$variant['product_id']]);
            if (!$product) {
                json_out(['ok' => false, 'message' => 'That product is no longer available.'], 404);
            }
            $key   = cart_key_variant($variantId);
            $stock = (int)$variant['stock'];
            $name  = $product['name'] . ' (' . $variant['label'] . ')';
        } else {
            $id      = $productId;
            $product = Database::row('SELECT * FROM products WHERE id = ? AND is_active = 1', [$id]);
            if (!$product) {
                json_out(['ok' => false, 'message' => 'That product is no longer available.'], 404);
            }
            // A product that has variants must be added through its options.
            if (product_has_variants($id)) {
                json_out(['ok' => false, 'message' => 'Please choose your options first.'], 422);
            }
            $key   = cart_key_product($id);
            $stock = (int)$product['stock'];
            $name  = $product['name'];
        }

        if ($stock <= 0) {
            json_out(['ok' => false, 'message' => $name . ' is sold out.']);
        }

        $current = (int)($_SESSION['cart'][$key] ?? 0);
        $newQty  = $action === 'set' ? $qty : $current + $qty;

        if ($newQty > $stock) {
            $_SESSION['cart'][$key] = $stock;
            json_out([
                'ok'      => true,
                'message' => 'Only ' . $stock . ' left in stock — cart set to the maximum.',
                'cart'    => cart_payload(cart_contents()),
                'line'    => ['key' => $key, 'qty' => $stock],
            ]);
        }

        $_SESSION['cart'][$key] = $newQty;
        json_out([
            'ok'      => true,
            'message' => $name . ' added to your cart.',
            'cart'    => cart_payload(cart_contents()),
            'line'    => ['key' => $key, 'qty' => $newQty],
        ]);

    case 'remove':
        $key = (string)post('key', (string)post('product_id'));
        unset($_SESSION['cart'][$key]);
        json_out(['ok' => true, 'message' => 'Item removed.', 'cart' => cart_payload(cart_contents())]);

    case 'clear':
        $_SESSION['cart'] = [];
        unset($_SESSION['coupon']);
        json_out(['ok' => true, 'message' => 'Cart emptied.', 'cart' => cart_payload(cart_contents())]);

    case 'count':
    default:
        json_out(['ok' => true, 'cart' => cart_payload(cart_contents())]);
}
