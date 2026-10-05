<?php
/**
 * AJAX — switch display currency (USD / LKR). Prices are stored in USD and
 * converted for display; the order is always recorded in the store currency.
 */
require_once __DIR__ . '/../includes/functions.php';

if (!is_post() || !is_ajax()) {
    json_out(['ok' => false, 'message' => 'Bad request.'], 400);
}
require_csrf();

$currency = strtoupper((string)post('currency'));
$allowed  = [store_currency(), (string)cfg('store.secondary', 'LKR')];

if (!in_array($currency, $allowed, true)) {
    json_out(['ok' => false, 'message' => 'Unsupported currency.'], 422);
}

$_SESSION['currency'] = $currency;

json_out([
    'ok'       => true,
    'currency' => $currency,
    'message'  => 'Prices now shown in ' . $currency . '.',
]);
