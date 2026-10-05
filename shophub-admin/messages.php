<?php
/**
 * ShopHub admin — contact messages + newsletter list
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$pageTitle = 'Messages';

if (is_post()) {
    require_csrf();
    $do = (string)post('do');
    $id = (int)post('id');

    if ($do === 'status') {
        Database::run('UPDATE messages SET status = ? WHERE id = ?', [(string)post('status'), $id]);
        flash('success', 'Message updated.');
    } elseif ($do === 'delete') {
        Database::run('DELETE FROM messages WHERE id = ?', [$id]);
        flash('success', 'Message deleted.');
    } elseif ($do === 'reply') {
        $msg  = Database::row('SELECT * FROM messages WHERE id = ?', [$id]);
        if ($msg) {
            send_mail((string)$msg['email'], 'Re: ' . (string)$msg['subject'], (string)post('reply'));
            Database::run("UPDATE messages SET status = 'replied' WHERE id = ?", [$id]);
            flash('success', 'Reply queued — see storage/mail.log in demo mode.');
        }
    }
    redirect('admin/messages.php');
}

$tab = (string)query('tab', 'inbox');
$messages = $tab === 'inbox'
    ? Database::all('SELECT * FROM messages ORDER BY created_at DESC LIMIT 100')
    : [];
$subscribers = $tab === 'subscribers'
    ? Database::all('SELECT * FROM subscribers ORDER BY created_at DESC LIMIT 500')
    : [];

include __DIR__ . '/includes/header.php';
?>

<nav class="admin-tabs">
    <a class="admin-tab<?= $tab === 'inbox' ? ' is-active' : '' ?>" href="?tab=inbox">Inbox</a>
    <a class="admin-tab<?= $tab === 'subscribers' ? ' is-active' : '' ?>" href="?tab=subscribers">
        Newsletter <em><?= (int)Database::value('SELECT COUNT(*) FROM subscribers') ?></em>
    </a>
</nav>

<?php if ($tab === 'inbox'): ?>
    <?php foreach ($messages as $m): ?>
        <section class="panel message-card<?= $m['status'] === 'new' ? ' is-new' : '' ?>">
            <div class="panel-head">
                <div>
                    <h2><?= e((string)$m['subject']) ?></h2>
                    <p class="muted small"><?= e((string)$m['name']) ?> · <a href="mailto:<?= e((string)$m['email']) ?>"><?= e((string)$m['email']) ?></a> · <?= e(nice_date((string)$m['created_at'], 'M j, Y g:i a')) ?></p>
                </div>
                <div class="confirm-badges">
                    <?= status_badge((string)$m['status']) ?>
                    <?php if ($m['status'] !== 'replied'): ?>
                        <form method="post" class="inline-form"><?= csrf_field() ?>
                            <input type="hidden" name="do" value="status"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                            <input type="hidden" name="status" value="resolved">
                            <button class="btn btn-ghost btn-sm" type="submit">Mark resolved</button>
                        </form>
                    <?php endif; ?>
                    <form method="post" class="inline-form" data-confirm-form="Delete this message?"><?= csrf_field() ?>
                        <input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                        <button class="icon-btn danger" type="submit" title="Delete"><?= icon('trash') ?></button>
                    </form>
                </div>
            </div>
            <p><?= nl2br(e((string)$m['message'])) ?></p>
            <details class="reply-box">
                <summary>Write a reply</summary>
                <form method="post" class="stack-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="reply">
                    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                    <textarea name="reply" rows="4" placeholder="Hi <?= e((string)$m['name']) ?>, thanks for reaching out…" required></textarea>
                    <button class="btn btn-gold btn-sm" type="submit">Send reply</button>
                </form>
            </details>
        </section>
    <?php endforeach; ?>
    <?php if (!$messages): ?><section class="panel"><p class="muted">Inbox zero — nothing waiting.</p></section><?php endif; ?>

<?php else: ?>
    <section class="panel">
        <div class="panel-head">
            <h2>Newsletter subscribers</h2>
            <span class="muted small">Export: copy the list below into your mail tool.</span>
        </div>
        <table class="table">
            <thead><tr><th>Email</th><th>Joined</th></tr></thead>
            <tbody>
            <?php foreach ($subscribers as $s): ?>
                <tr><td><?= e((string)$s['email']) ?></td><td class="muted"><?= e(nice_date((string)$s['created_at'], 'M j, Y')) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$subscribers): ?><tr><td colspan="2" class="muted">No subscribers yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
