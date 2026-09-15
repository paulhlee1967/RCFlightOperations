<?php
/**
 * includes/rate_limit.php
 *
 * Generic rate limiting helpers for DoS protection.
 * Provides IP-based rate limiting with configurable thresholds.
 */

/**
 * Generic IP-based rate limiting helper.
 *
 * Creates a unified rate_limit_events table to track attempts across all endpoints.
 * Automatically cleans up old records (>25 hours).
 *
 * @param PDO $pdo Database connection
 * @param string $endpoint Unique endpoint identifier (e.g., 'stripe_webhook', 'membership_submit')
 * @param string $clientIp Client IP address
 * @param int $maxAttempts Maximum attempts allowed in the time window
 * @param int $windowMinutes Time window in minutes
 * @return bool True if request is allowed, false if rate limited
 */
function rate_limit_check(
    PDO $pdo,
    string $endpoint,
    string $clientIp,
    int $maxAttempts,
    int $windowMinutes
): bool {
    if ($clientIp === '') {
        return true;
    }
    
    try {
        // Create table if not exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limit_events (
            id int unsigned NOT NULL AUTO_INCREMENT,
            endpoint varchar(100) NOT NULL,
            ip varchar(45) NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY endpoint_ip_created (endpoint, ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // Clean up old records
        $pdo->exec("DELETE FROM rate_limit_events 
                    WHERE created_at < DATE_SUB(NOW(), INTERVAL 25 HOUR)");
        
        // Check rate limit
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM rate_limit_events
             WHERE endpoint = ? AND ip = ? 
             AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        $stmt->execute([$endpoint, $clientIp, $windowMinutes]);
        
        $count = (int) $stmt->fetchColumn();
        
        if ($count >= $maxAttempts) {
            // Log rate limit trigger for monitoring
            error_log(sprintf(
                'Rate limit exceeded: endpoint=%s ip=%s count=%d limit=%d window=%dm',
                $endpoint,
                $clientIp,
                $count,
                $maxAttempts,
                $windowMinutes
            ));
            return false;
        }
        
        // Record this attempt
        $pdo->prepare("INSERT INTO rate_limit_events (endpoint, ip) VALUES (?, ?)")
            ->execute([$endpoint, $clientIp]);
        
        return true;
    } catch (Throwable $e) {
        // Log error but fail open to prevent blocking legitimate users
        error_log("Rate limit check failed: " . $e->getMessage());
        return true;
    }
}

/**
 * Get client IP address, accounting for proxies.
 *
 * Uses REMOTE_ADDR by default. If trust_forwarded_ip is enabled in config and
 * the request comes from a trusted proxy, uses X-Forwarded-For.
 *
 * @param array|null $config Application config array
 * @return string Client IP address
 */
function rate_limit_get_client_ip(?array $config = null): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    
    if ($ip === '') {
        return '0.0.0.0';
    }
    
    // If behind a trusted proxy and config allows it, use X-Forwarded-For
    $trustForwardedIp = !empty($config['trust_forwarded_ip']);
    $trustedProxies = is_array($config['trusted_proxies'] ?? null) 
        ? $config['trusted_proxies'] 
        : [];
    
    if ($trustForwardedIp) {
        $remoteAddr = $ip;
        $isTrusted = false;
        
        // If trusted_proxies list is empty, trust all (single-proxy setup)
        if ($trustedProxies === []) {
            $isTrusted = true;
        } else {
            // Check if REMOTE_ADDR is in trusted proxy list
            foreach ($trustedProxies as $proxy) {
                if (rate_limit_ip_in_range($remoteAddr, $proxy)) {
                    $isTrusted = true;
                    break;
                }
            }
        }
        
        // Use X-Forwarded-For if from trusted proxy
        if ($isTrusted && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwardedIps = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $clientIp = trim($forwardedIps[0]);
            if ($clientIp !== '') {
                $ip = $clientIp;
            }
        }
    }
    
    return $ip;
}

/**
 * Check if an IP address is within a CIDR range.
 *
 * @param string $ip IP address to check
 * @param string $range IP or CIDR range (e.g., '127.0.0.1' or '10.0.0.0/8')
 * @return bool True if IP is in range
 */
function rate_limit_ip_in_range(string $ip, string $range): bool
{
    // Direct match (no CIDR)
    if ($ip === $range) {
        return true;
    }
    
    // CIDR notation
    if (!str_contains($range, '/')) {
        return false;
    }
    
    [$subnet, $bits] = explode('/', $range, 2);
    $bits = (int) $bits;
    
    if ($bits < 0 || $bits > 32) {
        return false;
    }
    
    $ipLong = ip2long($ip);
    $subnetLong = ip2long($subnet);
    
    if ($ipLong === false || $subnetLong === false) {
        return false;
    }
    
    $mask = -1 << (32 - $bits);
    $subnetLong &= $mask;
    
    return ($ipLong & $mask) === $subnetLong;
}

/**
 * Send a 429 Too Many Requests response with JSON body.
 *
 * @param string $message Error message to return
 * @param bool $json Whether to return JSON (true) or plain text (false)
 */
function rate_limit_send_429(string $message = 'Too many requests. Please try again later.', bool $json = true): never
{
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    http_response_code(429);
    header('Retry-After: 60');
    
    if ($json) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'ok' => false,
            'error' => $message,
        ]);
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $message;
    }
    
    exit;
}

/**
 * Rate limit configuration presets for common endpoints.
 *
 * @return array<string, array{max_attempts: int, window_minutes: int}>
 */
function rate_limit_presets(): array
{
    return [
        'stripe_webhook' => ['max_attempts' => 100, 'window_minutes' => 1],
        'membership_submit' => ['max_attempts' => 5, 'window_minutes' => 60],
        'membership_quote' => ['max_attempts' => 30, 'window_minutes' => 15],
        'file_upload' => ['max_attempts' => 20, 'window_minutes' => 60],
        'pdf_export' => ['max_attempts' => 10, 'window_minutes' => 60],
        'csv_export' => ['max_attempts' => 10, 'window_minutes' => 60],
        'csv_import' => ['max_attempts' => 5, 'window_minutes' => 60],
    ];
}

/**
 * Apply rate limiting using preset configuration.
 *
 * @param PDO $pdo Database connection
 * @param string $presetName Preset name from rate_limit_presets()
 * @param string|null $clientIp Client IP (defaults to auto-detection)
 * @param array|null $config Application config for proxy trust settings
 * @return bool True if request is allowed, false if rate limited
 */
function rate_limit_apply_preset(
    PDO $pdo,
    string $presetName,
    ?string $clientIp = null,
    ?array $config = null
): bool {
    $presets = rate_limit_presets();
    
    if (!isset($presets[$presetName])) {
        error_log("Unknown rate limit preset: $presetName");
        return true;
    }
    
    $preset = $presets[$presetName];
    $clientIp = $clientIp ?? rate_limit_get_client_ip($config);
    
    return rate_limit_check(
        $pdo,
        $presetName,
        $clientIp,
        $preset['max_attempts'],
        $preset['window_minutes']
    );
}
