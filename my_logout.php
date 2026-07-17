<?php
/**
 * my_logout.php — End the member portal session.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/member_portal.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

member_portal_session_clear();
header('Location: my.php');
exit;
