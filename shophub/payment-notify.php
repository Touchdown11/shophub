<?php
/**
 * ShopHub — server-to-server payment notifications.
 *
 *   ?gateway=payhere  PayHere posts here directly (see payhere_fields()).
 *                     We only act when the md5sig matches.
 *   ?gateway=stripe   Stripe webhooks land here. We verify the
 *                     Stripe-Signature header against the signing secret set in
 *                     Settings → Stripe keys, so a forged POST is rejected.
 *
 * Neither branch needs a session or a CSRF token: the caller is a payment
 * provider, and the signature is what proves the request is genuine.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/orders.php';
require_once __DIR__ . '/includes/payments.php';

$gateway = (string)query('gateway', 'payhere');

// Always answer 200 so the gateway stops retrying, but only after we decide.
header('Content-Type: text/plain; charset=utf-8');

if (!in_array($gateway, ['payhere', 'stripe'], true)) {
    http_response_code(404);
    echo 'unknown gateway';
    exit;
}

/* ------------------------------------------------------------------- Stripe -- */

if ($gateway === 'stripe') {
    $secret = trim((string)setting('stripe_webhook_secret', ''));
    if ($secret === '') {
        http_response_code(400);
        echo 'no webhook signing secret configured';
        exit;
    }

    $payload   = (string)file_get_contents('php://input');
    $signature = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
    $parts     = [];
    foreach (explode(',', $signature) as $chunk) {
        [$k, $v] = array_pad(explode('=', trim($chunk), 2), 2, '');
        $parts[$k][] = $v;
    }
    $timestamp = (string)($parts['t'][0] ?? '');
    $expected  = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    $matches   = false;
    foreach ($parts['v1'] ?? [] as $candidate) {
        if ($candidate !== '' && hash_equals($expected, $candidate)) {
            $matches = true;
        }
    }

    if (!$matches || abs(time() - (int)$timestamp) > 300) {
        http_response_code(400);
        echo 'invalid signature';
        exit;
    }

    $event = json_decode($payload, true);
    $type  = (string)($event['type'] ?? '');
    $obj   = (array)($event['data']['object'] ?? []);

    if (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'payment_intent.succeeded'], true)) {
        $orderId = (int)($obj['metadata']['order_id'] ?? 0);
        $order   = $orderId > 0 ? find_order($orderId) : null;
        $paid    = $type === 'payment_intent.succeeded' || ($obj['payment_status'] ?? '') === 'paid' || ($obj['status'] ?? '') === 'succeeded';

        if ($order) {
            Database::insert('order_notes', [
                'order_id'   => (int)$order['id'],
                'user_id'    => null,
                'note'       => sprintf('Stripe webhook %s (%s).', $type, $paid ? 'paid' : 'not paid'),
                'created_at' => now(),
            ]);
            if ($paid) {
                Database::run('UPDATE orders SET payment_ref = ? WHERE id = ?', [(string)($obj['id'] ?? ''), (int)$order['id']]);
                mark_order_paid((int)$order['id'], 'stripe');
            }
        }
    }

    echo 'ok';
    exit;
}

if (!is_post()) {
    http_response_code(405);
    echo 'POST required';
    exit;
}

$post = $_POST;
$reference = (string)($post['order_id'] ?? '');
$order = $reference !== '' ? find_order_by_reference($reference) : null;

if (!$order) {
    http_response_code(404);
    echo 'unknown order';
    exit;
}

if (!payhere_verify_notify($post)) {
    Database::insert('order_notes', [
        'order_id'   => (int)$order['id'],
        'user_id'    => null,
        'note'       => 'PayHere notification REJECTED — signature mismatch. Order left untouched.',
        'created_at' => now(),
    ]);
    http_response_code(400);
    echo 'invalid signature';
    exit;
}

$statusCode = (string)($post['status_code'] ?? '');
$paymentId  = (string)($post['payment_id'] ?? '');
$amount     = (string)($post['payhere_amount'] ?? '');
$currency   = (string)($post['payhere_currency'] ?? '');

Database::insert('order_notes', [
    'order_id'   => (int)$order['id'],
    'user_id'    => null,
    'note'       => sprintf(
        'PayHere callback: status=%s (%s), payment_id=%s, amount=%s %s',
        $statusCode,
        (string)($post['status_message'] ?? ''),
        $paymentId,
        $amount,
        $currency
    ),
    'created_at' => now(),
]);

switch ($statusCode) {
    case '2': // success
        Database::run('UPDATE orders SET payment_ref = ? WHERE id = ?', [$paymentId, (int)$order['id']]);
        mark_order_paid((int)$order['id'], 'payhere');
        break;

    case '0': // pending
        Database::run('UPDATE orders SET payment_ref = ? WHERE id = ?', [$paymentId, (int)$order['id']]);
        break;

    case '-1': // cancelled
    case '-2': // failed
    case '-3': // chargedback
        if ($order['payment_status'] !== 'paid') {
            Database::run("UPDATE orders SET payment_status = 'unpaid' WHERE id = ?", [(int)$order['id']]);
        }
        break;
}

echo 'ok';
