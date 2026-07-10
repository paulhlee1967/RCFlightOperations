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
}
