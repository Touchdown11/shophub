<?php
/**
 * ShopHub — customer sign in (storefront portal)
 *
 * Customers only. The staff portal is a separate application and is not
 * referenced from anywhere in this store.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (auth_check()) {
    redirect('account.php');
}

$errors = [];
$email  = '';

if (is_post()) {
    require_csrf();
    $email  = (string)post('email');
    $result = attempt_login($email, (string)post('password'));

    if ($result['ok']) {
        flash('success', $result['message']);
        $intended = $_SESSION['intended'] ?? null;
        unset($_SESSION['intended']);
        redirect($intended && !str_contains((string)$intended, 'login.php') ? (string)$intended : 'account.php');
    }
    $errors[] = $result['message'];
}

$pageTitle = 'Sign in';
include __DIR__ . '/includes/header.php';
?>

<section class="section auth-section">
    <div class="container auth-layout">
        <div class="auth-aside">
            <span class="eyebrow">Members save more</span>
            <h2>Welcome back to <em>ShopHub</em></h2>
            <ul class="benefit-list">
                <li>Track orders and reorder in one tap</li>
                <li>Members-only coupons and early drops</li>
                <li>Saved addresses for faster checkout</li>
                <li>Wishlist synced across your devices</li>
            </ul>
        </div>

        <div class="auth-card">
            <h1>Sign in</h1>
            <p class="muted">New here? <a href="<?= e(base_url('register.php')) ?>">Create an account</a></p>

            <?php foreach ($errors as $err): ?>
                <div class="flash flash-danger"><span><?= e($err) ?></span></div>
            <?php endforeach; ?>

            <?php if (query('reset') === '1'): ?>
                <div class="flash flash-success"><span>Your password has been updated. Please sign in.</span></div>
            <?php endif; ?>

            <form method="post" class="stack-form">
                <?= csrf_field() ?>
                <label class="field">
                    <span>Email address</span>
                    <input type="email" name="email" value="<?= e($email) ?>" autocomplete="email" required>
                </label>
                <label class="field">
                    <span>Password</span>
                    <input type="password" name="password" autocomplete="current-password" required>
                </label>
                <div class="row-between">
                    <label class="check"><input type="checkbox" name="remember" value="1"> <span>Keep me signed in</span></label>
                    <a class="link-more" href="<?= e(base_url('forgot-password.php')) ?>">Forgot password?</a>
                </div>
                <button class="btn btn-gold btn-block btn-lg" type="submit">Sign in</button>
            </form>

            <div class="auth-hint">
                <p class="muted small"><strong>Demo customer accounts</strong> created by the installer:</p>
                <ul class="muted small">
                    <li><code>amara@example.com</code> / <code>password123</code></li>
                    <li><code>ruwan@example.com</code> / <code>password123</code></li>
                </ul>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
