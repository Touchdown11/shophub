<?php
/**
 * ShopHub — customer sign out.
 *
 * The staff portal is a separate application with its own session, so signing
 * out here only ends the storefront session.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

unset($_SESSION['user_id'], $_SESSION['intended']);
session_regenerate_id(true);

flash('success', 'You have been signed out.');
redirect('index.php');
