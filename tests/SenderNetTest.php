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

    public function test_format_channel_status(): void
    {
        $active = ['status' => ['email' => 'active', 'temail' => 'active']];
        $this->assertSame(['text' => 'Active', 'badge' => 'bg-success'], sender_net_format_channel_status($active, 'email'));
        $this->assertSame(['text' => 'Unsubscribed', 'badge' => 'bg-secondary'], sender_net_format_channel_status([
            'status' => ['email' => 'unsubscribed'],
        ], 'email'));
        $this->assertSame(['text' => 'Bounced', 'badge' => 'bg-danger'], sender_net_format_channel_status([
            'status' => ['email' => 'bounced'],
        ], 'email'));
        $this->assertSame(['text' => 'Not in Sender.net', 'badge' => 'bg-secondary'], sender_net_format_channel_status(null, 'email'));
    }

    public function test_dashboard_subscribers_url(): void
    {
        $this->assertSame('https://app.sender.net/subscribers', sender_net_dashboard_subscribers_url());
        $this->assertSame('https://app.sender.net/subscribers/o2lk68Y', sender_net_dashboard_subscribers_url('o2lk68Y'));
    }

    public function test_member_email_status_without_email(): void
    {
        $status = sender_net_member_email_status(null, ['email' => '']);
        $this->assertFalse($status['show']);
        $this->assertSame('no_email', $status['state']);
    }

    public function test_member_email_status_not_configured(): void
    {
        $status = sender_net_member_email_status(null, ['email' => 'member@example.com']);
        $this->assertFalse($status['show']);
        $this->assertSame('not_configured', $status['state']);
    }

    public function test_member_email_status_ok(): void
    {
        $status = sender_net_member_email_status(null, [
            'email' => 'member@example.com',
        ]);
        if (!sender_net_is_configured(sender_net_load_config(null))) {
            $this->markTestSkipped('Sender.net API token not configured in config.php');
        }

        $this->assertContains($status['state'], ['ok', 'not_found', 'error']);
        if ($status['state'] === 'ok') {
            $this->assertNotSame([], $status['rows']);
            $this->assertStringStartsWith('https://app.sender.net/subscribers', $status['dashboard_url']);
        }
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
