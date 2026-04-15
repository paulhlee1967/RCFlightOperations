<?php
/**
 * Simple file logger for operational logs (cron output, email failures, etc.).
 * Writes to logs/app-YYYY-MM-DD.log by default.
 */

/**
 * Get absolute path to the logs directory (project-root/logs).
 * Creates it if missing.
 */
function flightops_logs_dir(): string {
    $dir = dirname(__DIR__) . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/**
 * Append a log line to the daily log file.
 *
 * @param string               $level   e.g. 'INFO', 'WARN', 'ERROR'
 * @param string               $message Short human message
 * @param array<string, mixed> $context Optional structured context (JSON)
 * @param string|null          $fileTag Optional file tag (defaults to 'app')
 */
function flightops_log(string $level, string $message, array $context = [], string|null $fileTag = null): void {
    $level = strtoupper(trim($level));
    if ($level === '') $level = 'INFO';
    $message = trim($message);
    $fileTag = $fileTag !== null ? preg_replace('/[^a-z0-9._-]+/i', '-', $fileTag) : 'app';
    if ($fileTag === '' || $fileTag === '-') $fileTag = 'app';

    $dir  = flightops_logs_dir();
    $file = $dir . '/' . $fileTag . '-' . date('Y-m-d') . '.log';

    $line = '[' . date('c') . '] ' . $level . ' ' . $message;
    if (!empty($context)) {
        $json = json_encode($context, JSON_UNESCAPED_SLASHES);
        if ($json !== false && $json !== 'null') {
            $line .= ' ' . $json;
        }
    }
    $line .= "\n";

    // Best-effort logging; never break the page/cron job.
    try {
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
    }
}

