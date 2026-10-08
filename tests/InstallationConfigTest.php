<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/installation_config.php';

final class InstallationConfigTest extends TestCase
{
    public function test_application_notify_recipient_prefers_membership_email(): void
    {
        $this->assertSame(
            'membership@pvmac.com',
            application_notify_recipient_email([
                'membership_email' => 'membership@pvmac.com',
                'support_email'    => 'support@pvmac.com',
            ])
        );
    }

    public function test_application_notify_recipient_falls_back_to_support_email(): void
    {
        $this->assertSame(
            'support@pvmac.com',
            application_notify_recipient_email([
                'membership_email' => '',
                'support_email'    => 'support@pvmac.com',
            ])
        );
    }

    public function test_application_notify_recipient_empty_when_both_blank(): void
    {
        $this->assertSame('', application_notify_recipient_email([]));
    }

    public function test_installation_tabs_are_ordered(): void
    {
        $this->assertSame(
            ['status', 'email', 'payments', 'scheduled'],
            array_keys(installation_tabs())
        );
    }

    public function test_installation_normalize_tab_defaults_unknown(): void
    {
        $this->assertSame('status', installation_normalize_tab(''));
        $this->assertSame('status', installation_normalize_tab('nope'));
        $this->assertSame('email', installation_normalize_tab('email'));
        $this->assertSame('status', installation_normalize_tab('general'));
        $this->assertSame('status', installation_normalize_tab('tools'));
        $this->assertSame('payments', installation_normalize_tab('applications'));
        $this->assertSame('scheduled', installation_normalize_tab('board_packet'));
        $this->assertSame('scheduled', installation_normalize_tab('roster_digest'));
    }

    public function test_installation_tab_config_keys_are_section_scoped(): void
    {
        $status = installation_config_group_keys('status');
        $this->assertSame(['maintenance_mode'], $status);
        $this->assertNotContains('smtp_host', $status);
        $this->assertNotContains('renewal_prebook_start_month', $status);
        $this->assertNotContains('support_email', $status);

        $email = installation_config_group_keys('email');
        $this->assertContains('smtp_host', $email);
        $this->assertContains('sender_api_token', $email);
        $this->assertNotContains('maintenance_mode', $email);

        $board = installation_config_group_keys('board_packet');
        $this->assertSame(
            ['board_packet_enabled', 'board_packet_send_day', 'board_packet_recipients'],
            $board
        );

        $roster = installation_config_group_keys('roster_digest');
        $this->assertSame(
            [
                'current_members_digest_enabled',
                'current_members_digest_weekday',
                'current_members_digest_recipients',
            ],
            $roster
        );
        $this->assertNotContains('current_members_digest_recipients', $board);

        $this->assertSame([], installation_config_group_keys('scheduled'));
        $this->assertSame([], installation_config_group_keys('tools'));
    }

    public function test_prebook_day_clamps_to_month_length(): void
    {
        $this->assertSame('29', installation_posted_config_value('renewal_prebook_start_day', [
            'renewal_prebook_start_month' => '2',
            'renewal_prebook_start_day'   => '31',
        ]));
        $this->assertSame('15', installation_posted_config_value('renewal_prebook_start_day', [
            'renewal_prebook_start_month' => '10',
            'renewal_prebook_start_day'   => '15',
        ]));
    }

    public function test_on_time_window_label_uses_prebook_month_and_day(): void
    {
        $this->assertSame('Oct 15–Dec 31', renewal_on_time_window_label(null, [
            'prebook_month' => 10,
            'prebook_day'   => 15,
            'prorate_start' => 7,
            'prorate_end'   => 10,
        ]));
        $this->assertSame('Nov 1–Dec 31', renewal_on_time_window_label(null, [
            'prebook_month' => 11,
            'prebook_day'   => 1,
            'prorate_start' => 7,
            'prorate_end'   => 10,
        ]));
    }
}
