<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/membership_comp_invites.php';

final class MembershipCompInvitesTest extends TestCase
{
    public function testInviteMatchesEmailAndAma(): void
    {
        $invite = ['email' => 'jane@example.com', 'ama_number' => '123456'];
        $this->assertTrue(membership_comp_invite_row_matches($invite, '123456', 'jane@example.com'));
        $this->assertFalse(membership_comp_invite_row_matches($invite, '123456', 'other@example.com'));
        $this->assertFalse(membership_comp_invite_row_matches($invite, '999999', 'jane@example.com'));
    }

    public function testInviteMatchesEmailOnly(): void
    {
        $invite = ['email' => 'jane@example.com', 'ama_number' => ''];
        $this->assertTrue(membership_comp_invite_row_matches($invite, '123456', 'jane@example.com'));
        $this->assertTrue(membership_comp_invite_row_matches($invite, '', 'jane@example.com'));
        $this->assertFalse(membership_comp_invite_row_matches($invite, '123456', 'other@example.com'));
    }

    public function testInviteMatchesAmaOnly(): void
    {
        $invite = ['email' => '', 'ama_number' => '123456'];
        $this->assertTrue(membership_comp_invite_row_matches($invite, '123456', ''));
        $this->assertFalse(membership_comp_invite_row_matches($invite, '999999', 'jane@example.com'));
    }

    public function testInviteOpenStates(): void
    {
        $now = new DateTimeImmutable('2026-08-01 12:00:00');
        $this->assertFalse(membership_comp_invite_is_open(['redeemed_at' => '2026-01-01'], $now));
        $this->assertFalse(membership_comp_invite_is_open(['cancelled_at' => '2026-01-01'], $now));
        $this->assertFalse(membership_comp_invite_is_open(['expires_at' => '2026-07-01 00:00:00'], $now));
        $this->assertTrue(membership_comp_invite_is_open(['expires_at' => '2026-09-01 00:00:00'], $now));
        $this->assertTrue(membership_comp_invite_is_open([], $now));
    }

    public function testInviteMembershipTypeLabels(): void
    {
        $this->assertSame('free membership', membership_comp_invite_type_label('free_membership'));
        $this->assertSame('life member', membership_comp_invite_type_label('life_member'));
        $this->assertSame('free_membership', membership_comp_invite_normalize_type('invalid'));
    }
}
