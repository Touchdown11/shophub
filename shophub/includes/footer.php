</main>

<footer class="site-footer">
    <div class="container footer-grid">
        <div class="footer-col footer-brand">
            <a class="logo" href="<?= e(base_url('index.php')) ?>">
                <svg class="logo-mark" viewBox="0 0 48 40" aria-hidden="true">
                    <rect x="6" y="6" width="36" height="28" rx="6" fill="#ffc400"/>
                    <text x="24" y="28.5" text-anchor="middle" font-family="Georgia, serif" font-weight="700" font-size="19" fill="#0b0b0d">S</text>
                </svg>
                <span class="logo-text">Shop<span>Hub</span></span>
            </a>
            <p class="muted"><?= e(cfg('app.tagline')) ?>. Curated goods, honest prices and delivery you can count on.</p>
            <div class="pay-chips">
                <span>Cash on Delivery</span><span>Bank Transfer</span><span>Secure Checkout</span>
            </div>
        </div>

        <div class="footer-col">
            <h4>Shop</h4>
            <ul>
                <li><a href="<?= e(base_url('shop.php')) ?>">All products</a></li>
                <li><a href="<?= e(base_url('shop.php?sort=newest')) ?>">New arrivals</a></li>
                <li><a href="<?= e(base_url('shop.php?on_sale=1')) ?>">On sale</a></li>
                <li><a href="<?= e(base_url('shop.php?sort=rating')) ?>">Top rated</a></li>
            </ul>
        </div>

        <div class="footer-col">
            <h4>Account</h4>
            <ul>
                <li><a href="<?= e(base_url('account.php')) ?>">My account</a></li>
                <li><a href="<?= e(base_url('account.php?tab=orders')) ?>">Order history</a></li>
                <li><a href="<?= e(base_url('wishlist.php')) ?>">Wishlist</a></li>
                <li><a href="<?= e(base_url('cart.php')) ?>">Shopping cart</a></li>
            </ul>
        </div>

        <div class="footer-col">
            <h4>Company</h4>
            <ul>
                <li><a href="<?= e(base_url('contact.php')) ?>">Contact us</a></li>
                <li><a href="<?= e(base_url('contact.php#faq')) ?>">Shipping &amp; returns</a></li>
                <li><a href="<?= e(base_url('contact.php#privacy')) ?>">Privacy policy</a></li>
            </ul>
        </div>

        <div class="footer-col footer-news">
            <h4>Join the list</h4>
            <p class="muted">Early access to drops and members-only pricing.</p>
            <form class="news-form" data-newsletter action="<?= e(base_url('ajax/newsletter.php')) ?>">
                <input type="email" name="email" placeholder="you@example.com" required>
                <button class="btn btn-gold" type="submit">Subscribe</button>
            </form>
            <p class="news-note" data-news-note></p>
        </div>
    </div>

    <div class="container footer-bottom">
        <span>&copy; <?= date('Y') ?> <?= e(cfg('app.name')) ?>. All rights reserved.</span>
        <span class="muted">Built with PHP, MySQL and vanilla JavaScript.</span>
    </div>
</footer>

<div class="toast-stack" data-toast-stack aria-live="polite"></div>

<script>
    window.SHOPHUB = {
        base: <?= json_encode(base_url('')) ?>,
        csrf: <?= json_encode(csrf_token()) ?>,
        currency: <?= json_encode(active_currency()) ?>
    };
</script>
<script src="<?= e(asset('js/main.js')) ?>" defer></script>
</body>
</html>
