<?php
/**
 * ShopHub staff portal — sign out.
 */
require_once __DIR__ . '/includes/bootstrap.php';

unset($_SESSION['user_id'], $_SESSION['admin_last_active'], $_SESSION['intended']);
session_regenerate_id(true);

flash('success', 'You have been signed out of the staff portal.');
redirect('login.php');
