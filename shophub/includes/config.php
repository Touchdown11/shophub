<?php
/**
 * ShopHub — configuration
 * ---------------------------------------------------------------------------
 * This file is shared by BOTH portals (the customer store and the separate
 * staff portal in ../shophub-admin). Portal-specific behaviour is driven by the
 * SHOPHUB_PORTAL constant, which each portal's bootstrap defines before this
 * file is loaded.
 *
 * To override anything WITHOUT editing this file, drop a file named
 * "config.local.php" next to this one that returns an array of overrides:
 *
 *   <?php return ['db' => ['driver' => 'sqlite']];
 *
 * The staff portal also looks for <store>/includes/config.local.php, so a
 * single override file configures both apps.
 */
declare(strict_types=1);

date_default_timezone_set('Asia/Colombo');

$defaults = [
    'app' => [
        'name'      => 'ShopHub',
        'tagline'   => 'Premium marketplace for everyday luxury',
        'debug'     => true,          // set false in production
        'timezone'  => 'Asia/Colombo',
        'admin_session_minutes' => 120, // staff portal idle timeout (0 = never)
    ],

    /**
     * Absolute URLs. Leave both blank for auto-detection — the store portal
     * derives the staff URL by appending "-admin" (and vice versa), which is
     * exactly how the two folders ship side by side:
     *
     *   htdocs/shophub/          ← customer store
     *   htdocs/shophub-admin/    ← staff portal
     *
     * Set them explicitly if you deploy to different hosts/subdomains.
     */
    'urls' => [
        'store' => '',   // e.g. https://shophub.test
        'admin' => '',   // e.g. https://admin.shophub.test
    ],

    'db' => [
        // 'mysql' for XAMPP / live hosting, 'sqlite' for a zero-setup demo.
        'driver'   => 'mysql',
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'shophub',
        'user'     => 'root',
        'pass'     => '',
        'charset'  => 'utf8mb4',
        'sqlite'   => __DIR__ . '/../storage/shophub.sqlite',
    ],

    'store' => [
        'currency'         => 'USD',   // base prices are stored in this currency
        'currency_symbol'  => '$',
        'secondary'        => 'LKR',   // shown by the storefront currency switch
        'secondary_symbol' => 'Rs.',
        'secondary_rate'   => 305.00,  // 1 USD = X LKR
        'free_shipping_over' => 150.00,
        'shipping_flat'      => 12.00,
        'tax_percent'        => 8.00,
        'items_per_page'     => 12,
    ],

    'features' => [
        // Require a verified email address before checkout and review posting.
        'require_email_verification' => true,
        'reviews_require_approval'   => true,
    ],

    'mail' => [
        // Driver: 'log' (write to storage/mail.log — perfect for XAMPP),
        //         'mail' (PHP mail(), needs a configured MTA/sendmail)
        //         'smtp' (direct SMTP, works great for Gmail/transactional hosts)
        'driver'      => 'log',
        'from'        => 'no-reply@shophub.test',
        'from_name'   => 'ShopHub',
        'reply_to'    => 'hello@shophub.test',
        'smtp_host'   => 'smtp.gmail.com',
        'smtp_port'   => 587,
        'smtp_secure' => 'tls',      // 'tls' (587) or 'ssl' (465)
        'smtp_user'   => '',
        'smtp_pass'   => '',
        'notify_admin_on_order' => true,
    ],

    'payments' => [
        // Strip keys are entered in Admin → Settings so they never live in code.
        'stripe_mode'  => 'test',    // test | live
        'payhere_mode' => 'sandbox', // sandbox | live
    ],
];

$local = __DIR__ . '/config.local.php';
if (!is_file($local)) {
    // Staff portal: fall back to the store's override file so the whole
    // installation is configured in exactly one place.
    $sibling = dirname(__DIR__, 2) . '/shophub/includes/config.local.php';
    if (is_file($sibling)) {
        $local = $sibling;
    }
}
if (is_file($local)) {
    $override = require $local;
    if (is_array($override)) {
        $defaults = array_replace_recursive($defaults, $override);
    }
}

// Handy for staging/sandbox runs: SHOPHUB_DB_DRIVER=sqlite php -S 0.0.0.0:8080
if ($envDriver = getenv('SHOPHUB_DB_DRIVER')) {
    $defaults['db']['driver'] = $envDriver;
}

$GLOBALS['SHOPHUB_CFG'] = $defaults;

/** Read a dotted config key: cfg('store.currency'). */
function cfg(string $key, mixed $fallback = null): mixed
{
    $value = $GLOBALS['SHOPHUB_CFG'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $fallback;
        }
        $value = $value[$part];
    }
    return $value;
}
