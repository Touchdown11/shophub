<?php
/**
 * ShopHub staff portal — sign in
 *
 * Staff credentials are only accepted here. The customer storefront refuses
 * admin accounts, and this page refuses customer accounts.
 */
require_once __DIR__ . '/includes/bootstrap.php';

if (is_admin()) {
    redirect('index.php');
}

$email = '';
$error = '';
$notice = '';

$intended = (string)($_SESSION['intended'] ?? '');
if ($intended !== '' && str_contains($intended, 'expired')) {
    $notice = 'Your session expired. Please sign in again.';
    unset($_SESSION['intended']);
}

if (is_post()) {
    require_csrf();
    $email    = trim((string)post('email'));
    $password = (string)post('password');

    $result = attempt_login($email, $password);
    if ($result['ok'] && is_admin()) {
        redirect('index.php');
    }
    if ($result['ok']) {
        // A customer account got past the customer-side checks — never here.
        unset($_SESSION['user_id']);
        $error = 'That account does not have staff access.';
    } else {
        $error = $result['message'];
    }
}

$pageTitle = 'Staff sign in';
$publicShell = true;
ob_start();
?>
<main class="admin-login">
    <div class="admin-login-card">
        <div class="admin-login-brand">
            <svg viewBox="0 0 48 40" aria-hidden="true"><rect x="6" y="6" width="36" height="28" rx="6" fill="#ffc400"/><text x="24" y="28.5" text-anchor="middle" font-family="Georgia, serif" font-weight="700" font-size="19" fill="#0b0b0d">S</text></svg>
            <span>Shop<b>Hub</b></span>
            <em>staff portal</em>
        </div>

        <h1>Sign in to the dashboard</h1>
        <p class="muted small">Customers sign in on the storefront, not here.</p>

        <?php if ($notice): ?>
            <div class="flash flash-warn"><span><?= e($notice) ?></span></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="flash flash-danger"><span><?= e($error) ?></span></div>
        <?php endif; ?>

        <form method="post" class="stack-form">
            <?= csrf_field() ?>
            <label class="field">
                <span>Work email</span>
                <input type="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus>
            </label>
            <label class="field">
                <span>Password</span>
                <input type="password" name="password" autocomplete="current-password" required>
            </label>
            <button class="btn btn-gold btn-block btn-lg" type="submit">Sign in</button>
        </form>

        <div class="admin-login-foot">
            <a href="<?= e(store_url('index.php')) ?>">← Back to the storefront</a>
            <span class="muted small">Sessions end after <?= (int)cfg('app.admin_session_minutes', 120) ?> minutes idle.</span>
        </div>

        <details class="admin-demo-hint">
            <summary>Demo credentials</summary>
            <p><code>admin@shophub.test</code> / <code>admin123</code></p>
        </details>
    </div>
</main>
<?php
$content = ob_get_clean();
include __DIR__ . '/includes/header.php';
