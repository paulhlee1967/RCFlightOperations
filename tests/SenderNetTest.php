<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SenderNetTest extends TestCase
{
    public function test_normalize_email_lowercase(): void
    {
        $this->assertSame('user@example.com', sender_net_normalize_email('User@Example.COM'));
        $this->assertSame('user@example.com', sender_net_normalize_email('  user@example.com  '));
    }

    public function test_promotional_email_active(): void
    {
        $this->assertTrue(sender_net_promotional_email_active(['status' => ['email' => 'active']]));
        $this->assertTrue(sender_net_promotional_email_active(['status' => ['email' => 'Active']]));
        $this->assertFalse(sender_net_promotional_email_active(['status' => ['email' => 'unsubscribed']]));
        $this->assertFalse(sender_net_promotional_email_active(['status' => ['email' => 'bounced']]));
        $this->assertNull(sender_net_promotional_email_active(null));
        $this->assertNull(sender_net_promotional_email_active(['status' => []]));
    }

    public function test_prepare_recipient_when_not_configured(): void
    {
        $result = sender_net_prepare_recipient('Member@Example.com', 'A', 'B', ['api_token' => '']);
        $this->assertTrue($result['send']);
        $this->assertSame('sender_not_configured', $result['reason']);
        $this->assertSame('member@example.com', $result['normalized_email']);
    }

    public function test_unsubscribe_plain_text_line(): void
    {
        $this->assertStringContainsString('unsubscribe', strtolower(sender_net_unsubscribe_plain_text_line()));
    }
}
