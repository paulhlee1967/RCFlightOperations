#!/usr/bin/env php
<?php
/**
 * scripts/verify_ama_health.php
 *
 * Probe AMA verify page for a form_build_id (no member lookup).
 * Exit 0 = OK, 1 = failure. Run manually or from cron.
 *
 *   php scripts/verify_ama_health.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/ama_verify.php';

$id = ama_verify_probe_form_build_id();
if ($id === null || $id === '') {
    fwrite(STDERR, "AMA verify health check FAILED: could not obtain form_build_id\n");
    exit(1);
}

fwrite(STDOUT, "AMA verify health check OK (form_build_id present)\n");
exit(0);
