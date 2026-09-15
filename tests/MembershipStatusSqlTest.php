<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MembershipStatusSqlTest extends TestCase
{
    public function testCurrentMemberWhereSqlUsesAliasAndYearWithoutPaymentChecks(): void
    {
        $sql = currentMemberWhereSql('m', 2026);

        $this->assertStringContainsString('m.membership_renewal_year = ?', $sql);
        $this->assertStringContainsString('m.inactive = 0', $sql);
        $this->assertStringContainsString('m.suspended = 0', $sql);
        $this->assertStringNotContainsString('payments', $sql);
        $this->assertStringNotContainsString('member_fulfillments', $sql);
        $this->assertStringNotContainsString('life_member', $sql);
    }

    public function testCurrentMemberWhereParamsUsesYearOnce(): void
    {
        $this->assertSame([2026], currentMemberWhereParams(2026));
    }

    public function testNotYetRenewedCombinesPriorAndCurrentYearParams(): void
    {
        $params = notYetRenewedWhereParams(2026);
        $this->assertCount(2, $params);
        $this->assertSame([2025, 2026], $params);
    }

    public function testMemberIsCurrentIgnoresLifeAndPaymentHints(): void
    {
        $this->assertTrue(memberIsCurrent([
            'membership_renewal_year' => 2026,
            'inactive' => 0,
            'suspended' => 0,
            'life_member' => 0,
            'free_membership' => 0,
        ], 2026));

        $this->assertFalse(memberIsCurrent([
            'membership_renewal_year' => 2026,
            'inactive' => 1,
            'suspended' => 0,
            'life_member' => 1,
            'free_membership' => 1,
        ], 2026));

        $this->assertFalse(memberIsCurrent([
            'membership_renewal_year' => 2010,
            'inactive' => 0,
            'suspended' => 0,
            'life_member' => 1,
        ], 2026));
    }

    public function testFulfillmentPendingWhereSqlQualifiesMemberIdWhenUnaliased(): void
    {
        $sql = fulfillmentPendingWhereSql('');

        $this->assertStringContainsString('f.member_id = members.id', $sql);
        $this->assertStringNotContainsString('f.member_id = id', $sql);
    }

    public function testFulfillmentPendingWhereSqlUsesAliasWhenProvided(): void
    {
        $sql = fulfillmentPendingWhereSql('m');

        $this->assertStringContainsString('f.member_id = m.id', $sql);
    }
}
