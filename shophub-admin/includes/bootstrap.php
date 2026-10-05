<?php
/**
 * ShopHub staff portal — bootstrap
 * ---------------------------------------------------------------------------
 * This app is deliberately separate from the storefront: its own folder, its
 * own session cookie, its own sign-in screen. It borrows the store's database
 * and helper library so business rules live in exactly one place.
 *
 * Point it at the store with, in order of preference:
 *   1. the SHOPHUB_STORE_ROOT environment variable / constant,
 *   2. 'store_path' in shophub-admin/includes/config.local.php,
 *   3. auto-detection (sibling folders, or nested one level deep).
 */
declare(strict_types=1);

if (defined('SHOPHUB_PORTAL')) {
    // Already booted (e.g. an endpoint included from a page).
    return;
}

define('SHOPHUB_PORTAL', 'admin');
define('SHOPHUB_ADMIN', true);

/* ------------------------------------------------------------------ store lookup */

function shophub_locate_store(): string
{
    $candidates = [];

    $env = getenv('SHOPHUB_STORE_ROOT');
    if (is_string($env) && $env !== '') {
        $candidates[] = $env;
    }

    $local = __DIR__ . '/config.local.php';
    if (is_file($local)) {
        $extra = (array)(require $local);
        if (!empty($extra['store_path'])) {
            $candidates[] = (string)$extra['store_path'];
        }
    }

    // Sibling folders:  htdocs/shophub-admin  +  htdocs/shophub
    $candidates[] = dirname(__DIR__, 2) . '/shophub';
    // Nested one level: htdocs/shophub/shophub-admin  +  htdocs/shophub/shophub
    $candidates[] = dirname(__DIR__, 2) . '/shophub/shophub';
    // Deeper nesting:   htdocs/shop/shophub-admin     +  htdocs/shop/shophub
    $candidates[] = dirname(__DIR__, 3) . '/shophub';
    // Last resort: the folder this app sits in also holds the store.
    $candidates[] = dirname(__DIR__) . '/store';

    foreach ($candidates as $candidate) {
        $candidate = rtrim(str_replace('\\', '/', (string)$candidate), '/');
        if ($candidate === '' || !is_dir($candidate)) {
            continue;
        }
        if (is_file($candidate . '/includes/functions.php') && is_file($candidate . '/install.php')) {
            return $candidate;
        }
    }

    return '';
}

$storeRoot = shophub_locate_store();

if ($storeRoot === '') {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>ShopHub staff portal</title>'
        . '<style>body{background:#0b0b0d;color:#e9e9ee;font:16px/1.6 Helvetica,Arial,sans-serif;padding:48px;max-width:720px;margin:auto}'
        . 'code{background:#1b1b21;padding:2px 7px;border-radius:6px;color:#ffc400}h1{font-family:Georgia,serif}</style></head><body>'
        . '<h1>I can&rsquo;t find the storefront</h1>'
        . '<p>The staff portal needs the <strong>shophub</strong> store folder next to it, for example:</p>'
        . '<pre>htdocs/shophub/           &lt;- the store\nhtdocs/shophub-admin/     &lt;- this portal</pre>'
        . '<p>Deployed somewhere unusual? Create <code>shophub-admin/includes/config.local.php</code> containing:</p>'
        . '<pre>&lt;?php return [\'store_path\' =&gt; \'C:/xampp/htdocs/shophub\'];</pre>'
        . '</body></html>';
    exit;
}

define('SHOPHUB_STORE_ROOT', $storeRoot);

/* ------------------------------------------------------------- shared libraries */

foreach ([
    '/includes/config.php',
    '/includes/db.php',
    '/includes/functions.php',
    '/includes/auth.php',
    '/includes/variants.php',
    '/includes/orders.php',
    '/includes/mailer.php',
    '/includes/payments.php',
] as $file) {
    require_once SHOPHUB_STORE_ROOT . $file;
}

/* ------------------------------------------------------------------- admin extras */

/**
 * Icons for the staff UI. Keeps the storefront's inline-SVG approach (no icon
 * fonts, no CDN) while keeping portal-only helpers out of the store code.
 */
function admin_icon(string $name, int $size = 18): string
{
    $paths = [
        'grid'      => '<path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z" fill="none" stroke="currentColor" stroke-width="1.6"/>',
        'box'       => '<path d="M12 3 4 7v10l8 4 8-4V7l-8-4Z M4 7l8 4 8-4 M12 21V11" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>',
        'tags'      => '<path d="M4 6h9l7 7-7 7-9-9V6Z" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="8" cy="10" r="1.4" fill="currentColor"/>',
        'cart'      => '<path d="M4 5h2l2.2 9.2a2 2 0 0 0 2 1.6h6.4a2 2 0 0 0 2-1.5L20 8H7" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="10" cy="20" r="1.4" fill="currentColor"/><circle cx="18" cy="20" r="1.4" fill="currentColor"/>',
        'chart'     => '<path d="M4 20V9M10 20V4M16 20v-7M22 20H2" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>',
        'users'     => '<path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z M2 21c0-3.3 3-6 7-6s7 2.7 7 6 M17 11a3 3 0 1 0 0-6 M22 21c0-2.7-1.9-4.8-4.5-5.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
        'star'      => '<path d="m12 3.6 2.6 5.4 5.9.8-4.3 4.1 1 5.9-5.2-2.8-5.2 2.8 1-5.9L3.5 9.8l5.9-.8Z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>',
        'ticket'    => '<path d="M4 8a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4V8Z" fill="none" stroke="currentColor" stroke-width="1.6"/>',
        'mail'      => '<path d="M3 6h18v12H3z" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="m3 7 9 6 9-6" fill="none" stroke="currentColor" stroke-width="1.6"/>',
        'upload'    => '<path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5M4 20h16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>',
        'settings'  => '<circle cx="12" cy="12" r="3.2" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M12 2.8v2.6M12 18.6v2.6M4.6 4.6l1.9 1.9M17.5 17.5l1.9 1.9M2.8 12h2.6M18.6 12h2.6M4.6 19.4l1.9-1.9M17.5 6.5l1.9-1.9" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
        'search'    => '<circle cx="11" cy="11" r="6" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="m20 20-4.2-4.2" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>',
        'plus'      => '<path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>',
        'edit'      => '<path d="M4 20h4L20 8l-4-4L4 16v4Z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>',
        'trash'     => '<path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
        'eye'       => '<path d="M2 12s3.6-6 10-6 10 6 10 6-3.6 6-10 6-10-6-10-6Z" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="12" r="2.6" fill="none" stroke="currentColor" stroke-width="1.6"/>',
        'logout'    => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 8l-4 4 4 4M6 12h10" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>',
        'back'      => '<path d="M10 6 4 12l6 6M4 12h16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>',
        'download'  => '<path d="M12 4v12m0 0 4.5-4.5M12 16l-4.5-4.5M4 20h16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>',
        'check'     => '<path d="m5 13 4.5 4.5L19 7" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/>',
        'clock'     => '<circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M12 7.5V12l3 2" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>',
        'grip'      => '<path d="M9 6h.01M9 12h.01M9 18h.01M15 6h.01M15 12h.01M15 18h.01" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/>',
        'external'  => '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>',
    ];

    $path = $paths[$name] ?? $paths['grid'];

    return '<svg class="ico" viewBox="0 0 24 24" width="' . $size . '" height="' . $size
        . '" aria-hidden="true" focusable="false">' . $path . '</svg>';
}

/** Flashes are rendered by the layout; this pulls them out of the session once. */
function take_flashes_html(): string
{
    $out = '';
    foreach (take_flashes() as $flash) {
        $type = preg_match('/^(success|danger|warn|info)$/', (string)$flash['type']) ? $flash['type'] : 'info';
        $out .= '<div class="flash flash-' . $type . '"><span>' . e((string)$flash['message']) . '</span>'
             . '<button type="button" class="flash-x" aria-label="Dismiss">&times;</button></div>';
    }

    return $out;
}

/** A tiny helper the dashboard and lists use for empty states. */
function admin_empty(string $title, string $body = '', string $icon = 'box'): string
{
    return '<div class="empty">' . admin_icon($icon, 30)
        . '<h3>' . e($title) . '</h3>'
        . ($body !== '' ? '<p class="muted">' . e($body) . '</p>' : '')
        . '</div>';
}

/* Keep staff timezone/currency consistent with the store, then hand over. */
date_default_timezone_set((string)cfg('app.timezone', 'Asia/Colombo'));
