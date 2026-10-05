<?php
/**
 * ShopHub — request a password reset link.
 * Never reveals whether an address is registered.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (auth_check()) {
    redirect('account.php');
}

$sent  = false;
$email = '';

if (is_post()) {
    require_csrf();
    $email = (string)post('email');
    // Silently ignored when the address is unknown or belongs to staff.
    send_password_reset_email($email);
    $sent = true;
}

$pageTitle = 'Forgot password';
include __DIR__ . '/includes/header.php';
?>

<section class="section auth-section">
    <div class="container auth-layout">
        <div class="auth-aside">
            <span class="eyebrow">Account recovery</span>
            <h2>Locked out? <em>It happens.</em></h2>
            <ul class="benefit-list">
                <li>Reset links are valid for 2 hours</li>
                <li>Single use — a new request replaces the old link</li>
                <li>We never reveal whether an email is registered</li>
            </ul>
            <blockquote class="testimonial">
                <?= stars(5) ?>
                <p>“Reset took about thirty seconds.”</p>
                <footer><strong>Nadia F.</strong><span class="muted"> — Galle</span></footer>
            </blockquote>
        </div>

        <div class="auth-card">
            <?php if ($sent): ?>
                <h1>Check your inbox</h1>
                <p class="muted">
                    If an account exists for <strong><?= e($email) ?></strong>, we have sent a link to choose a new
                    password. It expires in 2 hours.
                </p>
                <p class="muted small">
                    On a local XAMPP install there is no mail server — the email is written to
                    <code>storage/mail.log</code> instead. Open that file to grab the link.
                </p>
                <a class="btn btn-gold btn-block" href="<?= e(base_url('login.php')) ?>">Back to sign in</a>
            <?php else: ?>
                <h1>Reset your password</h1>
                <p class="muted">Enter the email address on your account and we will send a reset link.</p>

                <form method="post" class="stack-form">
                    <?= csrf_field() ?>
                    <label class="field">
                        <span>Email address</span>
                        <input type="email" name="email" value="<?= e($email) ?>" required autofocus>
                    </label>
                    <button class="btn btn-gold btn-block btn-lg" type="submit">Email me a reset link</button>
                </form>
                <p class="muted small" style="margin-top:1rem">
                    Remembered it? <a href="<?= e(base_url('login.php')) ?>">Sign in</a> ·
                    New here? <a href="<?= e(base_url('register.php')) ?>">Create an account</a>
                </p>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
