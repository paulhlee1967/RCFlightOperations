<?php
/**
 * Whether to honor X-Forwarded-Proto (and similar) from the current request.
 *
 * OWASP: only treat forwarded headers as authoritative when the immediate client
 * is a reverse proxy you control — never trust them from arbitrary REMOTE_ADDR.
 */
declare(strict_types=1);

/**
 * @param array<string, mixed> $config
 */
function flightops_should_trust_forwarded_proto(array $config): bool
{
    if (empty($config['trust_forwarded_https'])) {
        return false;
    }
    $trusted = $config['trusted_proxies'] ?? null;
    if (!is_array($trusted) || $trusted === []) {
        // Backward compatible: no list = assume PHP is only reachable via your edge proxy.
        // For defense in depth, set trusted_proxies to the proxy IP(s) that connect to PHP.
        return true;
    }
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($remote === '') {
        return false;
    }
    foreach ($trusted as $rule) {
        $rule = trim((string) $rule);
        if ($rule === '') {
            continue;
        }
        if (flightops_ip_matches_rule($remote, $rule)) {
            return true;
        }
    }
    return false;
}

function flightops_ip_matches_rule(string $ip, string $rule): bool
{
    if ($ip === $rule) {
        return true;
    }
        if (!str_contains($rule, '/')) {
            return false;
        }
    // IPv4 CIDR only (sufficient for typical upstream proxies).
    $parts = explode('/', $rule, 2);
    $subnet = $parts[0];
    $bits = (int) ($parts[1] ?? -1);
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        || !filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        || $bits < 0 || $bits > 32) {
        return false;
    }
    $ipLong     = ip2long($ip)      ?: -1;
    $subnetLong = ip2long($subnet) ?: -1;
    if ($ipLong < 0 || $subnetLong < 0) {
        return false;
    }
    $mask = $bits === 0 ? 0 : (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;

    return ($ipLong & $mask) === ($subnetLong & $mask);
}
