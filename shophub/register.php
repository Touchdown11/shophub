<?php
/**
 * ShopHub — create a customer account
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (auth_check()) {
    redirect('account.php');
}

$errors = [];
$values = ['name' => '', 'email' => '', 'phone' => ''];

if (is_post()) {
    require_csrf();
    $values['name']  = (string)post('name');
    $values['email'] = (string)post('email');
    $values['phone'] = (string)post('phone');
    $password        = (string)post('password');
    $confirm         = (string)post('password_confirm');

    if ($values['name'] === '') {
        $errors[] = 'Please tell us your name.';
    }
    if ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }
    if (!$errors) {
        [$ok, $message] = register_user($values['name'], $values['email'], $password, $values['phone']);
        if ($ok) {
            $_SESSION['just_registered'] = 1;
            flash('success', $message . '. We have emailed you a verification link — plus a 15% welcome code.');
            redirect('account.php');
        }
        $errors[] = $message;
    }
}

$pageTitle = 'Create account';
include __DIR__ . '/includes/header.php';
?>

<section class="section auth-section">
    <div class="container auth-layout">
        <div class="auth-aside">
            <span class="eyebrow">Join ShopHub</span>
            <h2>Create an account, unlock <em>15% off</em></h2>
            <ul class="benefit-list">
                <li>Instant <strong>WELCOME15</strong> coupon</li>
                <li>Order tracking and printable invoices</li>
                <li>Faster checkout with saved addresses</li>
                <li>Wishlist and stock alerts</li>
            </ul>
            <blockquote class="testimonial">
                <?= stars(5) ?>
                <p>“Signing up paid for itself on the first order.”</p>
                <footer><strong>Dinuka R.</strong><span class="muted"> — Negombo</span></footer>
            </blockquote>
        </div>

        <div class="auth-card">
            <h1>Create your account</h1>
            <p class="muted">Already a member? <a href="<?= e(base_url('login.php')) ?>">Sign in</a></p>

            <?php foreach ($errors as $err): ?>
                <div class="flash flash-danger"><span><?= e($err) ?></span></div>
            <?php endforeach; ?>

            <form method="post" class="stack-form">
                <?= csrf_field() ?>
                <label class="field">
                    <span>Full name</span>
                    <input type="text" name="name" value="<?= e($values['name']) ?>" required>
                </label>
                <label class="field">
                    <span>Email address</span>
                    <input type="email" name="email" value="<?= e($values['email']) ?>" required>
                </label>
                <label class="field">
                    <span>Phone (optional)</span>
                    <input type="tel" name="phone" value="<?= e($values['phone']) ?>" placeholder="07X XXX XXXX">
                </label>
                <div class="form-grid">
                    <label class="field">
                        <span>Password</span>
                        <input type="password" name="password" minlength="8" autocomplete="new-password" required>
                    </label>
                    <label class="field">
                        <span>Confirm password</span>
                        <input type="password" name="password_confirm" minlength="8" autocomplete="new-password" required>
                    </label>
                </div>
                <p class="muted small">Minimum 8 characters. We will email you a link to confirm your address.</p>
                <button class="btn btn-gold btn-block btn-lg" type="submit">Create account</button>
            </form>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
