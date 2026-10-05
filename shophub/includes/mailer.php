<?php
/**
 * ShopHub — transactional email
 *
 * Drivers:
 *   log  → appends to storage/mail.log (default: perfect for XAMPP, testable)
 *   mail → PHP mail()
 *   smtp → direct SMTP with STARTTLS/SSL + AUTH LOGIN (Gmail, Mailgun, SES…)
 *
 * Every send is recorded in the email_log table so the staff portal can show
 * exactly what went out and what failed.
 */
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/* ------------------------------------------------------------------ sending */

/**
 * Render + send an email from a template.
 *
 * @param array $vars Template variables.
 * @return array{ok: bool, message: string}
 */
function send_template(string $to, string $template, array $vars = [], string $toName = ''): array
{
    $templates = mail_templates();
    if (!isset($templates[$template])) {
        return ['ok' => false, 'message' => 'Unknown email template: ' . $template];
    }

    $branding = [
        'store_name'  => (string)setting('store_name', cfg('app.name')),
        'store_url'   => store_link('index.php'),   // absolute: customers open these from a mail client
        'year'        => date('Y'),
        'currency'    => store_currency(),
    ];
    $vars = array_merge($branding, $vars);

    $subject = interpolate($templates[$template]['subject'], $vars);
    $bodyHtml = interpolate($templates[$template]['body'], $vars);
    // Plain-text alternative: drop the logo chip, turn block ends into breaks,
    // then tidy the whitespace the HTML indentation leaves behind.
    $textSource = preg_replace('/<span[^>]*class="mail-chip"[^>]*>.*?<\/span>/s', ' ', $bodyHtml) ?? $bodyHtml;
    $bodyText = html_entity_decode(strip_tags(str_replace(
        ['<br>', '<br/>', '<br />', '</p>', '</h1>', '</h2>', '</h3>', '</li>', '</tr>', '</table>'],
        "\n",
        $textSource
    )), ENT_QUOTES);
    $bodyText = preg_replace('/[ \t]+/', ' ', (string)$bodyText) ?? '';
    $bodyText = preg_replace('/ *\n */', "\n", $bodyText) ?? '';
    $bodyText = trim(preg_replace('/\n{3,}/', "\n\n", $bodyText) ?? '');

    return mail_send($to, $toName, $subject, $bodyHtml, $bodyText, $template);
}

function interpolate(string $template, array $vars): string
{
    return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', static function ($m) use ($vars) {
        return (string)($vars[$m[1]] ?? '');
    }, $template) ?? $template;
}

/** Low-level transport. Logs the attempt either way. */
function mail_send(string $to, string $toName, string $subject, string $html, string $text, string $template = ''): array
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        mail_log_row($to, $subject, $template, 'failed', 'Invalid recipient');
        return ['ok' => false, 'message' => 'Invalid recipient address.'];
    }

    $driver = (string)setting('mail_driver', cfg('mail.driver', 'log'));
    $from   = (string)setting('mail_from', cfg('mail.from'));
    $fromNm = (string)setting('mail_from_name', cfg('mail.from_name'));
    $reply  = (string)setting('mail_reply_to', cfg('mail.reply_to'));

    try {
        switch ($driver) {
            case 'smtp':
                $result = smtp_send($to, $toName, $subject, $html, $text, $from, $fromNm, $reply);
                break;
            case 'mail':
                $headers = implode("\r\n", [
                    'MIME-Version: 1.0',
                    'Content-Type: text/html; charset=UTF-8',
                    'From: ' . mail_phrase($fromNm, $from),
                    'Reply-To: ' . $reply,
                ]);
                $ok = @mail($to, $subject, $html, $headers);
                $result = ['ok' => (bool)$ok, 'message' => $ok ? 'Sent via mail().' : 'mail() returned false — check sendmail configuration.'];
                break;
            case 'log':
            default:
                $result = mail_write_log($to, $toName, $subject, $html, $text);
                break;
        }
    } catch (Throwable $e) {
        $result = ['ok' => false, 'message' => $e->getMessage()];
    }

    mail_log_row($to, $subject, $template, $result['ok'] ? 'sent' : 'failed', $result['message']);
    return $result;
}

function mail_phrase(string $name, string $email): string
{
    $name = trim($name);
    return $name === '' ? $email : sprintf('"%s" <%s>', str_replace('"', '', $name), $email);
}

function mail_write_log(string $to, string $toName, string $subject, string $html, string $text): array
{
    $dir = __DIR__ . '/../storage';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $entry = sprintf(
        "%s\nTO:      %s <%s>\nSUBJECT: %s\n\n-- TEXT --\n%s\n\n-- HTML --\n%s\n%s\n",
        str_repeat('=', 78),
        $toName !== '' ? $toName : $to,
        $to,
        $subject,
        $text,
        $html,
        str_repeat('=', 78)
    );
    $written = @file_put_contents($dir . '/mail.log', $entry, FILE_APPEND);
    return $written === false
        ? ['ok' => false, 'message' => 'Could not write storage/mail.log — is the folder writable?']
        : ['ok' => true, 'message' => 'Logged to storage/mail.log (driver: log).'];
}

function mail_log_row(string $to, string $subject, string $template, string $status, string $note): void
{
    try {
        Database::insert('email_log', [
            'recipient'  => $to,
            'subject'    => mb_substr($subject, 0, 200),
            'template'   => $template,
            'status'     => $status,
            'note'       => mb_substr($note, 0, 500),
            'created_at' => now(),
        ]);
    } catch (Throwable) {
        // Never let logging break a checkout.
    }
}

/* --------------------------------------------------------------------- SMTP */

function smtp_send(
    string $to, string $toName, string $subject, string $html, string $text,
    string $from, string $fromName, string $replyTo
): array {
    $host   = (string)setting('smtp_host', cfg('mail.smtp_host'));
    $port   = (int)setting('smtp_port', cfg('mail.smtp_port'));
    $secure = strtolower((string)setting('smtp_secure', cfg('mail.smtp_secure')));
    $user   = (string)setting('smtp_user', cfg('mail.smtp_user'));
    $pass   = (string)setting('smtp_pass', cfg('mail.smtp_pass'));

    if ($host === '' || $port === 0) {
        return ['ok' => false, 'message' => 'SMTP host/port not configured.'];
    }

    $transport = $secure === 'ssl' ? 'ssl://' : '';
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $socket = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$socket) {
        return ['ok' => false, 'message' => "SMTP connect failed: $errstr ($errno)"];
    }
    stream_set_timeout($socket, 20);

    $read = static function () use ($socket): string {
        $data = '';
        while (($line = fgets($socket, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return $data;
    };
    $write = static function (string $command) use ($socket): void {
        fwrite($socket, $command . "\r\n");
    };
    $expect = static function (string $response, string $code) : ?string {
        return str_starts_with($response, $code) ? null : trim($response);
    };

    try {
        if (($err = $expect($read(), '220')) !== null) {
            return ['ok' => false, 'message' => 'SMTP banner: ' . $err];
        }

        $write('EHLO ' . (parse_url(store_url(''), PHP_URL_HOST) ?: 'localhost'));
        $ehlo = $read();
        if (($err = $expect($ehlo, '250')) !== null) {
            return ['ok' => false, 'message' => 'EHLO failed: ' . $err];
        }

        if ($secure === 'tls') {
            $write('STARTTLS');
            if (($err = $expect($read(), '220')) !== null) {
                return ['ok' => false, 'message' => 'STARTTLS refused: ' . $err];
            }
            if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                return ['ok' => false, 'message' => 'Could not start TLS encryption.'];
            }
            $write('EHLO ' . (parse_url(store_url(''), PHP_URL_HOST) ?: 'localhost'));
            $read();
        }

        if ($user !== '') {
            $write('AUTH LOGIN');
            if (($err = $expect($read(), '334')) !== null) {
                return ['ok' => false, 'message' => 'AUTH LOGIN refused: ' . $err];
            }
            $write(base64_encode($user));
            $read();
            $write(base64_encode($pass));
            if (($err = $expect($read(), '235')) !== null) {
                return ['ok' => false, 'message' => 'SMTP authentication failed — check username/password: ' . $err];
            }
        }

        $write('MAIL FROM:<' . $from . '>');
        if (($err = $expect($read(), '250')) !== null) {
            return ['ok' => false, 'message' => 'MAIL FROM rejected: ' . $err];
        }
        $write('RCPT TO:<' . $to . '>');
        if (($err = $expect($read(), '250')) !== null && ($err = $expect($read(), '251')) !== null) {
            return ['ok' => false, 'message' => 'RCPT TO rejected: ' . $err];
        }
        $write('DATA');
        if (($err = $expect($read(), '354')) !== null) {
            return ['ok' => false, 'message' => 'DATA rejected: ' . $err];
        }

        $boundary = 'shophub_' . bin2hex(random_bytes(8));
        $headers = [
            'Date: ' . date('r'),
            'From: ' . mail_phrase($fromName, $from),
            'To: ' . mail_phrase($toName, $to),
            'Reply-To: ' . $replyTo,
            'Subject: ' . mail_encode_header($subject),
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $body = implode("\r\n", $headers) . "\r\n\r\n"
            . "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
            . str_replace("\n", "\r\n", $text) . "\r\n"
            . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
            . str_replace("\n", "\r\n", $html) . "\r\n"
            . "--$boundary--";

        // Dot-stuffing per RFC 5321.
        $body = preg_replace('/^\./m', '..', $body);

        $write($body . "\r\n.");
        if (($err = $expect($read(), '250')) !== null) {
            return ['ok' => false, 'message' => 'Message rejected: ' . $err];
        }
        $write('QUIT');
        fclose($socket);

        return ['ok' => true, 'message' => 'Sent via SMTP (' . $host . ').'];
    } catch (Throwable $e) {
        @fclose($socket);
        return ['ok' => false, 'message' => 'SMTP error: ' . $e->getMessage()];
    }
}

function mail_encode_header(string $value): string
{
    return preg_match('/[\x80-\xFF]/', $value)
        ? '=?UTF-8?B?' . base64_encode($value) . '?='
        : $value;
}

/* ----------------------------------------------------------------- templates */

function mail_layout(string $heading, string $intro, string $content, array $vars): string
{
    $store = htmlspecialchars((string)($vars['store_name'] ?? 'ShopHub'), ENT_QUOTES);
    $url   = htmlspecialchars((string)($vars['store_url'] ?? '#'), ENT_QUOTES);
    return <<<HTML
<!DOCTYPE html>
<html><body style="margin:0;padding:0;background:#0b0b0d;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0b0b0d;padding:28px 12px;font-family:Helvetica,Arial,sans-serif;">
  <tr><td align="center">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#15151a;border:1px solid #26262c;border-radius:14px;overflow:hidden;">
      <tr><td style="padding:22px 28px;border-bottom:1px solid #26262c;">
        <a href="{$url}" style="text-decoration:none;color:#ffffff;font-size:20px;font-weight:bold;letter-spacing:-.02em;">
          <span class="mail-chip" style="display:inline-block;width:30px;height:26px;background:#ffc400;border-radius:6px;text-align:center;color:#0b0b0d;font-family:Georgia,serif;line-height:26px;margin-right:8px;">S</span>{$store}
        </a>
      </td></tr>
      <tr><td style="padding:28px;">
        <h1 style="margin:0 0 10px;color:#f5f5f6;font-size:22px;font-family:Georgia,serif;font-weight:600;">{$heading}</h1>
        <p style="margin:0 0 20px;color:#a9a9b2;font-size:14px;line-height:1.6;">{$intro}</p>
        {$content}
      </td></tr>
      <tr><td style="padding:18px 28px;background:#101014;border-top:1px solid #26262c;color:#6f6f78;font-size:12px;">
        You are receiving this because you have an account or placed an order at {$store}.
        &copy; {$vars['year']} {$store}. All rights reserved.
      </td></tr>
    </table>
  </td></tr>
</table>
</body></html>
HTML;
}

function mail_button(string $label, string $url): string
{
    $label = htmlspecialchars($label, ENT_QUOTES);
    $url   = htmlspecialchars($url, ENT_QUOTES);
    return "<p style=\"margin:0 0 22px;\"><a href=\"{$url}\" style=\"display:inline-block;background:#ffc400;color:#14140a;font-weight:bold;text-decoration:none;padding:13px 26px;border-radius:999px;font-size:14px;\">{$label}</a></p>"
        . "<p style=\"margin:0 0 20px;color:#6f6f78;font-size:12px;word-break:break-all;\">Or paste this link into your browser:<br>{$url}</p>";
}

function mail_items_table(array $items, string $currency): string
{
    $rows = '';
    foreach ($items as $item) {
        $name = htmlspecialchars((string)$item['product_name'], ENT_QUOTES);
        $meta = !empty($item['variant_label']) ? ' <span style="color:#8d8d97;font-size:12px;">(' . htmlspecialchars((string)$item['variant_label'], ENT_QUOTES) . ')</span>' : '';
        $rows .= '<tr>'
            . '<td style="padding:9px 0;border-bottom:1px solid #26262c;color:#e6e6ea;font-size:13px;">' . $name . $meta
            . ' <span style="color:#8d8d97;">× ' . (int)$item['quantity'] . '</span></td>'
            . '<td align="right" style="padding:9px 0;border-bottom:1px solid #26262c;color:#ffc400;font-size:13px;white-space:nowrap;">'
            . htmlspecialchars(money((float)$item['line_total'], $currency), ENT_QUOTES) . '</td></tr>';
    }
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;">' . $rows . '</table>';
}

function mail_totals_block(array $order): string
{
    $currency = (string)($order['currency'] ?? store_currency());
    $line = static function (string $label, string $value, bool $strong = false): string {
        $style = $strong
            ? 'padding:7px 0;color:#ffc400;font-size:15px;font-weight:bold;border-top:1px solid #3a3a42;'
            : 'padding:5px 0;color:#a9a9b2;font-size:13px;';
        return '<tr><td style="' . $style . '">' . htmlspecialchars($label, ENT_QUOTES) . '</td>'
             . '<td align="right" style="' . $style . '">' . htmlspecialchars($value, ENT_QUOTES) . '</td></tr>';
    };
    $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 22px;">';
    $html .= $line('Subtotal', money((float)$order['subtotal'], $currency));
    if ((float)$order['discount'] > 0) {
        $html .= $line('Discount', '−' . money((float)$order['discount'], $currency));
    }
    $html .= $line('Delivery', (float)$order['shipping_fee'] > 0 ? money((float)$order['shipping_fee'], $currency) : 'Free');
    $html .= $line('Tax', money((float)$order['tax'], $currency));
    $html .= $line('Total', money((float)$order['total'], $currency), true);
    return $html . '</table>';
}

function mail_order_summary_block(array $order): string
{
    $currency = (string)($order['currency'] ?? store_currency());
    return mail_items_table(order_items((int)$order['id']), $currency) . mail_totals_block($order);
}

function mail_templates(): array
{
    $tpl = [];

    $tpl['verify_email'] = [
        'subject' => 'Confirm your {{store_name}} email address',
        'body' => mail_layout('Confirm your email address', 'One quick step and your {{store_name}} account is fully active — it keeps your orders and wishlist safe.', mail_button('Verify my email', '{{verify_url}}'), ['store_name' => '{{store_name}}', 'year' => '{{year}}', 'store_url' => '{{store_url}}'])
            . '',
    ];

    $tpl['password_reset'] = [
        'subject' => 'Reset your {{store_name}} password',
        'body' => mail_layout('Reset your password', 'We received a request to reset your password. This link is valid for 2 hours and can only be used once.', mail_button('Choose a new password', '{{reset_url}}'), ['store_name' => '{{store_name}}', 'year' => '{{year}}', 'store_url' => '{{store_url}}'])
            . '<p style="margin:0;color:#6f6f78;font-size:12px;">If you did not request this, you can safely ignore this email — your password will not change.</p>',
    ];

    $tpl['welcome'] = [
        'subject' => 'Welcome to {{store_name}}',
        'body' => mail_layout('Welcome aboard, {{name}}', 'Your account is ready. Here is a little something for your first order: use the code below at checkout for 15% off.', '<p style="margin:0 0 22px;font-size:18px;letter-spacing:.18em;color:#ffc400;font-weight:bold;border:1px dashed #4a4a52;border-radius:10px;padding:14px 18px;display:inline-block;">WELCOME15</p>' . mail_button('Start shopping', '{{store_url}}'), ['store_name' => '{{store_name}}', 'year' => '{{year}}', 'store_url' => '{{store_url}}'])
            . '',
    ];

    $tpl['order_placed'] = [
        'subject' => 'Order {{reference}} confirmed — thank you!',
        'body' => mail_layout(
            'Thanks for your order, {{name}}',
            'We have received order <strong style="color:#ffc400;">{{reference}}</strong> and it is being prepared. A receipt is below.',
            '{{items}}'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;font-size:13px;color:#a9a9b2;">'
            . '<tr><td style="padding:4px 0;">Deliver to</td><td align="right" style="padding:4px 0;color:#e6e6ea;">{{address}}, {{city}}</td></tr>'
            . '<tr><td style="padding:4px 0;">Payment</td><td align="right" style="padding:4px 0;color:#e6e6ea;">{{payment_method}}</td></tr>'
            . '</table>'
            . mail_button('Track this order', '{{order_url}}'),
            ['store_name' => '{{store_name}}', 'year' => '{{year}}', 'store_url' => '{{store_url}}']
        ),
    ];

    $tpl['payment_received'] = [
        'subject' => 'Payment received for {{reference}}',
        'body' => mail_layout('Payment received', 'Thank you — we have received payment for order <strong style="color:#ffc400;">{{reference}}</strong> ({{total}}). Your parcel is now in the dispatch queue.', mail_button('View your order', '{{order_url}}'), ['store_name' => '{{store_name}}', 'year' => '{{year}}', 'store_url' => '{{store_url}}']),
    ];

    $tpl['order_processing'] = [
        'subject' => 'Order {{reference}} is being prepared',
        'body' => mail_layout('We are packing your order', 'Good news, {{name}} — order <strong style="color:#ffc400;">{{reference}}</strong> is being prepared in our warehouse and will be handed to the courier shortly.', mail_button('View your order', '{{order_url}}'), ['store_name' => '{{store_name}}', 'year' => '{{year}}', 'store_url' => '{{store_url}}']),
    ];

    $tpl['order_shipped'] = [
        'subject' => 'Order {{reference}} is on its way',
        'body' => mail_layout('Your order has shipped', 'Order <strong style="color:#ffc400;">{{reference}}</strong> left our warehouse and is on its way to {{city}}. Standard delivery takes 2–4 working days.', mail_button('Track your parcel', '{{order_url}}'), ['store_name' => '{{store_name}}', 'year' => '{{year}}', 'store_url' => '{{store_url}}']),
    ];

    $tpl['order_delivered'] = [
        'subject' => 'Order {{reference}} delivered',
        'body' => mail_layout('Delivered — enjoy!', 'Your order <strong style="color:#ffc400;">{{reference}}</strong> has been delivered. If anything is not right you have 30 days to return it, no questions asked.', mail_button('Write a review', '{{review_url}}'), ['store_name' => '{{store_name}}', 'year' => '{{year}}', 'store_url' => '{{store_url}}']),
    ];

    $tpl['order_cancelled'] = [
        'subject' => 'Order {{reference}} cancelled',
        'body' => mail_layout('Your order was cancelled', 'Order <strong style="color:#ffc400;">{{reference}}</strong> has been cancelled. If payment was taken, a refund is issued within 3–5 working days.', mail_button('Shop again', '{{store_url}}'), ['store_name' => '{{store_name}}', 'year' => '{{year}}', 'store_url' => '{{store_url}}']),
    ];

    $tpl['order_refunded'] = [
        'subject' => 'Refund issued for {{reference}}',
        'body' => mail_layout('Your refund is on the way', 'We have refunded order <strong style="color:#ffc400;">{{reference}}</strong>. Depending on your bank it can take 3–5 working days to appear.', mail_button('View your orders', '{{orders_url}}'), ['store_name' => '{{store_name}}', 'year' => '{{year}}', 'store_url' => '{{store_url}}']),
    ];

    $tpl['admin_new_order'] = [
        'subject' => '[{{store_name}}] New order {{reference}} — {{total}}',
        'body' => mail_layout(
            'New order received',
            'Order <strong style="color:#ffc400;">{{reference}}</strong> was placed by {{name}} ({{email}}).',
            '{{items}}'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;font-size:13px;color:#a9a9b2;">'
            . '<tr><td style="padding:4px 0;">Payment method</td><td align="right" style="padding:4px 0;color:#e6e6ea;">{{payment_method}}</td></tr>'
            . '<tr><td style="padding:4px 0;">Deliver to</td><td align="right" style="padding:4px 0;color:#e6e6ea;">{{address}}, {{city}}</td></tr>'
            . '</table>' . mail_button('Open in the staff portal', '{{admin_url}}'),
            ['store_name' => '{{store_name}}', 'year' => '{{year}}', 'store_url' => '{{store_url}}']
        ),
    ];

    $tpl['contact_notice'] = [
        'subject' => '[{{store_name}}] Contact form: {{subject}}',
        'body' => mail_layout(
            'New contact message',
            'From <strong>{{name}}</strong> &lt;{{email}}&gt;',
            '<p style="margin:0 0 18px;color:#e6e6ea;font-size:14px;line-height:1.7;">{{message}}</p>'
            . mail_button('Open the staff portal', '{{admin_url}}'),
            ['store_name' => '{{store_name}}', 'year' => '{{year}}', 'store_url' => '{{store_url}}']
        ),
    ];

    return $tpl;
}
