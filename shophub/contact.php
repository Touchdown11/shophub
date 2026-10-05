<?php
/**
 * ShopHub — contact / FAQ / policies
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$sent = false;
if (is_post()) {
    require_csrf();
    $name    = (string)post('name');
    $email   = (string)post('email');
    $subject = (string)post('subject');
    $message = (string)post('message');

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($message) < 10) {
        flash('danger', 'Please complete every field — the message needs at least 10 characters.');
    } else {
        Database::insert('messages', [
            'name'       => $name,
            'email'      => strtolower($email),
            'subject'    => $subject,
            'message'    => $message,
            'user_id'    => auth_id(),
            'status'     => 'new',
            'created_at' => now(),
        ]);
        send_template(
            (string)setting('mail_from', cfg('mail.from')),
            'contact_notice',
            [
                'name'      => $name,
                'email'     => $email,
                'subject'   => $subject,
                'message'   => nl2br(e($message)),
                'admin_url' => admin_link('messages.php'),
            ],
            (string)setting('store_name', 'ShopHub')
        );
        flash('success', 'Thanks for reaching out — we reply within one working day.');
        $sent = true;
        redirect('contact.php');
    }
}

$pageTitle = 'Contact us';
include __DIR__ . '/includes/header.php';
?>

<section class="page-head">
    <div class="container">
        <nav class="breadcrumb"><a href="<?= e(base_url('index.php')) ?>">Home</a><span>/</span><em>Contact</em></nav>
        <h1>We are here to help</h1>
        <p class="muted">Questions about an order, a return or a bulk enquiry? Send a note and a human will reply.</p>
    </div>
</section>

<div class="container contact-layout">
    <form method="post" class="panel stack-form">
        <?= csrf_field() ?>
        <h2>Send a message</h2>
        <div class="form-grid">
            <label class="field"><span>Your name</span><input type="text" name="name" value="<?= e((string)(auth_user()['name'] ?? '')) ?>" required></label>
            <label class="field"><span>Email</span><input type="email" name="email" value="<?= e((string)(auth_user()['email'] ?? '')) ?>" required></label>
            <label class="field field-wide"><span>Subject</span>
                <select name="subject">
                    <option>Order enquiry</option>
                    <option>Returns and refunds</option>
                    <option>Product question</option>
                    <option>Wholesale / bulk order</option>
                    <option>Something else</option>
                </select>
            </label>
            <label class="field field-wide"><span>Message</span><textarea name="message" rows="5" minlength="10" required></textarea></label>
        </div>
        <button class="btn btn-gold" type="submit">Send message</button>
    </form>

    <aside class="contact-aside">
        <div class="panel">
            <h3>Reach us directly</h3>
            <ul class="contact-list">
                <li><span>Email</span><a href="mailto:hello@shophub.test">hello@shophub.test</a></li>
                <li><span>Phone</span><a href="tel:+94112345678">+94 11 234 5678</a></li>
                <li><span>Hours</span>Mon–Sat, 9am – 7pm</li>
                <li><span>Showroom</span>42 Galle Road, Colombo 03</li>
            </ul>
        </div>

        <div class="panel" id="faq">
            <h3>Quick answers</h3>
            <details><summary>How long does delivery take?</summary><p>2–4 working days island-wide. Orders before 2pm usually ship the same day.</p></details>
            <details><summary>Do you offer cash on delivery?</summary><p>Yes, everywhere we deliver. Bank transfer is also available at checkout.</p></details>
            <details><summary>What is the returns window?</summary><p>30 days from delivery for unused items in original packaging.</p></details>
        </div>
    </aside>
</div>

<section class="section" id="privacy">
    <div class="container narrow prose">
        <h2>Shipping &amp; returns</h2>
        <p>Standard delivery is free for orders over <?= e(money((float)setting('free_shipping_over', 150))) ?>; below that a flat <?= e(money((float)setting('shipping_flat', 12))) ?> applies. Tracked parcels are handed to our courier partner and you will receive an SMS on dispatch.</p>
        <h2>Privacy policy</h2>
        <p>We collect only what is needed to fulfil your order: name, contact details and delivery address. We never sell customer data, and payment information is never stored on our servers.</p>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
