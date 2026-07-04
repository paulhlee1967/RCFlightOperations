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

    public function test_transactional_email_active_and_may_send_reminder(): void
    {
        $this->assertTrue(sender_net_transactional_email_active(['status' => ['temail' => 'active']]));
        $this->assertFalse(sender_net_transactional_email_active(['status' => ['temail' => 'unsubscribed']]));

        $this->assertTrue(sender_net_may_send_reminder([
            'status' => ['email' => 'active', 'temail' => 'active'],
        ]));
        $this->assertFalse(sender_net_may_send_reminder([
            'status' => ['email' => 'active', 'temail' => 'unsubscribed'],
        ]));
        // Campaign unsubscribed does not block reminders.
        $this->assertTrue(sender_net_may_send_reminder([
            'status' => ['email' => 'unsubscribed', 'temail' => 'active'],
        ]));
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
        $this->assertStringContainsString('https://example.com/u', sender_net_unsubscribe_plain_text_line('https://example.com/u'));
        $this->assertStringContainsString('html version', strtolower(sender_net_unsubscribe_plain_text_line()));
    }

    public function test_unsubscribe_token(): void
    {
        $token = sender_net_unsubscribe_sign_token('User@Example.com', 'test-secret');
        $this->assertNotSame('', $token);
        $this->assertTrue(sender_net_unsubscribe_verify_token('user@example.com', $token, 'test-secret'));
        $this->assertFalse(sender_net_unsubscribe_verify_token('user@example.com', 'bad', 'test-secret'));
    }

    public function test_liquid_variables(): void
    {
        $vars = sender_net_liquid_variables('User@Example.com', 'Jane', 'Doe');
        $this->assertSame([
            'email'     => 'user@example.com',
            'firstname' => 'Jane',
            'lastname'  => 'Doe',
        ], $vars);

        $minimal = sender_net_liquid_variables('  test@club.org  ', '', null);
        $this->assertSame(['email' => 'test@club.org'], $minimal);
    }

    public function test_prepare_html_for_api(): void
    {
        $html = '<a href="{{ unsubscribe_link }}">click here</a>';
        $this->assertSame(
            '<a href="{{unsubscribe_link}}">click here</a>',
            sender_net_prepare_html_for_api($html)
        );
    }

    public function test_subscriber_in_group(): void
    {
        $groupId = 'eZVD4w';
        $subscriber = [
            'subscriber_tags' => [
                ['id' => 'other', 'title' => 'Other'],
                ['id' => $groupId, 'title' => 'Members'],
            ],
        ];

        $this->assertTrue(sender_net_subscriber_in_group($subscriber, $groupId));
        $this->assertFalse(sender_net_subscriber_in_group($subscriber, 'missing'));
        $this->assertFalse(sender_net_subscriber_in_group(null, $groupId));
        $this->assertFalse(sender_net_subscriber_in_group($subscriber, ''));
    }

    public function test_build_transactional_request(): void
    {
        $request = sender_net_build_transactional_request(
            'User@Example.com',
            'Jane Doe',
            'Test subject',
            '<p>Hello {{ firstname }}</p>',
            'Hello plain',
            ['email' => 'from@pvmac.com', 'name' => 'Club'],
            ['api_token' => 'x'],
            ['email' => 'user@example.com', 'firstname' => 'Jane']
        );

        $this->assertSame('POST', $request['method']);
        $this->assertSame('/message/send', $request['path']);
        $this->assertSame('user@example.com', $request['payload']['to']['email']);
        $this->assertSame('<p>Hello {{firstname}}</p>', $request['payload']['html']);
    }
}
