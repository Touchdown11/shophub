<?php
/**
 * ShopHub — confirm an email address from the link we mailed.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$token = (string)query('token');
$user  = $token !== '' ? auth_token_consume($token, TOKEN_VERIFY_EMAIL) : null;

if ($user) {
    if (empty($user['email_verified_at'])) {
        Database::run('UPDATE users SET email_verified_at = ? WHERE id = ?', [now(), (int)$user['id']]);
    }
    // Sign the customer straight in — the link proves they own the inbox.
    if (!auth_check()) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
    }
    flash('success', 'Thank you — your email address is confirmed.');
    redirect('account.php?verified=1');
}

$pageTitle = 'Email confirmation';
include __DIR__ . '/includes/header.php';
?>

<section class="section">
    <div class="container narrow">
        <div class="panel center-panel">
            <svg class="state-icon" viewBox="0 0 48 48" aria-hidden="true">
                <circle cx="24" cy="24" r="21" fill="none" stroke="currentColor" stroke-width="2.5"/>
                <path d="M16 24h16M24 16v16" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
            </svg>
            <h1>This link has expired</h1>
            <p class="muted">
                Confirmation links are valid for 48 hours and can only be used once.
                Sign in and we will send a fresh one, or create your account again.
            </p>
            <div class="success-actions">
                <a class="btn btn-gold" href="<?= e(base_url('login.php')) ?>">Sign in to resend</a>
                <a class="btn btn-outline" href="<?= e(base_url('register.php')) ?>">Create an account</a>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
