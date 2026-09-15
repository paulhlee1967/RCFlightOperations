<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MembersListQueryTest extends TestCase
{
    public function testParseRequestDefaults(): void
    {
        $f = members_list_parse_request([]);

        $this->assertSame('', $f['searchQ']);
        $this->assertSame(25, $f['perPage']);
        $this->assertSame(1, $f['page']);
        $this->assertSame('', $f['memberTypeFilter']);
        $this->assertNull($f['memberTypeSlotFilter']);
        $this->assertSame('active', $f['statusFilter']);
        $this->assertSame([], $f['flagFilters']);
        $this->assertSame('', $f['badgeFilter']);
        $this->assertSame('', $f['fulfillmentFilter']);
        $this->assertNull($f['fulfillmentYear']);
        $this->assertSame('name', $f['sort']);
    }

    public function testParseRequestAcceptsActiveStatus(): void
    {
        $f = members_list_parse_request(['status' => 'active']);

        $this->assertSame('active', $f['statusFilter']);
    }

    public function testParseRequestAcceptsCurrentStatusForDashboard(): void
    {
        $f = members_list_parse_request(['status' => 'current']);

        $this->assertSame('current', $f['statusFilter']);
    }

    public function testParseRequestAcceptsStackableFlagFilters(): void
    {
        $f = members_list_parse_request(['flag' => ['free', 'life', 'bogus', 'archived']]);

        $this->assertSame(['free', 'life'], $f['flagFilters']);
    }

    public function testParseRequestMigratesLegacyStatusToFlags(): void
    {
        $f = members_list_parse_request(['status' => 'free']);

        $this->assertSame('all', $f['statusFilter']);
        $this->assertSame(['free'], $f['flagFilters']);
    }

    public function testParseRequestAcceptsInactiveStatus(): void
    {
        $f = members_list_parse_request(['status' => 'inactive']);

        $this->assertSame('inactive', $f['statusFilter']);
        $this->assertSame([], $f['flagFilters']);
    }

    public function testParseRequestMapsLegacyArchivedStatusToInactive(): void
    {
        $f = members_list_parse_request(['status' => 'archived']);

        $this->assertSame('inactive', $f['statusFilter']);
    }

    public function testParseRequestRejectsInvalidStatusFilter(): void
    {
        $f = members_list_parse_request(['status' => 'expired']);

        $this->assertSame('active', $f['statusFilter']);
    }

    public function testStatusFilterKeysPartitionOnInactiveFlag(): void
    {
        $this->assertSame(['all', 'active', 'inactive'], membersListStatusFilterKeys());
        $this->assertSame(['all', 'active', 'inactive', 'current'], membersListAcceptedStatusFilters());
    }

    public function testMemberStatusFilterWhereSqlForActiveAndInactive(): void
    {
        $activeSql = memberStatusFilterWhereSql('active', 2026, 'm')['sql'];
        $this->assertStringContainsString('m.inactive = 0', $activeSql);

        $inactiveSql = memberStatusFilterWhereSql('inactive', 2026, 'm')['sql'];
        $this->assertStringContainsString('m.inactive = 1', $inactiveSql);
    }

    public function testFlagFilterKeysDoNotIncludeArchived(): void
    {
        $this->assertSame(['free', 'life', 'suspended'], membersListFlagFilterKeys());
    }

    public function testMemberFlagFilterWhereSql(): void
    {
        $this->assertStringContainsString('free_membership = 1', memberFlagFilterWhereSql('free')['sql']);
        $this->assertStringContainsString('life_member = 1', memberFlagFilterWhereSql('life')['sql']);
        $this->assertStringContainsString('suspended = 1', memberFlagFilterWhereSql('suspended')['sql']);
    }

    public function testMembersListCombinedFilterWhereSqlAndsStatusAndFlags(): void
    {
        $sql = membersListCombinedFilterWhereSql('active', ['free', 'life'], 2026, 'm')['sql'];

        $this->assertStringContainsString('inactive = 0', $sql);
        $this->assertStringContainsString('free_membership = 1', $sql);
        $this->assertStringContainsString('life_member = 1', $sql);
    }

    public function testMemberStatusFilterWhereSqlUsesUnaliasedColumnsWhenAliasEmpty(): void
    {
        $sql = memberStatusFilterWhereSql('current', 2026, '')['sql'];

        $this->assertStringNotContainsString('m.', $sql);
        $this->assertStringContainsString('membership_renewal_year', $sql);
    }

    public function testParseRequestRejectsInvalidSortAndPerPage(): void
    {
        $f = members_list_parse_request([
            'sort' => 'DROP TABLE',
            'per'  => 17,
            'page' => 0,
        ]);

        $this->assertSame('name', $f['sort']);
        $this->assertSame(25, $f['perPage']);
        $this->assertSame(1, $f['page']);
    }

    public function testParseRequestAcceptsValidMemberTypeSlot(): void
    {
        $f = members_list_parse_request(['member_type' => '3']);

        $this->assertSame('3', $f['memberTypeFilter']);
        $this->assertSame(3, $f['memberTypeSlotFilter']);
    }

    public function testParseRequestClearsInvalidMemberType(): void
    {
        $f = members_list_parse_request(['member_type' => '9']);

        $this->assertSame('', $f['memberTypeFilter']);
        $this->assertNull($f['memberTypeSlotFilter']);
    }

    public function testParseRequestAcceptsBadgeUnprintedFilter(): void
    {
        $f = members_list_parse_request(['badge' => 'unprinted']);

        $this->assertSame('unprinted', $f['badgeFilter']);
    }

    public function testParseRequestRejectsInvalidBadgeFilter(): void
    {
        $f = members_list_parse_request(['badge' => 'printed']);

        $this->assertSame('', $f['badgeFilter']);
    }

    public function testParseRequestAcceptsFulfillmentPendingFilter(): void
    {
        $f = members_list_parse_request(['fulfillment' => 'pending', 'year' => '2027']);

        $this->assertSame('pending', $f['fulfillmentFilter']);
        $this->assertSame(2027, $f['fulfillmentYear']);
    }

    public function testParseRequestRejectsInvalidFulfillmentFilter(): void
    {
        $f = members_list_parse_request(['fulfillment' => 'done']);

        $this->assertSame('', $f['fulfillmentFilter']);
        $this->assertNull($f['fulfillmentYear']);
    }

    public function testParseRequestCoercesCurrentStatusWhenFulfillmentPending(): void
    {
        $f = members_list_parse_request([
            'status'      => 'current',
            'fulfillment' => 'pending',
            'year'        => '2027',
        ]);

        $this->assertSame('all', $f['statusFilter']);
        $this->assertSame('pending', $f['fulfillmentFilter']);
        $this->assertSame(2027, $f['fulfillmentYear']);
    }

    public function testParseRequestIgnoresYearWithoutFulfillmentFilter(): void
    {
        $f = members_list_parse_request(['year' => '2027']);

        $this->assertNull($f['fulfillmentYear']);
    }

    public function testOrderByMapDefinesExpectedSortKeys(): void
    {
        $map = members_list_order_by_map();

        $this->assertArrayHasKey('name', $map);
        $this->assertArrayHasKey('year_desc', $map);
        $this->assertStringContainsString('m.last_name', $map['name']['search']);
        $this->assertStringContainsString('last_name', $map['name']['main']);
    }

    public function testExportSelectSqlIncludesCoreColumns(): void
    {
        $sql = members_list_export_select_sql();

        $this->assertStringContainsString('first_name', $sql);
        $this->assertStringContainsString('membership_renewal_year', $sql);
        $this->assertStringContainsString('ama_number', $sql);
        $this->assertStringContainsString('address_postal_code', $sql);
    }
}
