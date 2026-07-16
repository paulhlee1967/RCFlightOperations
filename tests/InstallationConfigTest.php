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
            ['general', 'applications', 'email', 'board_packet', 'tools'],
            array_keys(installation_tabs())
        );
    }

    public function test_installation_normalize_tab_defaults_unknown(): void
    {
        $this->assertSame('general', installation_normalize_tab(''));
        $this->assertSame('general', installation_normalize_tab('nope'));
        $this->assertSame('email', installation_normalize_tab('email'));
    }

    public function test_installation_tab_config_keys_are_section_scoped(): void
    {
        $general = installation_tab_config_keys('general');
        $this->assertContains('app_name', $general);
        $this->assertNotContains('smtp_host', $general);
        $this->assertNotContains('board_packet_recipients', $general);

        $email = installation_tab_config_keys('email');
        $this->assertContains('smtp_host', $email);
        $this->assertContains('sender_api_token', $email);
        $this->assertNotContains('app_name', $email);

        $board = installation_tab_config_keys('board_packet');
        $this->assertSame(
            ['board_packet_enabled', 'board_packet_send_day', 'board_packet_recipients'],
            $board
        );

        $this->assertSame([], installation_tab_config_keys('tools'));
    }
}
