# Denial of Service (DoS) Security Review

**Review Date:** 2026-07-13
**Application:** RC Flight Operations v2.0.0
**Reviewer:** Cloud Agent Security Analysis

---

## Executive Summary

The RC Flight Operations application has **moderate DoS protection** in place, with rate limiting on critical endpoints but several areas that could benefit from additional hardening. The application is designed for small club membership management (typically <1000 members) and is not built to handle high-traffic scenarios.

### Risk Level: **MEDIUM**

The application has basic protections but is vulnerable to resource exhaustion attacks in several areas.

---

## Current Security Measures ✅

### 1. **Rate Limiting on Public Endpoints** ✅ GOOD

The application implements IP-based rate limiting on sensitive public endpoints:

#### AMA Verification (Public - `/api_verify_ama_public.php`)
- **Limit:** 15 requests per 15 minutes per IP
- **Implementation:** Database-tracked (`membership_apply_ama_ip_events` table)
- **Response:** HTTP 429 with appropriate error message
- **Location:** `includes/membership_application.php:491-511`

```php
function membership_application_ama_rate_limit_check(PDO $pdo, string $clientIp): bool
{
    // ... creates table if not exists
    // Cleans old records (>25 hours)
    // Checks: 15 attempts in 15 minutes
    if ((int) $stmt->fetchColumn() >= 15) {
        return false;
    }
}
```

#### AMA Verification (Authenticated - `/api_verify_ama.php`)
- **Limit:** 20 requests per minute per user session
- **Implementation:** Session-based tracking
- **Response:** HTTP 429 with appropriate error message
- **Location:** `api_verify_ama.php:37-49`

#### Password Reset (`/forgot_password.php`)
- **Limit:** 12 requests per 15 minutes per IP
- **Implementation:** Database-tracked (`password_reset_ip_events` table)
- **Cooldown:** 5-minute cooldown per email address
- **Silent failure:** Returns success message even when rate limited (prevents enumeration)
- **Location:** `forgot_password.php:47-58`

### 2. **Login Brute-Force Protection** ✅ GOOD

- **Limit:** 5 failed attempts per email address
- **Lockout:** 15 minutes
- **Implementation:** Database-tracked (`login_attempts` table)
- **Response:** Clear error message about lockout
- **Location:** `login.php:24-93`

### 3. **Authentication & Authorization** ✅ GOOD

- All administrative endpoints require authentication
- Role-based access control (admin, manager, staff, report_viewer)
- CSRF protection on all POST requests
- Session regeneration on login (prevents fixation)

### 4. **File Upload Validation** ✅ GOOD

- **MIME type validation** using `finfo` (not just extension checking)
- **File size limits:** 5 MB for photos and documents
- **Allowed types:** Specific whitelist (JPEG, PNG, GIF for photos; PDF for documents)
- **Path traversal protection:** Uses `realpath()` and validates paths are within uploads directory
- **Location:** `includes/member_save.php:115-149`, `badge_photo.php:30-46`

### 5. **SQL Injection Protection** ✅ GOOD

- Consistent use of **prepared statements with parameterized queries**
- No raw SQL concatenation observed
- PDO with proper parameter binding throughout

---

## Identified DoS Vulnerabilities ⚠️

### 1. **CRITICAL: Stripe Webhook - No Rate Limiting** 🔴

**File:** `api_stripe_webhook.php`

**Issue:** The Stripe webhook endpoint has NO rate limiting. While it validates signatures, an attacker with a valid webhook secret could flood the system.

**Risk:** High - Could exhaust database connections and memory

**Current Code:**
```php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$payload = file_get_contents('php://input');
// No size check on payload
// No rate limiting
```

**Recommendation:**
1. Add IP-based rate limiting (100 requests per minute per IP)
2. Add payload size validation (Stripe webhooks are typically <10KB)
3. Consider webhook signature caching to prevent replay attacks
4. Add request logging for monitoring

### 2. **HIGH: Membership Application Submission - Limited Protection** 🟠

**File:** `api_membership_submit.php`

**Issue:** While CSRF-protected, there's no rate limiting on membership submission attempts. An attacker could:
- Flood the database with pending applications
- Fill up disk space with uploaded files
- Exhaust Stripe API rate limits

**Current Protection:**
- CSRF token required ✅
- File validation exists ✅
- **NO** rate limiting ❌

**Recommendation:**
1. Add IP-based rate limiting (5 submissions per hour per IP)
2. Add session-based rate limiting (1 submission per 5 minutes per session)
3. Add database cleanup for abandoned applications

### 3. **HIGH: File Upload Endpoints - No Rate Limiting** 🟠

**Files:**
- Member photo uploads (via `member_edit.php`, `member_wizard.php`)
- FAA card uploads
- Badge photo uploads
- Import CSV (`import.php`)

**Issue:** While file uploads have size limits (5MB), there's no limit on the **number** of uploads. An authenticated attacker could:
- Upload thousands of 5MB files
- Fill disk space
- Exhaust PHP upload processing resources

**Current Protection:**
- File size limits (5MB) ✅
- MIME type validation ✅
- Authentication required ✅
- **NO** rate limiting ❌

**Recommendation:**
1. Add per-user upload rate limiting (10 files per hour per user)
2. Add disk space monitoring
3. Implement upload quotas per user
4. Add automatic cleanup of old temporary files

### 4. **MEDIUM: CSV Import/Export - Resource Intensive** 🟡

**Files:** `import.php`, `export.php`, `reports.php`

**Issue:** CSV import and export operations can be memory-intensive for large datasets. While there's a threshold for session storage (400 rows), there's no limit on total import size.

**Current Protection:**
- Authentication required ✅
- CSRF protection ✅
- Session threshold (400 rows) ✅
- **NO** hard limits on import size ❌

**Recommendation:**
1. Add hard limit on CSV import size (e.g., 5,000 rows or 10MB file)
2. Add timeout protection for long-running imports
3. Consider chunked processing for large imports
4. Add progress monitoring

### 5. **MEDIUM: PDF Generation - Memory Exhaustion** 🟡

**File:** `includes/report_pdf.php`, `reports.php`

**Issue:** PDF generation using Dompdf can be memory-intensive, especially for reports with large datasets or high-resolution images.

**Current Protection:**
- Authentication required ✅
- Uses cached logo thumbnails ✅
- **NO** memory limits ❌
- **NO** rate limiting ❌

**Recommendation:**
1. Set explicit memory limits for PDF generation (e.g., 256MB)
2. Add rate limiting (5 PDF generations per hour per user)
3. Add row limits for PDF reports (e.g., max 1,000 rows)
4. Consider background job processing for large PDFs

### 6. **MEDIUM: External HTTP Requests - No Timeout Protection** 🟡

**Files:**
- `includes/ama_verify.php` (AMA website scraping)
- `includes/sender_net.php` (Sender.net API)
- `includes/member_save.php` (photo downloads)

**Issue:** External HTTP requests have timeouts but could still block PHP workers if multiple requests hang.

**Current Protection:**
- Connection timeout: 8 seconds ✅
- Request timeout: 25 seconds ✅
- Rate limiting on AMA verify ✅
- **NO** concurrent request limiting ❌

**Recommendation:**
1. Add circuit breaker pattern for repeated failures
2. Add monitoring for external service health
3. Consider async/queue processing for non-critical external calls

### 7. **LOW: Session Storage - No Size Limits** 🟢

**Issue:** While the import system uses file storage for large datasets (>400 rows), there's no explicit limit on session size for other operations.

**Current Protection:**
- File storage for large imports ✅
- Session-based AMA verification data with TTL ✅
- **NO** overall session size limit ❌

**Recommendation:**
1. Set `session.upload_progress.max_size` in PHP config
2. Add session size monitoring
3. Add cleanup of stale session data

### 8. **LOW: Database Connection Pool** 🟢

**Issue:** No explicit connection pooling or limits. A flood of requests could exhaust database connections.

**Current Protection:**
- Uses PDO persistent connections (configurable) ✅
- **NO** explicit connection pool management ❌

**Recommendation:**
1. Configure `max_connections` in MySQL/MariaDB
2. Set `max_user_connections` limit
3. Use a connection pooler like ProxySQL for high-traffic scenarios
4. Monitor active connections

---

## Infrastructure-Level Protections Needed

The application relies heavily on **infrastructure-level protections** that should be implemented outside the application:

### 1. **Web Application Firewall (WAF)** 🔴 CRITICAL
- **CloudFlare**, **AWS WAF**, or **ModSecurity**
- Rate limiting at the edge
- Bot protection
- DDoS mitigation

### 2. **Reverse Proxy Rate Limiting** 🔴 CRITICAL
- **Nginx** `limit_req` module
- **Apache** `mod_ratelimit`
- Per-IP connection limits
- Request rate limits

### 3. **Server Resource Limits** 🟠 HIGH
- PHP `max_execution_time` (currently no explicit limits)
- PHP `memory_limit` (should be set per-script)
- PHP-FPM worker limits (`pm.max_children`)
- Disk quotas

### 4. **Monitoring & Alerting** 🟠 HIGH
- Failed login attempts
- Rate limit triggers
- Disk space usage
- Database connection count
- External API failures

---

## Recommended Immediate Actions

### Priority 1: Critical (Implement Immediately)

1. **Add rate limiting to Stripe webhook endpoint**
   ```php
   // Add to api_stripe_webhook.php
   $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
   if (!stripe_webhook_rate_limit_check($pdo, $clientIp)) {
       http_response_code(429);
       exit;
   }
   ```

2. **Add payload size limit to webhook**
   ```php
   // Add before file_get_contents
   $maxSize = 10240; // 10KB
   if (isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > $maxSize) {
       http_response_code(413);
       exit;
   }
   ```

3. **Implement infrastructure rate limiting** (Nginx/Apache/CloudFlare)

### Priority 2: High (Implement Soon)

4. **Add rate limiting to membership submission endpoint**
5. **Add rate limiting to file upload operations**
6. **Set explicit memory limits for PDF generation**
7. **Add hard limits on CSV import size**

### Priority 3: Medium (Plan for Future Release)

8. **Implement upload quotas per user**
9. **Add circuit breaker pattern for external APIs**
10. **Implement background job processing for resource-intensive operations**

---

## Code-Level Improvements

### Rate Limiting Helper Function

Create a reusable rate limiting helper in `includes/rate_limit.php`:

```php
<?php
/**
 * Generic IP-based rate limiting helper.
 *
 * @param PDO $pdo Database connection
 * @param string $endpoint Unique endpoint identifier
 * @param string $clientIp Client IP address
 * @param int $maxAttempts Maximum attempts allowed
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
        $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limit_events (
            id int unsigned NOT NULL AUTO_INCREMENT,
            endpoint varchar(100) NOT NULL,
            ip varchar(45) NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY endpoint_ip_created (endpoint, ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("DELETE FROM rate_limit_events
                    WHERE created_at < DATE_SUB(NOW(), INTERVAL 25 HOUR)");

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM rate_limit_events
             WHERE endpoint = ? AND ip = ?
             AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        $stmt->execute([$endpoint, $clientIp, $windowMinutes]);

        if ((int) $stmt->fetchColumn() >= $maxAttempts) {
            return false;
        }

        $pdo->prepare("INSERT INTO rate_limit_events (endpoint, ip) VALUES (?, ?)")
            ->execute([$endpoint, $clientIp]);

        return true;
    } catch (Throwable $e) {
        error_log("Rate limit check failed: " . $e->getMessage());
        return true; // Fail open to prevent blocking legitimate users
    }
}
```

---

## Testing Recommendations

### Load Testing
1. Use **Apache Bench** or **wrk** to test rate limiting
2. Test concurrent file uploads
3. Test concurrent PDF generation
4. Test large CSV imports

### Security Testing
1. Test webhook endpoint with rapid requests
2. Test file upload with maximum size files
3. Test application submission flooding
4. Test external API timeout behavior

### Monitoring
1. Set up alerts for rate limit triggers
2. Monitor disk space usage
3. Monitor database connection count
4. Monitor PHP worker usage
5. Track failed external API calls

---

## Configuration Recommendations

### PHP Configuration (`php.ini` or `.htaccess`)

```ini
# Resource limits
max_execution_time = 60
max_input_time = 60
memory_limit = 256M
post_max_size = 10M
upload_max_filesize = 5M

# Session security
session.cookie_httponly = 1
session.cookie_samesite = "Lax"
session.use_strict_mode = 1
session.use_only_cookies = 1

# Upload limits
max_file_uploads = 10
```

### MySQL Configuration

```ini
[mysqld]
max_connections = 150
max_user_connections = 50
connect_timeout = 10
wait_timeout = 600
interactive_timeout = 600
```

### Nginx Rate Limiting (recommended)

```nginx
http {
    # Define rate limit zones
    limit_req_zone $binary_remote_addr zone=api:10m rate=30r/m;
    limit_req_zone $binary_remote_addr zone=login:10m rate=5r/m;
    limit_req_zone $binary_remote_addr zone=webhook:10m rate=100r/m;

    server {
        # API endpoints
        location ~ ^/api_ {
            limit_req zone=api burst=5 nodelay;
        }

        # Login
        location /login.php {
            limit_req zone=login burst=3 nodelay;
        }

        # Stripe webhook
        location /api_stripe_webhook.php {
            limit_req zone=webhook burst=10 nodelay;
        }
    }
}
```

---

## Summary Table

| Vulnerability | Severity | Current Protection | Recommended Action | Priority |
|--------------|----------|-------------------|-------------------|----------|
| Stripe webhook flooding | 🔴 Critical | None | Add rate limiting + size limits | P1 |
| Application submission flooding | 🟠 High | CSRF only | Add rate limiting | P2 |
| File upload flooding | 🟠 High | Size limits only | Add rate limiting | P2 |
| PDF memory exhaustion | 🟡 Medium | None | Add memory limits + rate limiting | P2 |
| CSV import size | 🟡 Medium | Session threshold | Add hard limits | P2 |
| External API timeouts | 🟡 Medium | Basic timeouts | Add circuit breaker | P3 |
| Session size | 🟢 Low | File fallback for imports | Add monitoring | P3 |
| Database connections | 🟢 Low | PDO pooling | Configure DB limits | P3 |

---

## Conclusion

The RC Flight Operations application has **good foundational security** with rate limiting on critical authentication and AMA verification endpoints. However, several **resource-intensive operations lack protection** against abuse.

### Key Strengths:
- ✅ Strong authentication and authorization
- ✅ Rate limiting on login and public AMA verification
- ✅ File upload validation
- ✅ SQL injection protection
- ✅ CSRF protection

### Key Weaknesses:
- ❌ No rate limiting on Stripe webhook
- ❌ No rate limiting on membership submissions
- ❌ No rate limiting on file uploads
- ❌ No resource limits on PDF generation
- ❌ Relies heavily on infrastructure-level protection

### Recommended Next Steps:
1. **Immediate:** Implement rate limiting on Stripe webhook (30 min)
2. **Short-term:** Add rate limiting to remaining public endpoints (2-4 hours)
3. **Medium-term:** Implement resource limits and monitoring (1-2 days)
4. **Long-term:** Consider infrastructure upgrades (WAF, CDN) for production deployments

The application is **suitable for small club use** (100-500 members) with basic infrastructure protection, but would need additional hardening for larger deployments or public-facing high-traffic scenarios.
