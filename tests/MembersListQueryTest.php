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
        $this->assertSame('current', $f['statusFilter']);
        $this->assertSame('', $f['badgeFilter']);
        $this->assertSame('name', $f['sort']);
    }

    public function testParseRequestMapsActiveStatusToCurrent(): void
    {
        $f = members_list_parse_request(['status' => 'active']);

        $this->assertSame('current', $f['statusFilter']);
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

    public function testOrderByMapDefinesExpectedSortKeys(): void
    {
        $map = members_list_order_by_map();

        $this->assertArrayHasKey('name', $map);
        $this->assertArrayHasKey('year_desc', $map);
        $this->assertStringContainsString('m.last_name', $map['name']['search']);
        $this->assertStringContainsString('last_name', $map['name']['main']);
    }
}
