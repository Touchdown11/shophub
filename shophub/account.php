<?php
/**
 * ShopHub — customer account dashboard
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/orders.php';
require_once __DIR__ . '/includes/partials.php';

require_login('account.php');

$user = auth_user();
$tab  = (string)query('tab', 'overview');

/* ------------------------------------------------------------------ actions -- */
if (is_post()) {
    require_csrf();
    $action = (string)post('action');

    if ($action === 'profile') {
        $name  = (string)post('name');
        $phone = (string)post('phone');
        if ($name === '') {
            flash('danger', 'Name cannot be empty.');
        } else {
            Database::run('UPDATE users SET name = ?, phone = ? WHERE id = ?', [$name, $phone, (int)$user['id']]);
            flash('success', 'Profile updated.');
        }
    } elseif ($action === 'password') {
        $current = (string)post('current_password');
        $new     = (string)post('new_password');
        $row     = Database::row('SELECT password_hash FROM users WHERE id = ?', [(int)$user['id']]);
        if (!password_verify($current, (string)$row['password_hash'])) {
            flash('danger', 'Your current password is incorrect.');
        } elseif (strlen($new) < 8) {
            flash('danger', 'New password must be at least 8 characters.');
        } else {
            Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), (int)$user['id']]);
            flash('success', 'Password changed.');
        }
    } elseif ($action === 'address') {
        $fields = ['label', 'full_name', 'phone', 'address', 'city', 'postal_code', 'country'];
        $data   = ['user_id' => (int)$user['id'], 'created_at' => now(), 'is_default' => 0];
        foreach ($fields as $f) {
            $data[$f] = (string)post($f);
        }
        if ($data['full_name'] === '' || $data['address'] === '') {
            flash('danger', 'Name and street address are required.');
        } else {
            if (!Database::value('SELECT COUNT(*) FROM addresses WHERE user_id = ?', [(int)$user['id']])) {
                $data['is_default'] = 1;
            }
            Database::insert('addresses', $data);
            flash('success', 'Address saved.');
        }
    } elseif ($action === 'resend_verification') {
        if (!empty($user['email_verified_at'])) {
            flash('success', 'Your email address is already confirmed.');
        } elseif (send_verification_email((int)$user['id'])) {
            flash('success', 'Verification email sent to ' . $user['email'] . ' — the link stays valid for 48 hours.');
        } else {
            flash('danger', 'We could not send that email. Please try again in a moment.');
        }
    } elseif ($action === 'delete_address') {
        Database::run('DELETE FROM addresses WHERE id = ? AND user_id = ?', [(int)post('address_id'), (int)$user['id']]);
        flash('success', 'Address removed.');
    }
    redirect('account.php?tab=' . urlencode($tab));
}

$addresses = Database::all('SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC', [(int)$user['id']]);
$orders    = Database::all('SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC', [(int)$user['id']]);
$wishCount = count(wishlist_ids());

$pageTitle = 'My account';
include __DIR__ . '/includes/header.php';
?>

<section class="page-head">
    <div class="container">
        <nav class="breadcrumb"><a href="<?= e(base_url('index.php')) ?>">Home</a><span>/</span><em>Account</em></nav>
        <h1>Hello, <?= e(explode(' ', (string)$user['name'])[0]) ?></h1>
        <p class="muted">Member since <?= e(nice_date((string)$user['created_at'], 'F Y')) ?> · <?= count($orders) ?> order<?= count($orders) === 1 ? '' : 's' ?> · <?= $wishCount ?> saved item<?= $wishCount === 1 ? '' : 's' ?></p>
    </div>
</section>

<div class="container">
    <?php if (empty($user['email_verified_at']) && verification_required()): ?>
        <div class="verify-banner">
            <div>
                <strong>Confirm your email address</strong>
                <p class="muted small">
                    We sent a link to <strong><?= e($user['email']) ?></strong>.
                    Confirming it unlocks checkout, reviews and order emails.
                    <?php if ((string)setting('mail_driver', 'log') === 'log'): ?>
                        <br>Running the demo? Open <code>storage/mail.log</code> and follow the newest confirmation link.
                    <?php endif; ?>
                </p>
            </div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="resend_verification">
                <button class="btn btn-gold" type="submit">Resend link</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<div class="container account-layout">
    <nav class="account-nav" aria-label="Account sections">
        <a class="<?= $tab === 'overview' ? 'is-active' : '' ?>" href="?tab=overview">Overview</a>
        <a class="<?= $tab === 'orders' ? 'is-active' : '' ?>" href="?tab=orders">Orders</a>
        <a class="<?= $tab === 'wishlist' ? 'is-active' : '' ?>" href="?tab=wishlist">Wishlist</a>
        <a class="<?= $tab === 'addresses' ? 'is-active' : '' ?>" href="?tab=addresses">Addresses</a>
        <a class="<?= $tab === 'settings' ? 'is-active' : '' ?>" href="?tab=settings">Settings</a>
        <a class="danger-link" href="<?= e(base_url('logout.php')) ?>">Sign out</a>
    </nav>

    <section class="account-main">
        <?php if ($tab === 'orders'): ?>
            <h2>Order history</h2>
            <?php if (!$orders): ?>
                <?= empty_state('No orders yet', 'When you place an order it will show up here.', 'Start shopping', base_url('shop.php')) ?>
            <?php endif; ?>
            <?php foreach ($orders as $order): ?>
                <details class="order-row">
                    <summary>
                        <div>
                            <strong><?= e((string)$order['reference']) ?></strong>
                            <span class="muted small"><?= e(nice_date((string)$order['created_at'])) ?> · <?= count(order_items((int)$order['id'])) ?> item(s)</span>
                        </div>
                        <div class="order-row-right">
                            <?= status_badge((string)$order['status']) ?>
                            <span class="price-now"><?= e(money((float)$order['total'], (string)$order['currency'])) ?></span>
                            <span class="chev">▾</span>
                        </div>
                    </summary>
                    <div class="order-row-body">
                        <?= order_confirmation_html($order, true) ?>
                    </div>
                </details>
            <?php endforeach; ?>

        <?php elseif ($tab === 'wishlist'): ?>
            <h2>Wishlist</h2>
            <?php
            $ids = wishlist_ids();
            if (!$ids) {
                echo empty_state('Nothing saved yet', 'Tap the heart on any product to save it for later.', 'Browse products', base_url('shop.php'));
            } else {
                $in  = implode(',', array_fill(0, count($ids), '?'));
                $rows = Database::all("SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id IN ($in)", $ids);
                echo '<div class="product-grid">';
                foreach ($rows as $p) {
                    echo product_card($p, $ids);
                }
                echo '</div>';
            }
            ?>

        <?php elseif ($tab === 'addresses'): ?>
            <h2>Delivery addresses</h2>
            <div class="address-grid">
                <?php foreach ($addresses as $a): ?>
                    <article class="address-card">
                        <header>
                            <strong><?= e($a['full_name']) ?></strong>
                            <?php if ((int)$a['is_default']): ?><span class="badge badge-gold">Default</span><?php endif; ?>
                        </header>
                        <p>
                            <?= e($a['address']) ?><br>
                            <?= e($a['city']) ?><?= $a['postal_code'] ? ', ' . e($a['postal_code']) : '' ?><br>
                            <?= e($a['country']) ?><br>
                            <?= e($a['phone']) ?>
                        </p>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_address">
                            <input type="hidden" name="address_id" value="<?= (int)$a['id'] ?>">
                            <button class="link-danger" type="submit">Delete</button>
                        </form>
                    </article>
                <?php endforeach; ?>

                <form method="post" class="address-card address-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="address">
                    <h3>Add an address</h3>
                    <div class="form-grid">
                        <label class="field"><span>Recipient</span><input type="text" name="full_name" required></label>
                        <label class="field"><span>Phone</span><input type="tel" name="phone"></label>
                        <label class="field field-wide"><span>Street address</span><input type="text" name="address" required></label>
                        <label class="field"><span>City</span><input type="text" name="city"></label>
                        <label class="field"><span>Postal code</span><input type="text" name="postal_code"></label>
                        <label class="field"><span>Country</span><input type="text" name="country" value="Sri Lanka"></label>
                    </div>
                    <button class="btn btn-gold" type="submit">Save address</button>
                </form>
            </div>

        <?php elseif ($tab === 'settings'): ?>
            <h2>Profile settings</h2>
            <form method="post" class="panel stack-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="profile">
                <div class="form-grid">
                    <label class="field"><span>Full name</span><input type="text" name="name" value="<?= e($user['name']) ?>" required></label>
                    <label class="field"><span>Phone</span><input type="tel" name="phone" value="<?= e($user['phone']) ?>"></label>
                    <label class="field">
                        <span>Email <?= empty($user['email_verified_at'])
                            ? '<em class="badge badge-warn">Unconfirmed</em>'
                            : '<em class="badge badge-ok">Confirmed</em>' ?></span>
                        <input type="email" value="<?= e($user['email']) ?>" disabled>
                    </label>
                </div>
                <button class="btn btn-gold" type="submit">Save changes</button>
            </form>

            <h2>Change password</h2>
            <form method="post" class="panel stack-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="password">
                <div class="form-grid">
                    <label class="field"><span>Current password</span><input type="password" name="current_password" required></label>
                    <label class="field"><span>New password</span><input type="password" name="new_password" minlength="8" required></label>
                </div>
                <button class="btn btn-outline" type="submit">Update password</button>
            </form>

        <?php else: ?>
            <h2>Overview</h2>
            <div class="stat-cards">
                <div class="stat-card"><span class="muted">Total orders</span><strong><?= count($orders) ?></strong></div>
                <div class="stat-card"><span class="muted">Lifetime spend</span><strong><?= e(money(array_sum(array_map(static fn($o) => (float)$o['total'], $orders)))) ?></strong></div>
                <div class="stat-card"><span class="muted">Wishlist items</span><strong><?= $wishCount ?></strong></div>
                <div class="stat-card"><span class="muted">Saved addresses</span><strong><?= count($addresses) ?></strong></div>
            </div>

            <?php if ($orders): $latest = $orders[0]; ?>
                <h2>Latest order</h2>
                <?= order_confirmation_html($latest, true) ?>
            <?php else: ?>
                <?= empty_state('Your account is ready', 'Add a first order and we will keep everything tracked here.', 'Shop the collection', base_url('shop.php')) ?>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
