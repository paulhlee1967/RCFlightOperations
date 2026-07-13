# DoS Security Patches - Implementation Guide

**Date:** 2026-07-13  
**Version:** 1.6.0+security  
**Related:** See `SECURITY_DOS_REVIEW.md` for full security analysis

---

## Overview

This document describes the security patches implemented to address Denial of Service (DoS) vulnerabilities identified in the security review. These patches add rate limiting to critical endpoints that were previously unprotected.

---

## Files Added

### 1. `includes/rate_limit.php` (NEW)

**Purpose:** Unified rate limiting library for DoS protection

**Key Functions:**
- `rate_limit_check()` - Generic IP-based rate limiting
- `rate_limit_get_client_ip()` - Safe client IP detection with proxy support
- `rate_limit_apply_preset()` - Apply predefined rate limits by endpoint name
- `rate_limit_send_429()` - Send standardized 429 responses
- `rate_limit_presets()` - Predefined limits for common endpoints

**Database Table Created:**
```sql
CREATE TABLE IF NOT EXISTS rate_limit_events (
    id int unsigned NOT NULL AUTO_INCREMENT,
    endpoint varchar(100) NOT NULL,
    ip varchar(45) NOT NULL,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY endpoint_ip_created (endpoint, ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

**Rate Limit Presets:**
- `stripe_webhook`: 100 requests per minute per IP
- `membership_submit`: 5 requests per hour per IP
- `membership_quote`: 30 requests per 15 minutes per IP
- `file_upload`: 20 requests per hour per IP
- `pdf_export`: 10 requests per hour per IP
- `csv_export`: 10 requests per hour per IP
- `csv_import`: 5 requests per hour per IP

---

## Files Modified

### 1. `api_stripe_webhook.php` ✅ PATCHED

**Critical Vulnerability Fixed:** Webhook endpoint had no rate limiting

**Changes:**
```diff
+ require_once __DIR__ . '/includes/rate_limit.php';

+ // Rate limiting: 100 requests per minute per IP
+ $clientIp = rate_limit_get_client_ip($config ?? null);
+ if (!rate_limit_apply_preset($pdo, 'stripe_webhook', $clientIp)) {
+     http_response_code(429);
+     echo json_encode(['error' => 'Too many requests.']);
+     exit;
+ }

+ // Payload size check: Stripe webhooks are typically small (<10KB)
+ $maxPayloadSize = 10240; // 10KB
+ if (isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > $maxPayloadSize) {
+     http_response_code(413);
+     echo json_encode(['error' => 'Payload too large.']);
+     exit;
+ }
```

**Protection Added:**
- ✅ Rate limiting: 100 requests/minute per IP
- ✅ Payload size limit: 10KB maximum
- ✅ Proper 429 and 413 HTTP responses

### 2. `api_membership_submit.php` ✅ PATCHED

**High Vulnerability Fixed:** Application submission had no rate limiting

**Changes:**
```diff
+ require_once __DIR__ . '/includes/rate_limit.php';

+ // Rate limiting: 5 submissions per hour per IP
+ $clientIp = rate_limit_get_client_ip($config ?? null);
+ if (!rate_limit_apply_preset($pdo, 'membership_submit', $clientIp)) {
+     membership_submit_json([
+         'ok' => false,
+         'error' => 'Too many submission attempts. Please wait an hour and try again.',
+     ], 429);
+ }
```

**Protection Added:**
- ✅ Rate limiting: 5 submissions per hour per IP
- ✅ Clear error message to users
- ✅ Prevents database flooding with fake applications

### 3. `api_membership_quote.php` ✅ PATCHED

**Medium Vulnerability Fixed:** Quote endpoint could be abused

**Changes:**
```diff
+ require_once __DIR__ . '/includes/rate_limit.php';

+ // Rate limiting: 30 requests per 15 minutes per IP
+ $clientIp = rate_limit_get_client_ip($config ?? null);
+ if (!rate_limit_apply_preset($pdo, 'membership_quote', $clientIp)) {
+     membership_quote_json([
+         'ok' => false,
+         'error' => 'Too many requests. Please try again in a few minutes.',
+     ], 429);
+ }
```

**Protection Added:**
- ✅ Rate limiting: 30 requests per 15 minutes per IP
- ✅ Prevents quote calculation abuse

---

## Configuration Options

### Proxy Support

If the application runs behind a reverse proxy (Nginx, CloudFlare, etc.), configure proxy trust in `config.php`:

```php
return [
    // Trust X-Forwarded-For header from reverse proxy
    'trust_forwarded_ip' => true,
    
    // Optional: List of trusted proxy IPs (defense in depth)
    // If omitted or empty, all proxies are trusted (typical single-proxy setup)
    'trusted_proxies' => ['127.0.0.1', '10.0.0.0/8'],
    
    // ... other config ...
];
```

**Important:** Only enable `trust_forwarded_ip` if you run behind a proxy YOU control. Never trust `X-Forwarded-For` from untrusted sources.

### Adjusting Rate Limits

To adjust rate limits for your installation, modify the presets in `includes/rate_limit.php`:

```php
function rate_limit_presets(): array
{
    return [
        'membership_submit' => [
            'max_attempts' => 10,      // Increase from 5 to 10
            'window_minutes' => 60      // Keep 1-hour window
        ],
        // ... other presets ...
    ];
}
```

Or call `rate_limit_check()` directly with custom parameters:

```php
$allowed = rate_limit_check(
    $pdo,
    'custom_endpoint',
    $clientIp,
    $maxAttempts = 20,
    $windowMinutes = 30
);
```

---

## Testing

### Unit Testing Rate Limiting

Test that rate limiting works correctly:

```bash
# Test Stripe webhook (should allow 100/min)
for i in {1..105}; do
  curl -X POST http://localhost/api_stripe_webhook.php \
    -H "Content-Type: application/json" \
    -d '{"test": true}' \
    -w "\nStatus: %{http_code}\n"
done
# Expected: First 100 succeed, last 5 return 429

# Test membership submission (should allow 5/hour)
for i in {1..7}; do
  curl -X POST http://localhost/api_membership_submit.php \
    -H "Content-Type: application/json" \
    -d '{"test": true}' \
    -w "\nStatus: %{http_code}\n"
  sleep 1
done
# Expected: First 5 succeed (after CSRF validation), last 2 return 429
```

### Load Testing

Use Apache Bench or wrk to test rate limiting under load:

```bash
# Test webhook endpoint
ab -n 200 -c 10 -p payload.json -T application/json \
  http://localhost/api_stripe_webhook.php

# Test membership submission
ab -n 20 -c 5 -p application.json -T application/json \
  http://localhost/api_membership_submit.php
```

### Monitoring Rate Limits

Query the rate limit events table to monitor usage:

```sql
-- Check current rate limit status
SELECT 
    endpoint,
    ip,
    COUNT(*) as attempts,
    MAX(created_at) as last_attempt
FROM rate_limit_events
WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
GROUP BY endpoint, ip
ORDER BY attempts DESC;

-- Check recent 429 responses in access logs
grep " 429 " /var/log/nginx/access.log | tail -20
```

---

## Deployment Checklist

### Pre-Deployment

- [ ] Review `SECURITY_DOS_REVIEW.md` for full context
- [ ] Test patches in development environment
- [ ] Configure `trusted_proxies` if behind reverse proxy
- [ ] Set up monitoring for rate limit events
- [ ] Notify users about new rate limiting (optional)

### Deployment

- [ ] Deploy `includes/rate_limit.php`
- [ ] Deploy patched API files
- [ ] Verify database table creation (happens automatically)
- [ ] Test each endpoint manually
- [ ] Run load tests (optional)

### Post-Deployment

- [ ] Monitor error logs for rate limit triggers
- [ ] Check for false positives (legitimate users blocked)
- [ ] Adjust rate limits if needed based on usage patterns
- [ ] Set up alerts for excessive 429 responses

---

## Rollback Plan

If issues arise, rollback is simple:

1. **Revert API files** to previous versions:
   ```bash
   git checkout HEAD~1 api_stripe_webhook.php
   git checkout HEAD~1 api_membership_submit.php
   git checkout HEAD~1 api_membership_quote.php
   ```

2. **Remove rate limit library** (optional):
   ```bash
   rm includes/rate_limit.php
   ```

3. **Leave database table** (harmless, contains only tracking data):
   ```sql
   -- Optional: Drop the table if needed
   DROP TABLE IF EXISTS rate_limit_events;
   ```

---

## Future Enhancements

These patches address **critical and high-priority** vulnerabilities. Additional improvements can be made:

### Phase 2 (Medium Priority)
- Add rate limiting to file upload endpoints
- Add rate limiting to PDF generation
- Add rate limiting to CSV import/export
- Implement memory limits for resource-intensive operations

### Phase 3 (Lower Priority)
- Add circuit breaker pattern for external API calls
- Implement background job processing for large operations
- Add per-user upload quotas
- Implement disk space monitoring

### Infrastructure Level
- Deploy Web Application Firewall (CloudFlare, AWS WAF)
- Configure Nginx/Apache rate limiting modules
- Set up monitoring and alerting for DoS attacks
- Implement IP reputation checking

---

## Support and Monitoring

### Log Monitoring

Rate limit triggers are logged to PHP error log:

```
[2026-07-13 03:55:12] Rate limit exceeded: endpoint=membership_submit ip=192.168.1.100 count=6 limit=5 window=60m
```

### Alert Setup

Set up alerts for excessive rate limiting (may indicate attack or misconfiguration):

```bash
# Example: Alert if more than 100 rate limit events in 5 minutes
grep "Rate limit exceeded" /var/log/php-fpm/error.log | 
  tail -1000 | 
  grep "$(date -d '5 minutes ago' '+%Y-%m-%d %H:%M')" | 
  wc -l
```

### Database Maintenance

The rate limit table self-cleans records older than 25 hours on each request. For additional cleanup:

```sql
-- Manual cleanup (optional, runs automatically)
DELETE FROM rate_limit_events 
WHERE created_at < DATE_SUB(NOW(), INTERVAL 25 HOUR);

-- Check table size
SELECT 
    COUNT(*) as total_records,
    COUNT(DISTINCT endpoint) as unique_endpoints,
    COUNT(DISTINCT ip) as unique_ips
FROM rate_limit_events;
```

---

## Security Considerations

### IP Spoofing

Rate limiting by IP can be bypassed if an attacker:
- Uses multiple IPs (distributed attack)
- Spoofs X-Forwarded-For header (if proxy not configured correctly)

**Mitigation:**
- Use `trusted_proxies` configuration to prevent header spoofing
- Consider additional authentication-based rate limiting
- Deploy infrastructure-level DDoS protection (CloudFlare, AWS Shield)

### Legitimate Users Blocked

Rate limits may block legitimate users who:
- Share IP addresses (NAT, corporate networks)
- Make multiple requests quickly (form resubmissions)

**Mitigation:**
- Monitor 429 responses and adjust limits if needed
- Provide clear error messages with retry timing
- Consider session-based rate limiting in addition to IP-based

### Database Performance

The rate_limit_events table can grow large under attack.

**Mitigation:**
- Automatic cleanup of old records (>25 hours)
- Indexed columns for fast queries
- Consider moving to Redis/Memcached for high-traffic sites

---

## Summary

These patches provide **essential DoS protection** for critical endpoints while maintaining a good user experience. The implementation is:

- ✅ **Backward compatible** - No breaking changes
- ✅ **Easy to deploy** - Drop-in files, automatic table creation
- ✅ **Configurable** - Adjustable limits per endpoint
- ✅ **Monitored** - Logs all rate limit triggers
- ✅ **Tested** - Includes testing procedures

**Impact:**
- Critical vulnerability (Stripe webhook flooding) → **FIXED**
- High vulnerability (Application flooding) → **FIXED**
- Medium vulnerability (Quote endpoint abuse) → **FIXED**

The application is now significantly more resilient to DoS attacks while maintaining ease of use for legitimate users.
