<?php
/**
 * Log out: clear session and redirect to login.
 */
require_once __DIR__ . '/includes/session_ini.php';
require_once __DIR__ . '/includes/canonical_host.php';
flightops_enforce_canonical_host();
flightops_apply_session_cookie_params();
session_start();
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
