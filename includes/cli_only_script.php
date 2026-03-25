<?php
/**
 * Require that the current script is running under the PHP CLI SAPI.
 * Include at the top of files in scripts/ so they cannot be invoked via the web server.
 */
declare(strict_types=1);

function flightops_require_cli(): void
{
    if (php_sapi_name() !== 'cli') {
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=UTF-8');
        }
        echo "This script must be run from the command line.\n";
        exit(1);
    }
}
