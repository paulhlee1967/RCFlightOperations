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

    public function testMembersUrlEncodesFlagArray(): void
    {
        $url = membersUrl(['status' => 'current', 'flag' => ['free', 'life']]);

        $this->assertStringContainsString('status=current', $url);
        $this->assertStringContainsString('flag', $url);
        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $parsed);
        $this->assertSame(['free', 'life'], $parsed['flag']);
    }

    public function testToggleFlagParamsAddsAndRemovesFlags(): void
    {
        $base = ['status' => 'current'];
        $withFree = members_list_toggle_flag_params($base, [], 'free');
        $this->assertSame(['free'], $withFree['flag']);

        $withBoth = members_list_toggle_flag_params($withFree, ['free'], 'life');
        $this->assertSame(['free', 'life'], $withBoth['flag']);

        $removed = members_list_toggle_flag_params($withBoth, ['free', 'life'], 'free');
        $this->assertSame(['life'], $removed['flag']);
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

    public function testExportFilterHiddenInputsPreserveStatusAndFlags(): void
    {
        ob_start();
        members_list_export_filter_hidden_inputs([
            'status' => 'inactive',
            'flag'   => ['life', 'free'],
            'q'      => 'smith',
            'page'   => 2,
            'per'    => 50,
        ]);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('name="status"', $html);
        $this->assertStringContainsString('value="inactive"', $html);
        $this->assertStringContainsString('name="flag[]"', $html);
        $this->assertStringContainsString('value="life"', $html);
        $this->assertStringContainsString('name="q"', $html);
        $this->assertStringNotContainsString('name="page"', $html);
        $this->assertStringNotContainsString('name="per"', $html);
    }
}
