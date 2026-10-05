<?php
/**
 * ShopHub staff portal — shell footer.
 */
if (empty($publicShell)):
?>
        </div><!-- /.admin-content -->

        <footer class="admin-footer">
            <span>ShopHub staff portal · <?= e(setting('store_name', cfg('app.name'))) ?></span>
            <span class="muted small">
                Signed in as <?= e((string)(auth_user()['email'] ?? '')) ?>
                · session ends after <?= (int)cfg('app.admin_session_minutes', 120) ?> minutes idle
            </span>
        </footer>
    </div><!-- /.admin-main -->
</div><!-- /.admin-shell -->
<?php endif; ?>

<div class="toast-stack" data-toast-stack aria-live="polite"></div>
<script>
    window.SHOPHUB = {
        base: <?= json_encode(base_url('')) ?>,
        csrf: <?= json_encode(csrf_token()) ?>,
        admin: true
    };
</script>
<script src="<?= e(base_url('assets/js/admin.js')) ?>?v=<?= e(asset_version(__DIR__ . '/../assets/js/admin.js')) ?>" defer></script>
</body>
</html>
