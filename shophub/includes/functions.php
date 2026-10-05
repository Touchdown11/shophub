<?php
/**
 * ShopHub — shared core
 *
 * Loaded by BOTH portals. The customer store lives in /shophub and the staff
 * portal lives in /shophub-admin; SHOPHUB_PORTAL tells this code which one is
 * running so URL helpers and session cookies never mix the two.
 */
declare(strict_types=1);

if (!defined('SHOPHUB_PORTAL')) {
    define('SHOPHUB_PORTAL', 'store');
}

/*
 * mbstring is enabled in XAMPP and most hosts, but a bare PHP build can lack it.
 * These two helpers are the only multibyte functions the app uses (truncating
 * descriptions and cutting long words), so a small fallback keeps the whole
 * portal working instead of throwing a fatal error on the dashboard.
 */
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $text, ?string $encoding = null): int
    {
        return strlen($text);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $text, int $start, ?int $length = null, ?string $encoding = null): string
    {
        return $length === null ? substr($text, $start) : substr($text, $start, $length);
    }
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (cfg('app.debug')) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    // The two portals get separate session cookies, so a storefront session can
    // never be reused as a staff session (and vice versa).
    session_name(SHOPHUB_PORTAL === 'admin' ? 'shophub_admin' : 'shophub_store');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

/* ------------------------------------------------------------------- routing */

/** Root URL of the portal currently running (no trailing slash). */
function portal_base(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $configured = (string)cfg('urls.' . SHOPHUB_PORTAL, '');
    if ($configured !== '') {
        return $base = rtrim($configured, '/');
    }

    // Auto-detect from the running script, ignoring the script file itself.
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $dir    = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($dir === '.' || $dir === '/') {
        $dir = '';
    }
    return $base = $dir;
}

/** URL inside the current portal. */
function base_url(string $path = ''): string
{
    return portal_base() . '/' . ltrim($path, '/');
}

/**
 * Filesystem path inside the store folder — works from either app.
 * The staff portal sets SHOPHUB_STORE_ROOT to the store it administers.
 */
function store_path(string $path = ''): string
{
    $root = defined('SHOPHUB_STORE_ROOT') && SHOPHUB_STORE_ROOT !== ''
        ? (string)SHOPHUB_STORE_ROOT
        : dirname(__DIR__);
    $root = rtrim(str_replace('\\', '/', $root), '/');

    return $path === '' ? $root : $root . '/' . ltrim($path, '/');
}

/**
 * Cache-buster for CSS/JS. Pass a file (absolute, or store-relative) and the
 * asset URL changes the moment the file does — no more stale stylesheets.
 */
function asset_version(string $file = ''): string
{
    if ($file !== '') {
        $path = is_file($file) ? $file : store_path($file);
        if (is_file($path)) {
            return (string)filemtime($path);
        }
    }

    return (string)cfg('app.version', '2.0');
}

/**
 * Public URL of a file that lives in the store (uploads, artwork).
 * Inside the staff portal those files are still served by the storefront.
 */
function media_url(string $path): string
{
    $path = ltrim($path, '/');

    return SHOPHUB_PORTAL === 'admin' ? store_url($path) : base_url($path);
}

function asset(string $path): string
{
    return base_url('assets/' . ltrim($path, '/'));
}

/**
 * Absolute URL of the customer store, from either portal.
 * Default layout: /shophub  and  /shophub-admin (siblings).
 */
function store_url(string $path = ''): string
{
    static $store = null;
    if ($store === null) {
        $configured = (string)cfg('urls.store', '');
        if ($configured !== '') {
            $store = rtrim($configured, '/');
        } elseif (SHOPHUB_PORTAL === 'store') {
            $store = portal_base();
        } else {
            $admin = portal_base();
            $store = str_ends_with($admin, '-admin')
                ? substr($admin, 0, -6)
                : rtrim(dirname($admin), '/');
        }
    }
    return $store . '/' . ltrim($path, '/');
}

/** Scheme + host of the current request ("http://localhost", "https://shop.example"). */
function request_origin(): string
{
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
    if ($host === '') {
        return $https ? 'https://localhost' : 'http://localhost';
    }
    return ($https ? 'https://' : 'http://') . $host;
}

/**
 * Turn a portal path into a fully-qualified URL.
 * Emails are read outside the browser, so links there must carry the host.
 */
function absolute_url(string $url): string
{
    if ($url === '') {
        return request_origin();
    }
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    return request_origin() . '/' . ltrim($url, '/');
}

/** Absolute link to a storefront page — safe to put in an email. */
function store_link(string $path = ''): string
{
    return absolute_url(store_url($path));
}

/** Absolute link to a staff-portal page — safe to put in an email. */
function admin_link(string $path = ''): string
{
    return absolute_url(admin_url($path));
}

/** Absolute URL of the staff portal, from either portal. */
function admin_url(string $path = ''): string
{
    static $admin = null;
    if ($admin === null) {
        $configured = (string)cfg('urls.admin', '');
        if ($configured !== '') {
            $admin = rtrim($configured, '/');
        } elseif (SHOPHUB_PORTAL === 'admin') {
            $admin = portal_base();
        } else {
            $admin = portal_base() . '-admin';
        }
    }
    return $admin . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : base_url($path)));
    exit;
}

function redirect_absolute(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/* -------------------------------------------------------------------- basics */

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function post(string $key, mixed $default = ''): mixed
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $value;
}

function query(string $key, mixed $default = ''): mixed
{
    $value = $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $value;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function is_ajax(): bool
{
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}

function json_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_ok(?string $token = null): bool
{
    $token ??= (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return $token !== '' && hash_equals($_SESSION['csrf'] ?? '', $token);
}

function require_csrf(): void
{
    if (!csrf_ok()) {

        if (is_ajax()) {
            json_out(['ok' => false, 'message' => 'Your session expired. Please refresh the page.'], 419);
        }
        flash('danger', 'Security token expired — please try again.');
        redirect('index.php');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

/* ------------------------------------------------------------------ settings */

function settings_all(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    try {
        foreach (Database::all('SELECT setting_key, setting_value FROM settings') as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    } catch (Throwable) {
        $cache = [];
    }
    return $cache;
}

function setting(string $key, mixed $fallback = null): mixed
{
    $all   = settings_all();
    $value = $all[$key] ?? null;
    if ($value === null || $value === '') {
        return $fallback ?? cfg('store.' . $key, $fallback);
    }
    return $value;
}

function setting_bool(string $key, bool $fallback = false): bool
{
    $value = setting($key, $fallback ? '1' : '0');
    return (string)$value === '1';
}

function setting_set(string $key, string $value): void
{
    $exists = Database::value('SELECT COUNT(*) FROM settings WHERE setting_key = ?', [$key]);
    if ($exists) {
        Database::run('UPDATE settings SET setting_value = ? WHERE setting_key = ?', [$value, $key]);
    } else {
        Database::insert('settings', ['setting_key' => $key, 'setting_value' => $value]);
    }
}

/* ------------------------------------------------------------------ currency */

function store_currency(): string
{
    return (string)setting('currency', cfg('store.currency', 'USD'));
}

function active_currency(): string
{
    $allowed = [store_currency(), cfg('store.secondary', 'LKR')];
    $choice  = $_SESSION['currency'] ?? null;
    return in_array($choice, $allowed, true) ? $choice : store_currency();
}

function money(float|string|null $amount, ?string $currency = null): string
{
    $amount     = (float)$amount;
    $currency ??= active_currency();
    $isBase     = $currency === store_currency();

    if ($isBase) {
        $value  = $amount;
        $symbol = (string)setting('currency_symbol', cfg('store.currency_symbol', '$'));
    } else {
        $rate   = (float)setting('secondary_rate', cfg('store.secondary_rate', 1));
        $value  = $amount * $rate;
        $symbol = (string)setting('secondary_symbol', cfg('store.secondary_symbol', 'Rs.'));
    }

    return $symbol . number_format($value, 2);
}

function product_price(array $product): float
{
    $sale  = (float)($product['sale_price'] ?? 0);
    $price = (float)($product['price'] ?? 0);
    return ($sale > 0 && $sale < $price) ? $sale : $price;
}

function discount_percent(array $product): int
{
    $price = (float)($product['price'] ?? 0);
    $sale  = (float)($product['sale_price'] ?? 0);
    if ($price <= 0 || $sale <= 0 || $sale >= $price) {
        return 0;
    }
    return (int)round(100 - ($sale / $price * 100));
}

/* --------------------------------------------------------------------- text */

function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    return trim($text, '-') ?: 'item';
}

function unique_slug(string $table, string $text, ?int $ignoreId = null): string
{
    $base = slugify($text);
    $slug = $base;
    $i    = 2;
    while (true) {
        $sql    = "SELECT COUNT(*) FROM {$table} WHERE slug = ?";
        $params = [$slug];
        if ($ignoreId) {
            $sql .= ' AND id <> ?';
            $params[] = $ignoreId;
        }
        if (!Database::value($sql, $params)) {
            return $slug;
        }
        $slug = $base . '-' . $i++;
    }
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function nice_date(?string $value, string $format = 'M j, Y'): string
{
    if (!$value) {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date($format, $ts) : '—';
}

function excerpt(?string $text, int $length = 120): string
{
    $text = trim(preg_replace('/\s+/', ' ', strip_tags((string)$text)) ?? '');
    return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text;
}

/* ------------------------------------------------------------- rich text ---- */

/**
 * Allowlist HTML sanitiser for editor content (WYSIWYG descriptions).
 * Unknown tags are unwrapped (text kept); script/style are removed entirely.
 */
function sanitize_html(string $html, array $allowed = []): string
{
    $allowed = $allowed ?: [
        'p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'h2' => [], 'h3' => [], 'h4' => [],
        'blockquote' => [], 'hr' => [], 'a' => ['href', 'title', 'target', 'rel'],
    ];

    if (trim($html) === '') {
        return '';
    }

    // XAMPP always ships ext-dom; the fallback keeps the app usable on a
    // stripped-down PHP build instead of fataling on a product page.
    if (!class_exists('DOMDocument')) {
        return sanitize_html_fallback($html, $allowed);
    }

    $doc = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<?xml encoding="UTF-8"><div id="shophub-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $root = $doc->getElementById('shophub-root');
    if (!$root) {
        return '';
    }

    $clean = static function (DOMNode $node) use (&$clean, $allowed, $doc): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMText) {
                continue;
            }
            if (!$child instanceof DOMElement) {
                $node->removeChild($child);
                continue;
            }

            $tag = strtolower($child->tagName);

            // Dangerous containers go entirely, content and all.
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'svg', 'link', 'meta'], true)) {
                $node->removeChild($child);
                continue;
            }

            if (!array_key_exists($tag, $allowed)) {
                // Unwrap: keep the words, drop the tag.
                $clean($child);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            // Strip attributes that are not allowlisted (kills on* handlers).
            foreach (iterator_to_array($child->attributes ?? []) as $attribute) {
                $name = strtolower($attribute->name);
                $keep = in_array($name, $allowed[$tag], true);
                if ($keep && $name === 'href') {
                    $value = trim($attribute->value);
                    if (preg_match('/^\s*(javascript|data|vbscript):/i', $value)) {
                        $keep = false;
                    }
                }
                if (!$keep) {
                    $child->removeAttribute($attribute->name);
                }
            }

            if ($tag === 'a') {
                $child->setAttribute('rel', 'noopener nofollow');
                if ($child->getAttribute('href') !== '') {
                    $child->setAttribute('target', '_blank');
                } else {
                    $node->removeChild($child);
                    continue;
                }
            }

            $clean($child);
        }
    };

    $clean($root);

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return trim($out);
}

/**
 * Tag-stripping sanitiser used when ext-dom is unavailable.
 * Same allowlist, same rules: dangerous containers vanish with their content,
 * unknown tags are unwrapped and every attribute except safe link ones is dropped.
 */
function sanitize_html_fallback(string $html, array $allowed): string
{
    $danger = 'script|style|iframe|object|embed|form|svg|link|meta|base|noscript';
    $html = (string)preg_replace('#<(' . $danger . ')\b[^>]*>.*?</\1\s*>#is', '', $html);
    $html = (string)preg_replace('#<\/?(?:' . $danger . ')\b[^>]*>#i', '', $html);

    $html = (string)preg_replace_callback(
        '#<([a-z0-9]+)([^>]*)>#i',
        static function (array $m) use ($allowed): string {
            $tag = strtolower($m[1]);
            if (!array_key_exists($tag, $allowed)) {
                return ''; // unwrap
            }
            $attrs = '';
            if (preg_match_all('/([a-z:-]+)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $m[2], $found, PREG_SET_ORDER)) {
                foreach ($found as $set) {
                    $name  = strtolower($set[1]);
                    $value = $set[3] !== '' ? $set[3] : ($set[4] !== '' ? $set[4] : ($set[5] ?? ''));
                    if (!in_array($name, $allowed[$tag], true)) {
                        continue;
                    }
                    if ($name === 'href' && preg_match('/^\s*(javascript|data|vbscript):/i', $value)) {
                        continue;
                    }
                    $attrs .= ' ' . $name . '="' . e($value) . '"';
                }
            }
            if ($tag === 'a' && str_contains($attrs, 'href=')) {
                $attrs .= ' rel="noopener nofollow" target="_blank"';
            }
            return '<' . $tag . $attrs . '>';
        },
        $html
    );

    return trim((string)preg_replace_callback(
        '#</([a-z0-9]+)\s*>#i',
        static fn(array $m): string => array_key_exists(strtolower($m[1]), $allowed) ? '</' . strtolower($m[1]) . '>' : '',
        $html
    ));
}

/**
 * Render stored description content on the storefront.
 * Plain-text rows (from the demo seed or an import) keep their paragraphs.
 */
function rich_text(?string $html, string $class = ''): string
{
    $html = (string)$html;
    if (trim($html) === '') {
        return '';
    }
    $clean = sanitize_html($html);
    if (!preg_match('/<(p|ul|ol|h[2-4]|blockquote|br)\b/i', $clean)) {
        $clean = '<p>' . nl2br($clean) . '</p>';
    }
    return $class ? '<div class="' . e($class) . '">' . $clean . '</div>' : $clean;
}

/* --------------------------------------------------------------------- misc */

function paginate(int $total, int $perPage, int $current, string $baseQuery = ''): array
{
    $pages = max(1, (int)ceil($total / max(1, $perPage)));
    $current = max(1, min($current, $pages));
    return [
        'total'   => $total,
        'pages'   => $pages,
        'current' => $current,
        'offset'  => ($current - 1) * $perPage,
        'per'     => $perPage,
        'query'   => $baseQuery,
    ];
}

function pagination_links(array $p, string $pageParam = 'page'): string
{
    if ($p['pages'] <= 1) {
        return '';
    }
    $link = function (int $n, string $label, bool $active = false, bool $disabled = false) use ($p, $pageParam) {
        $params = [];
        parse_str($p['query'], $params);
        $params[$pageParam] = $n;
        $href = '?' . http_build_query($params);
        $cls  = 'page-link' . ($active ? ' is-active' : '') . ($disabled ? ' is-disabled' : '');
        return '<a class="' . $cls . '" href="' . e($href) . '">' . $label . '</a>';
    };
    $out  = '<nav class="pagination">';
    $out .= $link(max(1, $p['current'] - 1), '&larr;', false, $p['current'] <= 1);
    for ($i = 1; $i <= $p['pages']; $i++) {
        if ($p['pages'] > 7 && $i > 2 && $i < $p['pages'] - 1 && abs($i - $p['current']) > 1) {
            if ($i === 3) {
                $out .= '<span class="page-gap">…</span>';
            }
            continue;
        }
        $out .= $link($i, (string)$i, $i === $p['current']);
    }
    $out .= $link(min($p['pages'], $p['current'] + 1), '&rarr;', false, $p['current'] >= $p['pages']);
    return $out . '</nav>';
}

function product_image(?string $path, ?string $name = null): string
{
    $path = trim((string)$path);
    if ($path !== '') {
        if (preg_match('#^https?://#', $path) || str_starts_with($path, 'data:')) {
            return $path;
        }
        if (is_file(store_path($path))) {
            return media_url($path);
        }
    }
    return placeholder_image($name ?? 'ShopHub');
}

function placeholder_image(string $label = 'ShopHub'): string
{
    $words = preg_split('/\s+/', trim($label)) ?: ['S'];
    $initials = strtoupper(substr($words[0] ?? 'S', 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 600" width="600" height="600">'
         . '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
         . '<stop offset="0" stop-color="#191919"/><stop offset="1" stop-color="#0b0b0d"/></linearGradient></defs>'
         . '<rect width="600" height="600" fill="url(#g)"/>'
         . '<circle cx="300" cy="285" r="150" fill="none" stroke="#ffc400" stroke-opacity=".35" stroke-width="2"/>'
         . '<text x="300" y="330" text-anchor="middle" font-family="Georgia, serif" font-size="120" fill="#ffc400" fill-opacity=".9">'
         . htmlspecialchars($initials, ENT_QUOTES) . '</text>'
         . '<text x="300" y="520" text-anchor="middle" font-family="Helvetica, Arial, sans-serif" letter-spacing="6" font-size="22" fill="#7a7a7a">SHOPHUB</text>'
         . '</svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

function product_gallery(int $productId): array
{
    return Database::all(
        'SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order, id',
        [$productId]
    );
}

function status_badge(string $status): string
{
    $map = [
        'pending' => 'warn', 'processing' => 'info', 'shipped' => 'gold', 'delivered' => 'ok',
        'cancelled' => 'danger', 'refunded' => 'muted', 'paid' => 'ok', 'unpaid' => 'warn',
        'active' => 'ok', 'inactive' => 'muted', 'draft' => 'muted',
        'new' => 'warn', 'read' => 'info', 'replied' => 'ok', 'resolved' => 'ok',
        'sent' => 'ok', 'failed' => 'danger', 'approved' => 'ok', 'rejected' => 'muted',
        'abandoned' => 'muted', 'verified' => 'ok', 'unverified' => 'warn',
    ];
    $tone = $map[strtolower($status)] ?? 'muted';
    return '<span class="badge badge-' . $tone . '">' . e(ucfirst($status)) . '</span>';
}

function order_statuses(): array
{
    return ['pending', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'];
}

function icon(string $name, string $class = ''): string
{
    $paths = [
        'edit'     => '<path d="M4 20h4l10.5-10.5a2.1 2.1 0 0 0-3-3L5 17v3z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M13.5 6.5l3 3" stroke="currentColor" stroke-width="1.7"/>',
        'trash'    => '<path d="M5 7h14M10 7V5h4v2M6.5 7l.8 12a1.5 1.5 0 0 0 1.5 1.4h6.4a1.5 1.5 0 0 0 1.5-1.4L17.5 7" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>',
        'eye'      => '<path d="M2.5 12S6 6 12 6s9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><circle cx="12" cy="12" r="2.6" fill="none" stroke="currentColor" stroke-width="1.7"/>',
        'eye-off'  => '<path d="M4 4l16 16" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><path d="M9.5 5.6A9.6 9.6 0 0 1 12 5.4c6 0 9.5 6.6 9.5 6.6a17 17 0 0 1-3.3 3.9M6.4 7.4C4 9.2 2.5 12 2.5 12S6 18.6 12 18.6c1.2 0 2.3-.2 3.3-.6" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>',
        'check'    => '<path d="M5 12.5l4.5 4.5L19 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'x'        => '<path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
        'plus'     => '<path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
        'print'    => '<path d="M7 9V4h10v5M7 17H5v-6h14v6h-2M7 14h10v6H7z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>',
        'download' => '<path d="M12 4v11M7.5 10.5 12 15l4.5-4.5M5 19h14" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>',
        'upload'   => '<path d="M12 20V9M7.5 13.5 12 9l4.5 4.5M5 5h14" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>',
        'grip'     => '<circle cx="9" cy="7" r="1.4" fill="currentColor"/><circle cx="15" cy="7" r="1.4" fill="currentColor"/><circle cx="9" cy="12" r="1.4" fill="currentColor"/><circle cx="15" cy="12" r="1.4" fill="currentColor"/><circle cx="9" cy="17" r="1.4" fill="currentColor"/><circle cx="15" cy="17" r="1.4" fill="currentColor"/>',
    ];
    $path = $paths[$name] ?? $paths['check'];
    return '<svg class="icon ' . e($class) . '" viewBox="0 0 24 24" aria-hidden="true">' . $path . '</svg>';
}

function stars(float $rating, bool $interactive = false): string
{
    $out = '<span class="stars' . ($interactive ? ' stars-input' : '') . '" title="' . e((string)$rating) . ' out of 5">';
    for ($i = 1; $i <= 5; $i++) {
        $fill = $i <= round($rating) ? 'is-on' : '';
        if ($interactive) {
            $out .= '<button type="button" class="star ' . $fill . '" data-value="' . $i . '" aria-label="' . $i . ' stars">★</button>';
        } else {
            $out .= '<span class="star ' . $fill . '">★</span>';
        }
    }
    return $out . '</span>';
}

function avg_rating(int $productId): array
{
    $sql = SHOPHUB_PORTAL === 'admin'
        ? 'SELECT COUNT(*) c, AVG(rating) a FROM reviews WHERE product_id = ?'
        : 'SELECT COUNT(*) c, AVG(rating) a FROM reviews WHERE product_id = ? AND status = \'approved\'';
    $row = Database::row($sql, [$productId]);
    return ['count' => (int)($row['c'] ?? 0), 'avg' => round((float)($row['a'] ?? 0), 1)];
}

function upload_image(array $file, string $prefix = 'product'): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'message' => 'No file uploaded.', 'skipped' => true];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Upload failed (code ' . (int)$file['error'] . ').'];
    }
    if ($file['size'] > 4 * 1024 * 1024) {
        return ['ok' => false, 'message' => 'Image must be smaller than 4 MB.'];
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = (string)$finfo->file($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'message' => 'Only JPG, PNG, WEBP or GIF images are allowed.'];
    }

    $dir = store_path('uploads/products');
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = $prefix . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        return ['ok' => false, 'message' => 'Could not save the uploaded file.'];
    }
    return ['ok' => true, 'path' => 'uploads/products/' . $name];
}

/* ------------------------------------------------------------------ catalogue */

function search_products(array $opts = []): array
{
    $where  = ['p.is_active = 1'];
    $params = [];

    if (!empty($opts['category'])) {
        $where[]  = 'c.slug = ?';
        $params[] = $opts['category'];
    }
    if (!empty($opts['q'])) {
        $where[]  = '(p.name LIKE ? OR p.brand LIKE ? OR p.short_desc LIKE ? OR p.sku LIKE ?)';
        $like     = '%' . $opts['q'] . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if (!empty($opts['brand'])) {
        $where[]  = 'p.brand = ?';
        $params[] = $opts['brand'];
    }
    if (!empty($opts['min'])) {
        $where[]  = 'COALESCE(NULLIF(p.sale_price,0), p.price) >= ?';
        $params[] = (float)$opts['min'];
    }
    if (!empty($opts['max'])) {
        $where[]  = 'COALESCE(NULLIF(p.sale_price,0), p.price) <= ?';
        $params[] = (float)$opts['max'];
    }
    if (!empty($opts['in_stock'])) {
        $where[] = '(p.stock > 0 OR EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.stock > 0 AND v.is_active = 1))';
    }
    if (!empty($opts['on_sale'])) {
        $where[] = 'p.sale_price > 0 AND p.sale_price < p.price';
    }
    if (!empty($opts['featured'])) {
        $where[] = 'p.is_featured = 1';
    }
    if (!empty($opts['exclude'])) {
        $where[]  = 'p.id <> ?';
        $params[] = (int)$opts['exclude'];
    }

    $order = match ($opts['sort'] ?? 'featured') {
        'price_asc'  => 'COALESCE(NULLIF(p.sale_price,0), p.price) ASC',
        'price_desc' => 'COALESCE(NULLIF(p.sale_price,0), p.price) DESC',
        'rating'     => 'rating_avg DESC, review_count DESC',
        'newest'     => 'p.created_at DESC, p.id DESC',
        'name'       => 'p.name ASC',
        default      => 'p.is_featured DESC, p.id DESC',
    };

    $fromWhere = 'FROM products p
                  LEFT JOIN categories c ON c.id = p.category_id
                  WHERE ' . implode(' AND ', $where);

    $sql = 'SELECT p.*, c.name AS category_name, c.slug AS category_slug,
                   (SELECT COUNT(*) FROM reviews r WHERE r.product_id = p.id AND r.status = \'approved\') AS review_count,
                   (SELECT COALESCE(AVG(r.rating),0) FROM reviews r WHERE r.product_id = p.id AND r.status = \'approved\') AS rating_avg,
                   (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1) AS variant_count
            ' . $fromWhere;

    $count  = (int)Database::value('SELECT COUNT(*) ' . $fromWhere, $params);
    $limit  = (int)($opts['limit'] ?? setting('items_per_page', 12));
    $offset = (int)($opts['offset'] ?? 0);
    $sql   .= ' ORDER BY ' . $order . ' LIMIT ' . $limit . ' OFFSET ' . $offset;

    $rows = Database::all($sql, $params);
    foreach ($rows as &$row) {
        $row['rating_avg'] = round((float)$row['rating_avg'], 1);
    }
    return ['rows' => $rows, 'total' => $count];
}

function brands_list(): array
{
    return array_column(
        Database::all('SELECT DISTINCT brand FROM products WHERE brand <> \'\' AND brand IS NOT NULL ORDER BY brand'),
        'brand'
    );
}

function categories_list(bool $onlyActive = true): array
{
    $sql = 'SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.is_active = 1) AS product_count
            FROM categories c';
    if ($onlyActive) {
        $sql .= ' WHERE c.is_active = 1';
    }
    $sql .= ' ORDER BY c.sort_order, c.name';
    return Database::all($sql);
}

/* ------------------------------------------------------------------- the cart */

/**
 * Cart keys keep products and their variants apart:
 *   p{productId}  → a product with no variants
 *   v{variantId}  → one specific size/colour combination
 */
function cart_key_product(int $productId): string
{
    return 'p' . $productId;
}

function cart_key_variant(int $variantId): string
{
    return 'v' . $variantId;
}

require_once __DIR__ . '/variants.php';

/**
 * Rebuild the cart from the database on every request — prices, stock caps and
 * variant availability are therefore never stale.
 */
function cart_contents(): array
{
    $items = [];
    $cart  = $_SESSION['cart'] ?? [];

    $productKeys = [];
    $variantKeys = [];
    foreach (array_keys($cart) as $key) {
        $key = (string)$key;
        if (str_starts_with($key, 'p')) {
            $productKeys[] = (int)substr($key, 1);
        } elseif (str_starts_with($key, 'v')) {
            $variantKeys[] = (int)substr($key, 1);
        }
    }

    $products = [];
    if ($productKeys) {
        $in = implode(',', array_fill(0, count($productKeys), '?'));
        foreach (Database::all("SELECT * FROM products WHERE id IN ($in) AND is_active = 1", $productKeys) as $row) {
            $products[(int)$row['id']] = $row;
        }
    }

    foreach ($cart as $key => $qty) {
        $key = (string)$key;
        $qty = max(1, (int)$qty);

        if (str_starts_with($key, 'v')) {
            $variant = find_variant((int)substr($key, 1));
            if (!$variant || !(int)$variant['is_active']) {
                unset($_SESSION['cart'][$key]); // variant removed or hidden
                continue;
            }
            $product = $products[(int)$variant['product_id']]
                ?? Database::row('SELECT * FROM products WHERE id = ? AND is_active = 1', [(int)$variant['product_id']]);
            if (!$product) {
                unset($_SESSION['cart'][$key]);
                continue;
            }
            $stock = (int)$variant['stock'];
            if ($stock <= 0) {
                unset($_SESSION['cart'][$key]);
                continue;
            }
            $qty = min($qty, $stock);
            $_SESSION['cart'][$key] = $qty;

            $unit = variant_price($variant, $product);
            $items[] = [
                'key'        => $key,
                'product'    => $product,
                'variant'    => $variant,
                'variant_id' => (int)$variant['id'],
                'label'      => $variant['label'],
                'sku'        => $variant['sku'] !== '' ? $variant['sku'] : (string)$product['sku'],
                'image'      => $variant['image'] !== '' ? $variant['image'] : (string)$product['image'],
                'stock'      => $stock,
                'qty'        => $qty,
                'price'      => $unit,
                'subtotal'   => $unit * $qty,
            ];
            continue;
        }

        $product = $products[(int)substr($key, 1)] ?? null;
        if (!$product) {
            unset($_SESSION['cart'][$key]);
            continue;
        }
        $stock = (int)$product['stock'];
        if ($stock <= 0) {
            unset($_SESSION['cart'][$key]);
            continue;
        }
        $qty = min($qty, $stock);
        $_SESSION['cart'][$key] = $qty;

        $unit = product_price($product);
        $items[] = [
            'key'        => $key,
            'product'    => $product,
            'variant'    => null,
            'variant_id' => null,
            'label'      => '',
            'sku'        => (string)$product['sku'],
            'image'      => (string)$product['image'],
            'stock'      => $stock,
            'qty'        => $qty,
            'price'      => $unit,
            'subtotal'   => $unit * $qty,
        ];
    }

    $subtotal = array_sum(array_column($items, 'subtotal'));
    $coupon   = coupon_current();
    $discount = ($coupon && $subtotal > 0) ? coupon_discount($coupon, $subtotal) : 0.0;

    $freeOver = (float)setting('free_shipping_over', cfg('store.free_shipping_over', 150));
    $shipping = ($subtotal === 0.0 || $subtotal >= $freeOver)
        ? 0.0
        : (float)setting('shipping_flat', cfg('store.shipping_flat', 12));
    $tax      = round(($subtotal - $discount) * ((float)setting('tax_percent', cfg('store.tax_percent', 0)) / 100), 2);

    return [
        'items'    => $items,
        'count'    => array_sum(array_column($items, 'qty')),
        'subtotal' => round($subtotal, 2),
        'discount' => round($discount, 2),
        'shipping' => round($shipping, 2),
        'tax'      => $tax,
        'total'    => round(max(0, $subtotal - $discount) + $shipping + $tax, 2),
        'coupon'   => $coupon,
    ];
}

/* ------------------------------------------------------------------- coupons */

function coupon_current(): ?array
{
    $code = $_SESSION['coupon'] ?? null;
    if (!$code) {
        return null;
    }
    $coupon = coupon_find((string)$code);
    if (!$coupon) {
        unset($_SESSION['coupon']);
    }
    return $coupon;
}

function coupon_find(string $code): ?array
{
    $coupon = Database::row('SELECT * FROM coupons WHERE code = ?', [strtoupper($code)]);
    if (!$coupon || !(int)$coupon['is_active']) {
        return null;
    }
    $today = date('Y-m-d H:i:s');
    if ($coupon['starts_at'] && $coupon['starts_at'] > $today) {
        return null;
    }
    if ($coupon['expires_at'] && $coupon['expires_at'] < $today) {
        return null;
    }
    if ((int)$coupon['max_uses'] > 0 && (int)$coupon['used_count'] >= (int)$coupon['max_uses']) {
        return null;
    }
    return $coupon;
}

function coupon_discount(array $coupon, float $subtotal): float
{
    if ($subtotal < (float)$coupon['min_subtotal']) {
        return 0.0;
    }
    $discount = $coupon['type'] === 'percent'
        ? $subtotal * ((float)$coupon['value'] / 100)
        : (float)$coupon['value'];
    return round(min($discount, $subtotal), 2);
}

/* ----------------------------------------------------------------------- CSV */

/** Stream rows straight to the browser as a CSV download. */
function csv_stream(string $filename, array $headers, iterable $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    // BOM so Excel opens UTF-8 correctly.
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers, ',', '"', '\\');
    foreach ($rows as $row) {
        fputcsv($out, array_map(static function ($value) {
            return is_scalar($value) || $value === null ? (string)$value : json_encode($value);
        }, $row), ',', '"', '\\');
    }
    fclose($out);
    exit;
}

/** Read a CSV file into a header-keyed array (tolerant of BOM/CRLF). */
function csv_read(string $path): array
{
    $handle = fopen($path, 'r');
    if (!$handle) {
        return ['headers' => [], 'rows' => []];
    }

    $headers = null;
    $rows    = [];
    while (($line = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        if ($line === [null]) {
            continue; // blank line
        }
        if ($headers === null) {
            $headers = array_map(static function ($h) {
                $h = (string)$h;
                $h = preg_replace('/^\xEF\xBB\xBF/', '', $h) ?? $h; // strip BOM
                return strtolower(trim(str_replace([' ', '-'], '_', $h)));
            }, $line);
            continue;
        }
        $line = array_pad($line, count($headers), '');
        $rows[] = array_combine($headers, array_slice($line, 0, count($headers)));
    }
    fclose($handle);

    return ['headers' => $headers ?? [], 'rows' => $rows];
}
