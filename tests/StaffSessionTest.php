<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/auth.php';

final class StaffSessionTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testMissingTimestampIsNotExpired(): void
    {
        $this->assertFalse(staff_session_idle_expired(1_700_000_000, 0));
        $this->assertFalse(staff_session_idle_expired(1_700_000_000, null));
    }

    public function testIdleAfterTtl(): void
    {
        $now = 1_700_000_000;
        $this->assertFalse(staff_session_idle_expired($now, $now - 1799, 1800));
        $this->assertTrue(staff_session_idle_expired($now, $now - 1801, 1800));
    }

    public function testTouchAndClear(): void
    {
        staff_session_touch();
        $this->assertGreaterThan(0, (int) $_SESSION['user_active_at']);
        $_SESSION['user_id'] = 9;
        staff_session_clear();
        $this->assertArrayNotHasKey('user_id', $_SESSION);
        $this->assertArrayNotHasKey('user_active_at', $_SESSION);
    }
}
