<?php
/**
 * Log out: clear session and redirect to login.
 */
require_once __DIR__ . '/includes/session_ini.php';
flightops_apply_session_cookie_params();
session_start();
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
