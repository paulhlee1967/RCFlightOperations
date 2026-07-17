<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/member_portal.php';

final class MemberPortalTest extends TestCase
{
    public function test_editable_fields_are_allowlisted(): void
    {
        $fields = member_portal_editable_fields();
        $this->assertContains('phone', $fields);
        $this->assertContains('ama_number', $fields);
        $this->assertContains('email_opt_in_expiry_reminders', $fields);
        $this->assertNotContains('email', $fields);
        $this->assertNotContains('first_name', $fields);
        $this->assertNotContains('membership_renewal_year', $fields);
        $this->assertNotContains('notes', $fields);
    }

    public function test_hash_token_is_sha256_hex(): void
    {
        $hash = member_portal_hash_token('abc');
        $this->assertSame(64, strlen($hash));
        $this->assertSame(hash('sha256', 'abc'), $hash);
    }

    public function test_validate_input_accepts_contact_and_prefs(): void
    {
        [$errors, $clean] = member_portal_validate_input([
            'phone' => '555-1212',
            'address_city' => 'Pomona',
            'address_state' => 'CA',
            'email_opt_in_club_events' => '1',
            'email_opt_in_expiry_reminders' => '0',
            'ama_life_member' => '1',
        ]);
        $this->assertSame([], $errors);
        $this->assertSame('555-1212', $clean['phone']);
        $this->assertSame('Pomona', $clean['address_city']);
        $this->assertSame(1, $clean['email_opt_in_club_events']);
        $this->assertSame(0, $clean['email_opt_in_expiry_reminders']);
        $this->assertSame(1, $clean['ama_life_member']);
    }

    public function test_validate_input_rejects_bad_dates(): void
    {
        [$errors] = member_portal_validate_input([
            'ama_expiration' => 'not-a-date',
            'faa_expiration' => '2026-13-40',
        ]);
        $this->assertArrayHasKey('ama_expiration', $errors);
        $this->assertArrayHasKey('faa_expiration', $errors);
    }

    public function test_field_diff_reports_changed_allowlisted_fields_only(): void
    {
        $before = [
            'phone' => '111',
            'email' => 'a@example.com',
            'ama_number' => '123',
            'notes' => 'secret',
        ];
        $after = [
            'phone' => '222',
            'email' => 'b@example.com',
            'ama_number' => '123',
            'notes' => 'changed',
            'email_opt_in_club_events' => 1,
            'email_opt_in_expiry_reminders' => 1,
            'ama_life_member' => 0,
            'address_street' => null,
            'address_street2' => null,
            'address_city' => null,
            'address_state' => null,
            'address_postal_code' => null,
            'emergency_contact_name' => null,
            'emergency_contact_relationship' => null,
            'emergency_contact_phone' => null,
            'ama_expiration' => null,
            'faa_number' => null,
            'faa_expiration' => null,
        ];
        $diff = member_portal_field_diff($before, $after);
        $this->assertArrayHasKey('phone', $diff);
        $this->assertSame('111', $diff['phone']['from']);
        $this->assertSame('222', $diff['phone']['to']);
        $this->assertArrayNotHasKey('email', $diff);
        $this->assertArrayNotHasKey('notes', $diff);
        $this->assertArrayNotHasKey('ama_number', $diff);
    }

    public function test_link_url_uses_public_base(): void
    {
        $url = member_portal_link_url('tok123', ['public_base_url' => 'https://club.example/flightops']);
        $this->assertSame('https://club.example/flightops/membership_link.php?token=tok123', $url);
    }

    public function test_normalize_token_strips_email_junk(): void
    {
        $raw = 'AbCdEf0123456789abcdef0123456789abcdef0123456789abcdef0123456789>';
        $this->assertSame(
            'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789',
            member_portal_normalize_token($raw)
        );
    }

    public function test_format_change_lines_for_staff_email(): void
    {
        $lines = member_portal_format_change_lines([
            'phone' => ['from' => '111', 'to' => '222'],
            'photo_path' => ['from' => 'a.jpg', 'to' => 'b.jpg'],
            'email_opt_in_club_events' => ['from' => 0, 'to' => 1],
        ]);
        $this->assertContains('Phone: 111 → 222', $lines);
        $this->assertContains('Badge photo: updated', $lines);
        $this->assertContains('Club event emails: no → yes', $lines);
    }

    public function test_request_page_url_uses_public_base(): void
    {
        $this->assertSame(
            'https://club.example/flightops/membership.php',
            member_portal_request_page_url(['public_base_url' => 'https://club.example/flightops'])
        );
    }

    public function test_request_page_url_prefills_email(): void
    {
        $this->assertSame(
            'https://club.example/flightops/membership.php?email=member%40club.org',
            member_portal_request_page_url(
                ['public_base_url' => 'https://club.example/flightops'],
                'Member@Club.org'
            )
        );
    }

    public function test_session_helpers_clear_expired(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['member_portal_id'] = 42;
        $_SESSION['member_portal_active_at'] = time() - MEMBER_PORTAL_SESSION_TTL - 10;
        $this->assertSame(0, member_portal_current_member_id());
        $this->assertArrayNotHasKey('member_portal_id', $_SESSION);
    }

    public function test_session_start_and_current(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        member_portal_session_start(7);
        $this->assertSame(7, member_portal_current_member_id());
        member_portal_session_clear();
        $this->assertSame(0, member_portal_current_member_id());
    }
}
