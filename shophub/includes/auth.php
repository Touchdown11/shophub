<?php
/**
 * ShopHub — authentication
 *
 * The customer store and the staff portal are separate applications with
 * separate session cookies, so this file behaves according to SHOPHUB_PORTAL:
 *
 *   store  → customers only. Staff credentials are refused (no admin UI here).
 *   admin  → staff only. Customer credentials are refused.
 *
 * Accounts also carry an email-verification state, and password resets use
 * single-use hashed tokens (see the bottom of this file).
 */
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mailer.php';

const TOKEN_VERIFY_EMAIL   = 'verify_email';
const TOKEN_PASSWORD_RESET = 'password_reset';

/* ---------------------------------------------------------------- session -- */

function auth_user(): ?array
{
    static $cache = [];

    $id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    if (array_key_exists($id, $cache)) {
        return $cache[$id];
    }
    if ($id === 0) {
        return $cache[0] = null;
    }

    $row = Database::row('SELECT * FROM users WHERE id = ? AND status = \'active\'', [$id]);
    if (!$row) {
        unset($_SESSION['user_id']);
        return $cache[$id] = null;
    }
    unset($row['password_hash']);
    return $cache[$id] = $row;
}

function auth_check(): bool
{
    return auth_user() !== null;
}

function auth_id(): ?int
{
    $user = auth_user();
    return $user ? (int)$user['id'] : null;
}

function is_admin(): bool
{
    $user = auth_user();
    return $user !== null && $user['role'] === 'admin';
}

function is_staff_portal(): bool
{
    return SHOPHUB_PORTAL === 'admin';
}

/** Account is signed in AND its email has been confirmed. */
function auth_verified(): bool
{
    $user = auth_user();
    return $user !== null && !empty($user['email_verified_at']);
}

/** True when the store is configured to block unverified shoppers. */
function verification_required(): bool
{
    return setting_bool('require_email_verification', (bool)cfg('features.require_email_verification', false))
        && auth_check() && !auth_verified();
}

function require_login(string $redirectBack = ''): void
{
    if (!auth_check()) {
        $_SESSION['intended'] = $redirectBack ?: ($_SERVER['REQUEST_URI'] ?? store_url('index.php'));
        flash('warn', 'Please sign in to continue.');
        redirect('login.php');
    }
}

/** Guard for the staff portal. */
function require_admin(): void
{
    if (!is_admin()) {
        flash('warn', 'Administrator access required.');
        redirect('login.php');
    }

    $minutes = (int)cfg('app.admin_session_minutes', 120);
    $last    = (int)($_SESSION['admin_last_active'] ?? 0);
    if ($minutes > 0 && $last > 0 && (time() - $last) > $minutes * 60) {
        unset($_SESSION['user_id'], $_SESSION['admin_last_active']);
        flash('warn', 'Your session expired after ' . $minutes . ' minutes of inactivity. Please sign in again.');
        redirect('login.php');
    }
    $_SESSION['admin_last_active'] = time();
}

/* -------------------------------------------------------------------- login -- */

/* ---------------------------------------------------- failed-sign-in throttle */

const LOGIN_LOCK_SECONDS = 300;

/** Failed attempts for one account in the current portal: [attempts, locked_until]. */
function login_attempt_state(string $identifier): array
{
    $row = Database::row(
        'SELECT attempts, locked_until FROM login_attempts WHERE scope = ? AND identifier = ?',
        [SHOPHUB_PORTAL, strtolower(trim($identifier))]
    ) ?? [];
    return [
        'attempts'     => (int)($row['attempts'] ?? 0),
        'locked_until' => !empty($row['locked_until']) ? (int)strtotime((string)$row['locked_until']) : 0,
    ];
}

function login_attempt_record(string $identifier, int $limit): int
{
    $identifier = strtolower(trim($identifier));
    $state      = login_attempt_state($identifier);
    $window     = $state['attempts'];

    // Start a fresh count once the lock has elapsed and the window has passed.
    if ($state['locked_until'] > 0 && $state['locked_until'] < time()) {
        $window = 0;
    }

    $attempts = $window + 1;
    $locked   = $attempts >= $limit ? date('Y-m-d H:i:s', time() + LOGIN_LOCK_SECONDS) : null;
    $exists   = Database::row(
        'SELECT id FROM login_attempts WHERE scope = ? AND identifier = ?',
        [SHOPHUB_PORTAL, $identifier]
    );
    $data = ['attempts' => $attempts, 'locked_until' => $locked, 'last_attempt' => now()];

    if ($exists) {
        Database::update('login_attempts', $data, 'id = ' . (int)$exists['id']);
    } else {
        Database::insert('login_attempts', $data + ['scope' => SHOPHUB_PORTAL, 'identifier' => $identifier]);
    }

    return $attempts;
}

function login_attempt_clear(string $identifier): void
{
    Database::run(
        'DELETE FROM login_attempts WHERE scope = ? AND identifier = ?',
        [SHOPHUB_PORTAL, strtolower(trim($identifier))]
    );
}

/**
 * Authenticate within the portal that is running.
 * @return array{ok: bool, code: string, message: string}
 */
function attempt_login(string $email, string $password): array
{
    $staff    = is_staff_portal();
    $throttle = 'login_attempts_' . SHOPHUB_PORTAL;
    $limit    = $staff ? 5 : 6;
    $attempts = $_SESSION[$throttle] ?? ['count' => 0, 'time' => 0];
    $state    = login_attempt_state($email);

    if ($state['locked_until'] > time()) {
        return [
            'ok'      => false,
            'code'    => 'locked',
            'message' => 'Too many failed attempts. Please wait '
                . max(1, (int)ceil(($state['locked_until'] - time()) / 60)) . ' minute(s) and try again.',
        ];
    }

    if ($attempts['count'] >= $limit && (time() - (int)$attempts['time']) < LOGIN_LOCK_SECONDS) {
        return [
            'ok'      => false,
            'code'    => 'locked',
            'message' => 'Too many failed attempts. Please wait 5 minutes and try again.',
        ];
    }
    // (the per-account lock above reports its own remaining time)

    $fail = static function (string $code, string $message) use ($throttle, $attempts, $email, $limit): array {
        if (in_array($code, ['invalid', 'wrong_portal'], true)) {
            $_SESSION[$throttle] = ['count' => (int)$attempts['count'] + 1, 'time' => time()];
            $count = login_attempt_record($email, $limit);
            if ($count >= $limit) {
                return ['ok' => false, 'code' => 'locked', 'message' => 'Too many failed attempts. Please wait '
                    . (int)ceil(LOGIN_LOCK_SECONDS / 60) . ' minutes and try again.'];
            }
        }
        return ['ok' => false, 'code' => $code, 'message' => $message];
    };

    $user = Database::row('SELECT * FROM users WHERE LOWER(email) = ?', [strtolower($email)]);
    if (!$user || !password_verify($password, (string)$user['password_hash'])) {
        return $fail('invalid', 'Those credentials do not match our records.');
    }
    if ($user['status'] !== 'active') {
        return ['ok' => false, 'code' => 'suspended', 'message' => 'This account has been suspended. Please contact support.'];
    }

    // Portal separation: each app only accepts its own audience.
    if ($staff && $user['role'] !== 'admin') {
        return $fail('wrong_portal', 'That account does not have administrator access.');
    }
    if (!$staff && $user['role'] === 'admin') {
        return $fail('wrong_portal', 'Administrator accounts cannot sign in here. Please use the staff portal.');
    }

    unset($_SESSION[$throttle]);
    login_attempt_clear($email);
    session_regenerate_id(true);
    $_SESSION['user_id']            = (int)$user['id'];
    $_SESSION['admin_last_active']  = time();
    Database::run('UPDATE users SET last_login = ? WHERE id = ?', [now(), $user['id']]);

    return ['ok' => true, 'code' => 'ok', 'message' => 'Welcome back, ' . $user['name'] . '!'];
}

/* ----------------------------------------------------------------- register -- */

function register_user(string $name, string $email, string $password, string $phone = ''): array
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Please enter a valid email address.'];
    }
    if (strlen($password) < 8) {
        return [false, 'Password must be at least 8 characters.'];
    }
    if (Database::value('SELECT COUNT(*) FROM users WHERE LOWER(email) = ?', [$email])) {
        return [false, 'An account with that email already exists.'];
    }

    $id = Database::insert('users', [
        'name'          => $name,
        'email'         => $email,
        'phone'         => $phone,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role'          => 'customer',
        'status'        => 'active',
        'created_at'    => now(),
    ]);

    session_regenerate_id(true);
    $_SESSION['user_id'] = $id;

    // Guest wishlist rows follow the customer into their account.
    if (!empty($_SESSION['guest_wishlist'])) {
        foreach (array_unique($_SESSION['guest_wishlist']) as $pid) {
            wishlist_add($id, (int)$pid);
        }
        unset($_SESSION['guest_wishlist']);
    }

    send_verification_email($id);
    send_template($email, 'welcome', ['name' => $name], $name);

    return [true, 'Your account is ready'];
}

/* ----------------------------------------------------------------- wishlist -- */

function wishlist_ids(): array
{
    if (auth_check()) {
        return array_map('intval', array_column(
            Database::all('SELECT product_id FROM wishlists WHERE user_id = ?', [auth_id()]),
            'product_id'
        ));
    }
    return array_map('intval', $_SESSION['guest_wishlist'] ?? []);
}

function wishlist_add(int $userId, int $productId): void
{
    $exists = Database::value(
        'SELECT COUNT(*) FROM wishlists WHERE user_id = ? AND product_id = ?',
        [$userId, $productId]
    );
    if (!$exists) {
        Database::insert('wishlists', [
            'user_id'    => $userId,
            'product_id' => $productId,
            'created_at' => now(),
        ]);
    }
}

/* ------------------------------------------------------------------- tokens -- */

/**
 * Create a single-use token and return the RAW value (only ever emailed, never
 * stored — the database keeps a SHA-256 hash).
 */
function auth_token_create(int $userId, string $type, int $ttlSeconds): string
{
    // One live token per type per user.
    Database::run(
        'DELETE FROM auth_tokens WHERE user_id = ? AND type = ? AND used_at IS NULL',
        [$userId, $type]
    );
    // Opportunistic cleanup of anything expired.
    Database::run('DELETE FROM auth_tokens WHERE expires_at < ?', [now()]);

    $raw = bin2hex(random_bytes(32));
    Database::insert('auth_tokens', [
        'user_id'    => $userId,
        'type'       => $type,
        'token_hash' => hash('sha256', $raw),
        'expires_at' => date('Y-m-d H:i:s', time() + $ttlSeconds),
        'ip'         => mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        'created_at' => now(),
    ]);
    return $raw;
}

/** Validate + burn a token. Returns the matching user row, or null. */
function auth_token_consume(string $raw, string $type): ?array
{
    $raw = trim($raw);
    if ($raw === '' || !preg_match('/^[a-f0-9]{64}$/i', $raw)) {
        return null;
    }

    $row = Database::row(
        'SELECT * FROM auth_tokens WHERE token_hash = ? AND type = ?',
        [hash('sha256', $raw), $type]
    );
    if (!$row || $row['used_at'] !== null || $row['expires_at'] < now()) {
        return null;
    }

    Database::run('UPDATE auth_tokens SET used_at = ? WHERE id = ?', [now(), (int)$row['id']]);
    return Database::row('SELECT * FROM users WHERE id = ?', [(int)$row['user_id']]);
}

function auth_token_valid(string $raw, string $type): ?array
{
    $raw = trim($raw);
    if ($raw === '' || !preg_match('/^[a-f0-9]{64}$/i', $raw)) {
        return null;
    }
    $row = Database::row(
        'SELECT * FROM auth_tokens WHERE token_hash = ? AND type = ?',
        [hash('sha256', $raw), $type]
    );
    if (!$row || $row['used_at'] !== null || $row['expires_at'] < now()) {
        return null;
    }
    return Database::row('SELECT * FROM users WHERE id = ?', [(int)$row['user_id']]);
}

/* ------------------------------------------------------- verification emails -- */

function send_verification_email(int $userId): bool
{
    $user = Database::row('SELECT * FROM users WHERE id = ?', [$userId]);
    if (!$user || !empty($user['email_verified_at'])) {
        return false;
    }
    $token = auth_token_create($userId, TOKEN_VERIFY_EMAIL, 48 * 3600);
    $result = send_template(
        (string)$user['email'],
        'verify_email',
        ['verify_url' => store_link('verify-email.php?token=' . $token)],
        (string)$user['name']
    );
    return $result['ok'];
}

/** Always reports success to the visitor (no account enumeration). */
function send_password_reset_email(string $email): bool
{
    $user = Database::row('SELECT * FROM users WHERE LOWER(email) = ?', [strtolower($email)]);
    if (!$user || $user['status'] !== 'active' || $user['role'] === 'admin') {
        return false;
    }
    $token = auth_token_create((int)$user['id'], TOKEN_PASSWORD_RESET, 2 * 3600);
    send_template(
        (string)$user['email'],
        'password_reset',
        ['reset_url' => store_link('reset-password.php?token=' . $token)],
        (string)$user['name']
    );
    return true;
}
