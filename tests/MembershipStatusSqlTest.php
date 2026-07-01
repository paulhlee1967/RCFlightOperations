<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MembershipStatusSqlTest extends TestCase
{
    public function testCurrentMemberWhereSqlUsesAliasAndYear(): void
    {
        $sql = currentMemberWhereSql('m', 2026);

        $this->assertStringContainsString('m.membership_renewal_year = ?', $sql);
        $this->assertStringContainsString('m.inactive = 0', $sql);
        $this->assertStringContainsString('p.member_id = m.id', $sql);
    }

    public function testCurrentMemberWhereParamsRepeatsYearThreeTimes(): void
    {
        $this->assertSame([2026, 2026, 2026], currentMemberWhereParams(2026));
    }

    public function testNotYetRenewedCombinesPriorAndCurrentYearParams(): void
    {
        $params = notYetRenewedWhereParams(2026);
        $this->assertCount(6, $params);
        $this->assertSame([2025, 2025, 2025, 2026, 2026, 2026], $params);
    }
}
