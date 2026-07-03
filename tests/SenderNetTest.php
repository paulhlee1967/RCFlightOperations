<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SenderNetTest extends TestCase
{
    public function test_promotional_email_active(): void
    {
        $this->assertTrue(sender_net_promotional_email_active(['status' => ['email' => 'active']]));
        $this->assertTrue(sender_net_promotional_email_active(['status' => ['email' => 'Active']]));
        $this->assertFalse(sender_net_promotional_email_active(['status' => ['email' => 'unsubscribed']]));
        $this->assertFalse(sender_net_promotional_email_active(['status' => ['email' => 'bounced']]));
        $this->assertNull(sender_net_promotional_email_active(null));
        $this->assertNull(sender_net_promotional_email_active(['status' => []]));
    }

    public function test_may_email_when_not_configured(): void
    {
        $result = sender_net_may_email_recipient('member@example.com', ['api_token' => '']);
        $this->assertTrue($result['send']);
        $this->assertSame('sender_not_configured', $result['reason']);
    }

    public function test_unsubscribe_url_template(): void
    {
        $config = ['unsubscribe_url' => 'https://example.com/u/{email}?id={id}'];
        $url = sender_net_unsubscribe_url('a@b.com', 'sub123', $config);
        $this->assertSame('https://example.com/u/a%40b.com?id=sub123', $url);
    }

    public function test_append_unsubscribe_text(): void
    {
        $text = sender_net_append_unsubscribe_text("Hello\n", 'https://example.com/unsub');
        $this->assertStringContainsString('Unsubscribe from club emails: https://example.com/unsub', $text);
    }
}
