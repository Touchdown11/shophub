<?php
/**
 * ShopHub — set a new password using a reset token.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$token = (string)query('token');
$user  = $token !== '' ? auth_token_valid($token, TOKEN_PASSWORD_RESET) : null;
$errors = [];

if ($user && is_post()) {
    require_csrf();
    $password = (string)post('password');
    $confirm  = (string)post('password_confirm');

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    } else {
        // Consume (burns the token) then set the new hash.
        $consumed = auth_token_consume($token, TOKEN_PASSWORD_RESET);
        if ($consumed) {
            Database::run(
                'UPDATE users SET password_hash = ? WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), (int)$consumed['id']]
            );
            // Any other outstanding reset/verify links for this user die with it.
            Database::run('DELETE FROM auth_tokens WHERE user_id = ?', [(int)$consumed['id']]);
            flash('success', 'Your password has been changed. You can sign in now.');
            redirect('login.php?reset=1');
        }
        $errors[] = 'That reset link has already been used. Please request a new one.';
        $user = null;
    }
}

$pageTitle = 'Choose a new password';
include __DIR__ . '/includes/header.php';
?>

<section class="section auth-section">
    <div class="container narrow">
        <div class="auth-card">
            <?php if (!$user): ?>
                <h1>This reset link is not valid</h1>
                <p class="muted">Links expire after 2 hours and can only be used once.</p>
                <?php foreach ($errors as $err): ?>
                    <div class="flash flash-danger"><span><?= e($err) ?></span></div>
                <?php endforeach; ?>
                <a class="btn btn-gold btn-block" href="<?= e(base_url('forgot-password.php')) ?>">Request a new link</a>
            <?php else: ?>
                <h1>Choose a new password</h1>
                <p class="muted">Setting a new password for <strong><?= e((string)$user['email']) ?></strong>.</p>

                <?php foreach ($errors as $err): ?>
                    <div class="flash flash-danger"><span><?= e($err) ?></span></div>
                <?php endforeach; ?>

                <form method="post" class="stack-form">
                    <?= csrf_field() ?>
                    <label class="field">
                        <span>New password</span>
                        <input type="password" name="password" minlength="8" autocomplete="new-password" required autofocus>
                    </label>
                    <label class="field">
                        <span>Confirm new password</span>
                        <input type="password" name="password_confirm" minlength="8" autocomplete="new-password" required>
                    </label>
                    <p class="muted small">Minimum 8 characters. Use a mix of letters and numbers.</p>
                    <button class="btn btn-gold btn-block btn-lg" type="submit">Save new password</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
