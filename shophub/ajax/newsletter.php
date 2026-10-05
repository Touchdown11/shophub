<?php
/**
 * AJAX — newsletter signup (footer)
 */
require_once __DIR__ . '/../includes/functions.php';

if (!is_post() || !is_ajax()) {
    json_out(['ok' => false, 'message' => 'Bad request.'], 400);
}
require_csrf();

$email = strtolower(trim((string)post('email')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_out(['ok' => false, 'message' => 'Please enter a valid email address.'], 422);
}

$exists = Database::value('SELECT COUNT(*) FROM subscribers WHERE email = ?', [$email]);
if ($exists) {
    json_out(['ok' => true, 'message' => 'You are already on the list — thank you!']);
}

Database::insert('subscribers', ['email' => $email, 'created_at' => now()]);
json_out(['ok' => true, 'message' => 'Welcome aboard! Watch your inbox for early drops.']);
