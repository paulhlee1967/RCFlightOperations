<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MembersListHelpersTest extends TestCase
{
    public function testMembersUrlPreservesQueryParams(): void
    {
        $url = membersUrl(['status' => 'current', 'sort' => 'year'], 3);

        $this->assertSame('members.php?status=current&sort=year&page=3', $url);
    }

    public function testMembersUrlOmitsPageWhenNull(): void
    {
        $this->assertSame('members.php?status=inactive', membersUrl(['status' => 'inactive']));
        $this->assertSame('members.php', membersUrl([]));
    }

    public function testInitialsColorIsDeterministic(): void
    {
        $a = members_initials_color('Jane Pilot');
        $b = members_initials_color('Jane Pilot');
        $c = members_initials_color('Other Name');

        $this->assertSame($a, $b);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $a);
        $this->assertNotSame($a, $c);
    }

    public function testTypeBadgeEscapesLabelHtml(): void
    {
        $html = members_type_badge(1, [1 => 'Adult <script>']);

        $this->assertStringContainsString('Adult &lt;script&gt;', $html);
        $this->assertStringContainsString('bg-primary', $html);
    }

    public function testYearBadgeReflectsCurrentDueAndLapsed(): void
    {
        $currentYear = 2026;

        $this->assertStringContainsString('badge-year-current', members_year_badge(2026, $currentYear));
        $this->assertStringContainsString('badge-year-due', members_year_badge(2025, $currentYear));
        $this->assertStringContainsString('badge-year-lapsed', members_year_badge(2024, $currentYear));
        $this->assertStringContainsString('—', members_year_badge(0, $currentYear));
    }
}
