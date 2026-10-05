<?php
/**
 * ShopHub staff portal — settings
 *
 * Everything an operator can change without touching code: store basics,
 * checkout behaviour, the payment gateways (COD / bank transfer / Stripe /
 * PayHere), transactional mail and the account feature switches.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$pageTitle = 'Settings';

/* Every editable setting, grouped for the UI. */
$groups = [
    'store' => [
        'label' => 'Store',
        'blurb' => 'Identity, currency and the announcement bar.',
        'fields' => [
            'store_name'         => ['Store name', 'text'],
            'currency'           => ['Primary currency code', 'text', 'Prices are stored in this currency.'],
            'currency_symbol'    => ['Primary currency symbol', 'text'],
            'secondary_rate'     => ['Exchange rate: 1 primary = ? secondary', 'text'],
            'secondary_symbol'   => ['Secondary currency symbol', 'text'],
            'announcement'       => ['Top announcement bar text', 'text', 'wide'],
        ],
    ],
    'checkout' => [
        'label' => 'Checkout & delivery',
        'blurb' => 'Shipping fees, tax and catalogue behaviour.',
        'fields' => [
            'shipping_flat'      => ['Flat delivery fee', 'text'],
            'free_shipping_over' => ['Free delivery threshold', 'text'],
            'tax_percent'        => ['Tax percentage', 'text'],
            'items_per_page'     => ['Products per page', 'text'],
        ],
    ],
    'payments' => [
        'label' => 'Payment methods',
        'blurb' => 'Toggle what shoppers can choose at checkout. Gateways also need live keys below.',
        'fields' => [
            'payment_cod_enabled'           => ['Cash on delivery', 'bool'],
            'payment_bank_transfer_enabled' => ['Bank transfer', 'bool'],
            'payment_stripe_enabled'        => ['Stripe (card)', 'bool'],
            'payment_payhere_enabled'       => ['PayHere (Sri Lanka)', 'bool'],
            'bank_name'                     => ['Bank name', 'text'],
            'bank_account_name'             => ['Account name', 'text'],
            'bank_account_number'           => ['Account number', 'text'],
            'bank_branch'                   => ['Branch', 'text'],
            'bank_instructions'             => ['Payment instructions shown after checkout', 'textarea', 'wide'],
        ],
    ],
    'stripe' => [
        'label' => 'Stripe keys',
        'blurb' => 'Dashboard → Developers → API keys. Test keys start with sk_test_.',
        'fields' => [
            'stripe_publishable_key' => ['Publishable key', 'text'],
            'stripe_secret_key'      => ['Secret key', 'secret'],
            'stripe_webhook_secret'  => ['Webhook signing secret (optional)', 'secret'],
        ],
    ],
    'payhere' => [
        'label' => 'PayHere keys',
        'blurb' => 'Dashboard → Domains & Credentials. Keep sandbox mode until you have gone live.',
        'fields' => [
            'payhere_merchant_id'     => ['Merchant ID', 'text'],
            'payhere_merchant_secret' => ['Merchant secret', 'secret'],
            'payhere_mode'            => ['Mode', 'select', '', ['sandbox' => 'Sandbox', 'live' => 'Live']],
            'payhere_currency'        => ['Charge currency (blank = order currency)', 'text'],
        ],
    ],
    'mail' => [
        'label' => 'Email',
        'blurb' => 'How transactional mail leaves the store. “Log” writes to storage/mail.log — ideal for XAMPP.',
        'fields' => [
            'mail_driver'    => ['Driver', 'select', '', ['log' => 'Log file (demo)', 'mail' => 'PHP mail()', 'smtp' => 'SMTP']],
            'mail_from'      => ['From address', 'text'],
            'mail_from_name' => ['From name', 'text'],
            'mail_reply_to'  => ['Reply-to address', 'text'],
            'smtp_host'      => ['SMTP host', 'text'],
            'smtp_port'      => ['SMTP port', 'text'],
            'smtp_user'      => ['SMTP username', 'text'],
            'smtp_pass'      => ['SMTP password', 'secret'],
            'smtp_secure'    => ['Encryption', 'select', '', ['' => 'None', 'tls' => 'STARTTLS', 'ssl' => 'SSL']],
            'notify_admin_on_order' => ['Email me on every new order', 'bool'],
        ],
    ],
    'features' => [
        'label' => 'Accounts & reviews',
        'blurb' => 'Feature switches for the customer experience.',
        'fields' => [
            'require_email_verification' => ['Require a verified email before checkout', 'bool'],
            'reviews_require_approval'   => ['Hold new reviews for approval', 'bool'],
        ],
    ],
];

if (is_post()) {
    require_csrf();

    foreach ($groups as $group) {
        foreach ($group['fields'] as $key => [$label, $type]) {
            if ($type === 'bool') {
                setting_set($key, post($key) ? '1' : '0');
                continue;
            }
            if (post($key) !== null) {
                // Secrets are only overwritten when something was typed.
                if ($type === 'secret' && trim((string)post($key)) === '') {
                    continue;
                }
                setting_set($key, trim((string)post($key)));
            }
        }
    }

    flash('success', 'Settings saved.');
    redirect('settings.php');
}

$bankDetailsSet = trim((string)setting('bank_account_number', '')) !== '';

include __DIR__ . '/includes/header.php';
?>

<form method="post" class="admin-form">
    <?= csrf_field() ?>

    <div class="admin-cols admin-cols-form">
        <div class="admin-stack">
            <?php foreach (['store', 'checkout', 'payments'] as $groupId): $group = $groups[$groupId]; ?>
                <section class="panel">
                    <div class="panel-head">
                        <h2><?= e($group['label']) ?></h2>
                        <span class="muted small"><?= e($group['blurb']) ?></span>
                    </div>
                    <div class="form-grid">
                        <?php foreach ($group['fields'] as $key => $field): [$label, $type] = $field; $hint = $field[2] ?? ''; $choices = $field[3] ?? []; ?>
                            <?php if ($type === 'bool'): ?>
                                <label class="switch-row field-wide">
                                    <input type="checkbox" name="<?= e($key) ?>" value="1" <?= setting_bool($key, $key !== 'payment_stripe_enabled' && $key !== 'payment_payhere_enabled') ? 'checked' : '' ?>>
                                    <span><?= e($label) ?></span>
                                </label>
                            <?php elseif ($type === 'textarea'): ?>
                                <label class="field field-wide">
                                    <span><?= e($label) ?></span>
                                    <textarea name="<?= e($key) ?>" rows="3"><?= e((string)setting($key, '')) ?></textarea>
                                </label>
                            <?php else: ?>
                                <label class="field<?= $hint === 'wide' ? ' field-wide' : '' ?>">
                                    <span><?= e($label) ?></span>
                                    <input type="text" name="<?= e($key) ?>" value="<?= e((string)setting($key, '')) ?>">
                                    <?php if ($hint && $hint !== 'wide'): ?><em class="muted small"><?= e($hint) ?></em><?php endif; ?>
                                </label>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($groupId === 'payments'): ?>
                        <p class="muted small">
                            <?= $bankDetailsSet
                                ? 'Bank details are on file — shoppers see them on the confirmation page.'
                                : 'Add your bank details so bank-transfer shoppers know where to send the money.' ?>
                        </p>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>

        <div class="admin-stack">
            <?php foreach (['stripe', 'payhere', 'mail', 'features'] as $groupId): $group = $groups[$groupId]; ?>
                <section class="panel">
                    <div class="panel-head">
                        <h2><?= e($group['label']) ?></h2>
                    </div>
                    <p class="muted small"><?= e($group['blurb']) ?></p>
                    <div class="form-grid">
                        <?php foreach ($group['fields'] as $key => $field): [$label, $type] = $field; $hint = $field[2] ?? ''; $choices = $field[3] ?? []; ?>
                            <?php if ($type === 'bool'): ?>
                                <label class="switch-row field-wide">
                                    <input type="checkbox" name="<?= e($key) ?>" value="1" <?= setting_bool($key, false) ? 'checked' : '' ?>>
                                    <span><?= e($label) ?></span>
                                </label>
                            <?php elseif ($type === 'select'): ?>
                                <label class="field field-wide">
                                    <span><?= e($label) ?></span>
                                    <select name="<?= e($key) ?>">
                                        <?php foreach ($choices as $value => $text): ?>
                                            <option value="<?= e((string)$value) ?>" <?= (string)setting($key, '') === (string)$value ? 'selected' : '' ?>><?= e($text) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            <?php elseif ($type === 'secret'): ?>
                                <label class="field field-wide">
                                    <span><?= e($label) ?></span>
                                    <input type="password" name="<?= e($key) ?>" value="" autocomplete="new-password"
                                           placeholder="<?= setting($key, '') !== '' ? '•••••• stored — type to replace' : 'not set' ?>">
                                </label>
                            <?php else: ?>
                                <label class="field field-wide">
                                    <span><?= e($label) ?></span>
                                    <input type="text" name="<?= e($key) ?>" value="<?= e((string)setting($key, '')) ?>">
                                </label>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($groupId === 'stripe'): ?>
                        <p class="muted small">
                            Status: <?= stripe_configured()
                                ? '<span class="badge badge-ok">Ready</span>'
                                : '<span class="badge badge-warn">Needs keys</span>' ?>
                            · Webhook URL for Stripe (events: <code>checkout.session.completed</code>):
                            <code><?= e(store_link('payment-notify.php?gateway=stripe')) ?></code>
                        </p>
                    <?php elseif ($groupId === 'payhere'): ?>
                        <p class="muted small">
                            Status: <?= payhere_configured()
                                ? '<span class="badge badge-ok">Ready</span>'
                                : '<span class="badge badge-warn">Needs keys</span>' ?>
                            · Notify URL to paste into PayHere:
                            <code><?= e(store_link('payment-notify.php?gateway=payhere')) ?></code>
                        </p>
                    <?php elseif ($groupId === 'mail'): ?>
                        <p class="muted small">
                            Test the pipeline from <a href="<?= e(base_url('settings.php?test_mail=1')) ?>">send a test email</a>.
                        </p>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>

            <section class="panel">
                <h2>Database</h2>
                <p class="muted small">
                    Driver: <strong><?= e(Database::driver()) ?></strong>
                    <?php if (Database::driver() === 'mysql'): ?>
                        · Host <?= e((string)cfg('db.host')) ?> · Database <?= e((string)cfg('db.name')) ?>
                    <?php else: ?>
                        · SQLite at <?= e((string)cfg('db.sqlite')) ?>
                    <?php endif; ?>
                </p>
                <ul class="rank-list">
                    <li><span>Products</span><em><?= (int)Database::value('SELECT COUNT(*) FROM products') ?></em></li>
                    <li><span>Variants</span><em><?= (int)Database::value('SELECT COUNT(*) FROM product_variants') ?></em></li>
                    <li><span>Orders</span><em><?= (int)Database::value('SELECT COUNT(*) FROM orders') ?></em></li>
                    <li><span>Customers</span><em><?= (int)Database::value('SELECT COUNT(*) FROM users WHERE role = \'customer\'') ?></em></li>
                    <li><span>Emails logged</span><em><?= (int)Database::value('SELECT COUNT(*) FROM email_log') ?></em></li>
                </ul>
            </section>

            <div class="admin-actions">
                <button class="btn btn-gold btn-lg" type="submit">Save settings</button>
            </div>
        </div>
    </div>
</form>

<?php
/* Optional: send a test email straight from here. */
if (query('test_mail')) {
    $to = (string)setting('mail_from', cfg('mail.from'));
    $result = send_template($to, 'welcome', ['name' => 'ShopHub admin'], $to);
    echo '<section class="panel"><h2>Test email</h2><p class="muted">'
        . e($result['message'] ?? ($result['ok'] ? 'Sent.' : 'Failed.'))
        . ' Recipient: <code>' . e($to) . '</code></p></section>';
}

include __DIR__ . '/includes/footer.php';
