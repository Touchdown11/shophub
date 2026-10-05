<?php
/**
 * ShopHub — payment gateways
 *
 * The checkout offers whatever is enabled in Admin → Settings:
 *
 *   cod            manual   → cash on delivery
 *   bank_transfer  manual   → bank transfer instructions
 *   stripe         gateway  → Stripe Checkout (redirect + server-side verify)
 *   payhere        gateway  → PayHere Checkout (signed form + server callback)
 *
 * Nothing here needs Composer — both gateways are plain HTTPS calls.
 * Keys live in the settings table, never in code.
 */
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

const PAYMENT_METHODS = [
    'cod'           => 'Cash on delivery',
    'bank_transfer' => 'Bank transfer',
    'stripe'        => 'Card — Stripe',
    'payhere'       => 'Card / wallet — PayHere',
];

/** Methods currently offered at checkout, in display order. */
function payment_methods_available(): array
{
    $out = [];
    if ((int)setting('payment_cod_enabled', 1)) {
        $out['cod'] = PAYMENT_METHODS['cod'];
    }
    if (setting_bool('payment_bank_transfer_enabled', true)) {
        $out['bank_transfer'] = PAYMENT_METHODS['bank_transfer'];
    }
    if (stripe_configured() && (int)setting('payment_stripe_enabled', 0)) {
        $out['stripe'] = PAYMENT_METHODS['stripe'];
    }
    if (payhere_configured() && (int)setting('payment_payhere_enabled', 0)) {
        $out['payhere'] = PAYMENT_METHODS['payhere'];
    }
    return $out;
}

function is_gateway_method(string $method): bool
{
    return in_array($method, ['stripe', 'payhere'], true);
}

function payment_method_label(string $method): string
{
    return PAYMENT_METHODS[$method] ?? ucfirst(str_replace('_', ' ', $method));
}

/* ------------------------------------------------------------------ Stripe -- */

function stripe_configured(): bool
{
    return trim((string)setting('stripe_secret_key', '')) !== ''
        && trim((string)setting('stripe_publishable_key', '')) !== '';
}

function stripe_secret(): string
{
    return trim((string)setting('stripe_secret_key', ''));
}

/**
 * Create a Stripe Checkout Session and return its hosted payment URL.
 *
 * @return array{ok: bool, url?: string, message: string}
 */
function stripe_create_checkout(array $order): array
{
    if (!stripe_configured()) {
        return ['ok' => false, 'message' => 'Stripe keys are not configured.'];
    }

    $currency = strtolower((string)$order['currency']);
    $params   = [
        'mode'                 => 'payment',
        'success_url'          => store_link('payment-return.php?order=' . (int)$order['id'] . '&gateway=stripe&session_id={CHECKOUT_SESSION_ID}'),
        'cancel_url'           => store_link('checkout.php?cancelled=1'),
        'client_reference_id'  => (string)$order['reference'],
        'customer_email'       => (string)$order['email'],
        'metadata[order_id]'   => (int)$order['id'],
        'metadata[reference]'  => (string)$order['reference'],
    ];

    $index = 0;
    foreach (order_items((int)$order['id']) as $item) {
        $name = (string)$item['product_name'];
        if (!empty($item['variant_label'])) {
            $name .= ' (' . $item['variant_label'] . ')';
        }
        $params["line_items[$index][quantity]"]                          = (int)$item['quantity'];
        $params["line_items[$index][price_data][currency]"]              = $currency;
        $params["line_items[$index][price_data][unit_amount]"]           = (int)round((float)$item['unit_price'] * 100);
        $params["line_items[$index][price_data][product_data][name]"]    = mb_substr($name, 0, 120);
        $index++;
    }

    // Delivery + tax as their own lines so the total always reconciles.
    if ((float)$order['shipping_fee'] > 0) {
        $params["line_items[$index][quantity]"]                       = 1;
        $params["line_items[$index][price_data][currency]"]           = $currency;
        $params["line_items[$index][price_data][unit_amount]"]        = (int)round((float)$order['shipping_fee'] * 100);
        $params["line_items[$index][price_data][product_data][name]"] = 'Delivery';
        $index++;
    }
    if ((float)$order['tax'] > 0) {
        $params["line_items[$index][quantity]"]                       = 1;
        $params["line_items[$index][price_data][currency]"]           = $currency;
        $params["line_items[$index][price_data][unit_amount]"]        = (int)round((float)$order['tax'] * 100);
        $params["line_items[$index][price_data][product_data][name]"] = 'Tax';
        $index++;
    }

    /*
     * Discounts: Stripe line items cannot be negative, so when a coupon was
     * applied we create a one-off percentage coupon that brings the session
     * total down to exactly the order total, then attach it to the session.
     */
    if ((float)$order['discount'] > 0) {
        $grossCents = (int)round(((float)$order['subtotal'] + (float)$order['shipping_fee'] + (float)$order['tax']) * 100);
        $targetCents = (int)round((float)$order['total'] * 100);

        if ($grossCents > 0 && $targetCents < $grossCents) {
            $percent = round(100 - ($targetCents / $grossCents * 100), 2);
            $coupon  = stripe_request('POST', '/v1/coupons', [
                'percent_off'   => $percent,
                'duration'      => 'once',
                'name'          => 'Discount ' . (string)($order['coupon_code'] ?? ''),
                'metadata[order]' => (string)$order['reference'],
            ]);
            if ($coupon['ok'] && !empty($coupon['data']['id'])) {
                $params['discounts[0][coupon]'] = (string)$coupon['data']['id'];
            } else {
                return ['ok' => false, 'message' => 'Could not apply the discount at the gateway: ' . $coupon['message']];
            }
        }
    }

    $response = stripe_request('POST', '/v1/checkout/sessions', $params);
    if (!$response['ok']) {
        return $response;
    }
    $session = $response['data'];

    return ['ok' => true, 'url' => (string)($session['url'] ?? ''), 'message' => 'Stripe session created.'];
}

/** Verify a returned Checkout Session is actually paid before trusting it. */
function stripe_verify_session(string $sessionId): array
{
    $response = stripe_request('GET', '/v1/checkout/sessions/' . urlencode($sessionId));
    if (!$response['ok']) {
        return $response;
    }
    $session = $response['data'];
    return [
        'ok'       => true,
        'paid'     => ($session['payment_status'] ?? '') === 'paid',
        'order_id' => (int)($session['metadata']['order_id'] ?? 0),
        'amount'   => ((int)($session['amount_total'] ?? 0)) / 100,
        'message'  => 'payment_status=' . ($session['payment_status'] ?? 'unknown'),
    ];
}

/** Minimal Stripe REST client (curl when available, stream wrapper otherwise). */
function stripe_request(string $method, string $path, array $params = []): array
{
    $url = 'https://api.stripe.com' . $path;
    $headers = [
        'Authorization: Bearer ' . stripe_secret(),
        'Stripe-Version: 2024-06-20',
    ];
    $body = http_build_query($params);

    if ($method === 'GET') {
        $url .= ($params ? '?' . $body : '');
        $body = null;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return ['ok' => false, 'message' => 'Stripe request failed: ' . $err];
        }
    } else {
        $context = stream_context_create(['http' => [
            'method'        => $method,
            'header'        => implode("\r\n", array_merge($headers, ['Content-Type: application/x-www-form-urlencoded'])),
            'content'       => $body,
            'timeout'       => 25,
            'ignore_errors' => true,
        ]]);
        $raw  = @file_get_contents($url, false, $context);
        $code = (int)($http_response_header ? preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m) ? $m[1] : 0 : 0);
        if ($raw === false) {
            return ['ok' => false, 'message' => 'Stripe request failed (no network or TLS issue).'];
        }
    }

    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'message' => 'Stripe returned an unreadable response.'];
    }
    if ($code >= 400) {
        return ['ok' => false, 'message' => 'Stripe error: ' . (string)($data['error']['message'] ?? ('HTTP ' . $code))];
    }
    return ['ok' => true, 'data' => $data, 'message' => 'ok'];
}

/* ----------------------------------------------------------------- PayHere -- */

function payhere_configured(): bool
{
    return trim((string)setting('payhere_merchant_id', '')) !== ''
        && trim((string)setting('payhere_merchant_secret', '')) !== '';
}

function payhere_is_live(): bool
{
    return setting('payhere_mode', cfg('payments.payhere_mode', 'sandbox')) === 'live';
}

function payhere_endpoint(): string
{
    return payhere_is_live()
        ? 'https://www.payhere.lk/pay/checkout'
        : 'https://sandbox.payhere.lk/pay/checkout';
}

/** Amount PayHere should charge, honouring an optional forced currency. */
function payhere_amount(array $order): array
{
    $forced = strtoupper(trim((string)setting('payhere_currency', '')));
    $orderCurrency = strtoupper((string)$order['currency']);
    $amount = (float)$order['total'];

    if ($forced !== '' && $forced !== $orderCurrency) {
        // Convert using the same rate the storefront switch uses.
        $rate = (float)setting('secondary_rate', cfg('store.secondary_rate', 1));
        if ($forced === strtoupper((string)cfg('store.secondary', 'LKR'))) {
            $amount *= $rate;
        }
        $currency = $forced;
    } else {
        $currency = $orderCurrency;
    }

    return ['amount' => number_format($amount, 2, '.', ''), 'currency' => $currency];
}

/**
 * Official PayHere checkout hash:
 *   md5( merchant_id + order_id + amount + currency + md5(merchant_secret) )
 */
function payhere_checkout_hash(string $orderId, string $amount, string $currency): string
{
    $secret  = trim((string)setting('payhere_merchant_secret', ''));
    $merchant = trim((string)setting('payhere_merchant_id', ''));
    return strtoupper(md5(
        $merchant . $orderId . $amount . $currency . strtoupper(md5($secret))
    ));
}

/** Verify the server-to-server notification signature. */
function payhere_verify_notify(array $post): bool
{
    $secret = trim((string)setting('payhere_merchant_secret', ''));
    if ($secret === '') {
        return false;
    }
    $local = strtoupper(md5(
        (string)($post['merchant_id'] ?? '')
        . (string)($post['order_id'] ?? '')
        . (string)($post['payhere_amount'] ?? '')
        . (string)($post['payhere_currency'] ?? '')
        . (string)($post['status_code'] ?? '')
        . strtoupper(md5($secret))
    ));
    return hash_equals($local, strtoupper((string)($post['md5sig'] ?? '')));
}

/** Fields for the auto-submitting PayHere form. */
function payhere_fields(array $order): array
{
    $money  = payhere_amount($order);
    $items  = order_items((int)$order['id']);
    $names  = preg_split('/\s+/', trim((string)$order['customer_name'])) ?: [''];
    $first  = array_shift($names) ?: 'Customer';
    $last   = implode(' ', $names);

    return [
        'merchant_id' => (string)setting('payhere_merchant_id', ''),
        'return_url'  => store_link('payment-return.php?order=' . (int)$order['id'] . '&gateway=payhere'),
        'cancel_url'  => store_link('checkout.php?cancelled=1'),
        'notify_url'  => store_link('payment-notify.php?gateway=payhere'),
        'order_id'    => (string)$order['reference'],
        'items'       => mb_substr(implode(', ', array_map(static fn($i) => $i['product_name'], $items)), 0, 100),
        'currency'    => $money['currency'],
        'amount'      => $money['amount'],
        'first_name'  => mb_substr($first, 0, 50),
        'last_name'   => mb_substr($last, 0, 50),
        'email'       => (string)$order['email'],
        'phone'       => (string)$order['phone'],
        'address'     => mb_substr((string)$order['shipping_address'], 0, 100),
        'city'        => mb_substr((string)$order['city'], 0, 50),
        'country'     => mb_substr((string)$order['country'], 0, 50),
        'hash'        => payhere_checkout_hash((string)$order['reference'], $money['amount'], $money['currency']),
    ];
}

/* --------------------------------------------------------------- fulfilment -- */

/** Mark an order paid (idempotent) and fire the payment email once. */
function mark_order_paid(int $orderId, string $method = ''): bool
{
    $order = find_order($orderId);
    if (!$order || $order['payment_status'] === 'paid') {
        return false;
    }
    // Record when the money landed (and, for gateways, the reference we were given).
    if ($method !== '' && is_gateway_method($method)) {
        $ref = trim((string)($order['payment_ref'] ?? ''));
        Database::run(
            'UPDATE orders SET payment_status = ?, paid_at = ?, payment_ref = ?, updated_at = ? WHERE id = ?',
            ['paid', now(), $ref !== '' ? $ref : strtoupper($method) . '-' . $order['reference'], now(), $orderId]
        );
    } else {
        Database::run(
            'UPDATE orders SET payment_status = ?, paid_at = ?, updated_at = ? WHERE id = ?',
            ['paid', now(), now(), $orderId]
        );
    }
    if ($order['status'] === 'pending') {
        Database::run('UPDATE orders SET status = ? WHERE id = ?', ['processing', $orderId]);
    }

    $fresh = find_order($orderId);
    order_send_status_email($fresh, 'payment_received');
    return true;
}

/** Auto-submitting HTML form used to hand the customer to PayHere. */
function payment_gateway_form(array $order, string $method): string
{
    $fields = payhere_fields($order);
    $inputs = '';
    foreach ($fields as $name => $value) {
        $inputs .= '<input type="hidden" name="' . e($name) . '" value="' . e((string)$value) . '">' . "\n";
    }
    return '<form id="gateway-form" method="post" action="' . e(payhere_endpoint()) . '">' . $inputs . '</form>'
        . '<script>document.getElementById("gateway-form").submit();</script>';
}
