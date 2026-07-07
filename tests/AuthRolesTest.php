<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/auth.php';

final class AuthRolesTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function asRole(string $role): void
    {
        $_SESSION['user_id'] = 42;
        $_SESSION['user_role'] = $role;
    }

    public function testLegacyRoleSlugsNormalize(): void
    {
        $this->assertSame('manager', normalizeUserRole('editor'));
        $this->assertSame('staff', normalizeUserRole('treasurer'));
        $this->assertSame('report_viewer', normalizeUserRole('viewer'));
        $this->assertSame('admin', normalizeUserRole('admin'));
    }

    public function testReportViewerCannotViewMembers(): void
    {
        $this->asRole('report_viewer');
        $this->assertTrue(canViewReports());
        $this->assertFalse(canViewMembers());
        $this->assertFalse(canEditMembers());
        $this->assertFalse(canProcessMemberships());
    }

    public function testStaffCanProcessMembershipsButNotEditMembers(): void
    {
        $this->asRole('staff');
        $this->assertTrue(canViewMembers());
        $this->assertTrue(canProcessMemberships());
        $this->assertFalse(canEditMembers());
        $this->assertTrue(canManagePayments());
    }

    public function testManagerCanEditMembers(): void
    {
        $this->asRole('manager');
        $this->assertTrue(canEditMembers());
        $this->assertTrue(canProcessMemberships());
        $this->assertFalse(canManageUsers());
    }

    public function testLegacyTreasurerSessionMapsToStaffPermissions(): void
    {
        $this->asRole('treasurer');
        $this->assertSame('staff', currentUserRole());
        $this->assertTrue(canProcessMemberships());
        $this->assertFalse(canEditMembers());
    }

    public function testSystemUserRolesUseNewSlugs(): void
    {
        $this->assertSame(
            ['admin', 'manager', 'staff', 'report_viewer'],
            array_keys(getSystemUserRoles())
        );
    }
}
