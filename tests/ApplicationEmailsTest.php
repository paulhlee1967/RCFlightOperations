<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/application_emails.php';
require_once dirname(__DIR__) . '/includes/member_applications.php';

final class ApplicationEmailsTest extends TestCase
{
    public function testRequestInfoDedupKeyIsStableWithinBucket(): void
    {
        $key1 = application_request_info_dedup_key(5, 9, "Please send your FAA card.");
        $key2 = application_request_info_dedup_key(5, 9, "Please send your FAA card.");
        $this->assertSame($key1, $key2);
    }

    public function testRequestInfoDedupKeyDiffersForDistinctMessages(): void
    {
        $key1 = application_request_info_dedup_key(5, 9, 'Need AMA card');
        $key2 = application_request_info_dedup_key(5, 9, 'Need FAA card');
        $this->assertNotSame($key1, $key2);
    }

    public function testRequestInfoDedupKeyNormalizesWhitespace(): void
    {
        $key1 = application_request_info_dedup_key(5, 9, "Line one\n\nLine two");
        $key2 = application_request_info_dedup_key(5, 9, 'Line one Line two');
        $this->assertSame($key1, $key2);
    }

    public function testTemplateVarsIncludeMailingAddress(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturnCallback(function () {
            throw new RuntimeException('no db');
        });

        $vars = application_email_template_vars($pdo, [
            'id' => 12,
            'first_name' => 'Ada',
            'last_name' => 'Flyer',
            'email' => 'ada@example.com',
            'wpforms_entry_id' => 'APP-12',
            'payment_total' => 130.0,
            'address_street' => '1 Field Rd',
            'address_city' => 'Claremont',
            'address_state' => 'CA',
            'address_postal_code' => '91711',
        ]);

        $this->assertSame('Ada', $vars['first_name']);
        $this->assertSame('APP-12', $vars['reference']);
        $this->assertStringContainsString('1 Field Rd', $vars['mailing_address']);
        $this->assertStringContainsString('Claremont', $vars['mailing_address']);
        $this->assertArrayHasKey('club_name', $vars);
        $this->assertArrayHasKey('footer_note', $vars);
    }

    public function testReceivedTemplateUsesClubNameInSubject(): void
    {
        $rendered = render_email_template('application_received', [
            'club_name'        => 'Test RC Club',
            'first_name'       => 'Ada',
            'payment_total'    => 50.0,
            'mailing_address'  => '1 Field Rd',
            'reference'        => 'APP-1',
            'support_email'    => 'membership@example.com',
            'footer_note'      => 'Footer',
            'eyebrow'          => 'Membership Application',
        ], null);

        $this->assertStringContainsString('Test RC Club', $rendered['subject']);
        $this->assertStringContainsString('membership application received', strtolower($rendered['subject']));
        $this->assertStringContainsString('Ada', $rendered['html']);
        $this->assertStringContainsString('$50.00', $rendered['html']);
        $this->assertStringNotContainsString('PVMAC', $rendered['html']);
    }

    public function testApprovedTemplateRendersBrandedBody(): void
    {
        $rendered = render_email_template('application_approved', [
            'club_name'     => 'Test RC Club',
            'first_name'    => 'Ada',
            'reference'     => 'APP-2',
            'support_email' => 'help@example.com',
            'footer_note'   => 'Footer',
            'eyebrow'       => 'Membership Application',
        ], null);

        $this->assertStringContainsString('approved', strtolower($rendered['subject']));
        $this->assertStringContainsString('has been approved', $rendered['html']);
        $this->assertStringContainsString('help@example.com', $rendered['html']);
    }

    public function testRequestInfoTemplateIncludesStaffMessage(): void
    {
        $rendered = render_email_template('application_request_info', [
            'club_name'       => 'Test RC Club',
            'first_name'      => 'Ada',
            'reference'       => 'APP-3',
            'support_email'   => 'help@example.com',
            'request_message' => "Please upload a clearer FAA card.\nThanks!",
            'footer_note'     => 'Footer',
            'eyebrow'         => 'Membership Application',
        ], null);

        $this->assertStringContainsString('additional information', strtolower($rendered['subject']));
        $this->assertStringContainsString('clearer FAA card', $rendered['html']);
        $this->assertStringContainsString('pending', strtolower($rendered['html']));
    }

    public function testReviewableStatusIncludesPendingPayment(): void
    {
        $this->assertTrue(application_is_reviewable_status('pending'));
        $this->assertTrue(application_is_reviewable_status('pending_payment'));
        $this->assertFalse(application_is_reviewable_status('approved'));
    }
}
