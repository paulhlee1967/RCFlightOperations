<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/dues_helpers.php';
require_once dirname(__DIR__) . '/includes/sender_net.php';
require_once dirname(__DIR__) . '/includes/membership_application.php';

final class MembershipApplicationTest extends TestCase
{
    private function sampleRules(): array
    {
        return [
            1 => [
                'annual_dues' => 160.0,
                'prorated_dues' => 80.0,
                'initiation_fee' => 50.0,
                'prorate_start_month' => 7,
                'prorate_end_month' => 10,
            ],
            2 => [
                'annual_dues' => 20.0,
                'prorated_dues' => 20.0,
                'initiation_fee' => 0.0,
                'prorate_start_month' => 7,
                'prorate_end_month' => 10,
            ],
        ];
    }

    public function testStripeProcessingFeeFormula(): void
    {
        $fee = membership_application_stripe_processing_fee(210.0);
        $this->assertGreaterThan(6.0, $fee);
        $this->assertLessThan(7.5, $fee);
        $this->assertSame(0.0, membership_application_stripe_processing_fee(0.0));
    }

    public function testNewMemberSeasonBoundaries(): void
    {
        $pdo = $this->createMock(PDO::class);
        $this->assertSame('regular_new', membership_application_new_member_season(new DateTimeImmutable('2026-03-15'), $pdo));
        $this->assertSame('prorated_new', membership_application_new_member_season(new DateTimeImmutable('2026-08-01'), $pdo));
    }

    public function testCouponWaivesPayment(): void
    {
        $pdo = $this->createMock(PDO::class);
        $quote = membership_application_quote($pdo, 'new', 1, 'PAULTEST', new DateTimeImmutable('2026-08-01'));
        $this->assertTrue($quote['waive_payment']);
        $this->assertSame(0.0, $quote['total']);
    }

    public function testRenewalQuoteUsesOnTimeDues(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturnCallback(function () {
            throw new RuntimeException('no db');
        });

        // Quote internally calls calculateDues with enabled labels — mock via renewal open date in Oct
        $quote = membership_application_quote($pdo, 'renewal', 1, null, new DateTimeImmutable('2026-11-01'));
        $this->assertSame('renewal', $quote['kind']);
        $this->assertSame('on_time', $quote['renewal_type']);
    }

    public function testConfirmationTokenRoundTrip(): void
    {
        $secret = 'test-secret';
        $token = membership_application_confirmation_token(42, $secret);
        $this->assertTrue(membership_application_verify_confirmation_token(42, $token, $secret));
        $this->assertFalse(membership_application_verify_confirmation_token(43, $token, $secret));
    }

    public function testEmailOptInFromPost(): void
    {
        $this->assertSame(0, email_opt_in_from_post(null));
        $this->assertSame(0, email_opt_in_from_post(''));
        $this->assertSame(0, email_opt_in_from_post('0'));
        $this->assertSame(1, email_opt_in_from_post('1'));
        $this->assertSame(1, email_opt_in_from_post(1));
        $this->assertSame(1, email_opt_in_from_post('on'));
    }

    public function testEmailOptInApplicationSummary(): void
    {
        $none = email_opt_in_application_summary([]);
        $this->assertSame(['No optional emails selected'], $none);

        $both = email_opt_in_application_summary([
            'email_opt_in_club_events' => 1,
            'email_opt_in_expiry_reminders' => 1,
        ]);
        $this->assertCount(2, $both);

        $eventsOnly = email_opt_in_application_summary(['email_opt_in_club_events' => 1]);
        $this->assertSame(['Club events & announcements'], $eventsOnly);
    }

    public function testMemberWantsExpiryReminderEmails(): void
    {
        $this->assertTrue(member_wants_expiry_reminder_emails([]));
        $this->assertTrue(member_wants_expiry_reminder_emails(['email_opt_in_expiry_reminders' => 1]));
        $this->assertFalse(member_wants_expiry_reminder_emails(['email_opt_in_expiry_reminders' => 0]));
    }

    public function testLocalUploadPathDetection(): void
    {
        $this->assertTrue(membership_application_is_local_upload_path('uploads/applications/5/badge.jpg'));
        $this->assertFalse(membership_application_is_local_upload_path('https://pvmac.com/file.jpg'));
    }

    public function testApplicationFileHrefUsesSecureEndpointForLocalFiles(): void
    {
        $app = [
            'id' => 12,
            'file_signature_url' => 'uploads/applications/12/signature.png',
            'file_badge_photo_url' => 'https://pvmac.com/photo.jpg',
        ];
        $this->assertSame(
            'application_file.php?application_id=12&kind=signature',
            application_file_href($app, 'signature')
        );
        $this->assertSame('https://pvmac.com/photo.jpg', application_file_href($app, 'badge'));
    }

    public function testValidateInputAcceptsUsStyleDates(): void
    {
        $pdo = $this->createMock(PDO::class);
        membership_application_ama_set_session([
            'ama_number'         => '123456',
            'last_name'          => 'User',
            'first_name'         => 'Test',
            'ama_expiration_ymd' => '2026-12-31',
            'ama_expiration_mdy' => '12/31/2026',
            'life_member'        => false,
        ]);
        $context = ['renewal_open' => true];
        $post = [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'phone' => '5551234567',
            'address_street' => '1 Main St',
            'address_city' => 'Claremont',
            'address_state' => 'CA',
            'address_postal_code' => '91711',
            'birthday' => '03/15/1980',
            'application_kind' => 'renewal',
            'membership_type_slot' => '1',
            'terms' => '1',
            'ama_number' => '123456',
            'ama_expiration' => '12/31/2026',
            'faa_number' => 'FA123',
            'faa_expiration' => '06/01/2027',
            'signature_data' => 'data:image/png;base64,aa==',
        ];
        $files = [
            'faa_card' => ['tmp_name' => '', 'size' => 0],
        ];
        $result = membership_application_validate_input($pdo, $post, $files, $context);
        $this->assertArrayNotHasKey('birthday', $result['errors']);
        $this->assertArrayNotHasKey('ama_expiration', $result['errors']);
        $this->assertArrayNotHasKey('ama_verify', $result['errors']);
        $this->assertSame('1980-03-15', $result['clean']['birthday']);
        membership_application_ama_clear_session();
    }

    public function testFaaCardAllowedMimesIncludePdfForApplyUploads(): void
    {
        require_once dirname(__DIR__) . '/includes/member_save.php';

        $mimes = member_faa_card_allowed_mimes();
        $this->assertArrayHasKey('application/pdf', $mimes);
        $this->assertSame('pdf', $mimes['application/pdf']);
    }

    public function testValidateInputFaaCardRequiredMessage(): void
    {
        $pdo = $this->createMock(PDO::class);
        membership_application_ama_clear_session();
        $_SESSION['membership_apply_ama'] = [
            'verified' => true,
            'ama_number' => '123456',
            'last_name' => 'User',
            'first_name' => 'Test',
            'ama_expiration' => '2026-12-31',
        ];
        $context = membership_application_context($pdo, new DateTimeImmutable('2026-08-01'));
        $post = [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'phone' => '555-0100',
            'address_street' => '1 Main St',
            'address_city' => 'Claremont',
            'address_state' => 'CA',
            'address_postal_code' => '91711',
            'birthday' => '03/15/1980',
            'application_kind' => 'renewal',
            'membership_type_slot' => '1',
            'terms' => '1',
            'ama_number' => '123456',
            'ama_expiration' => '12/31/2026',
            'faa_number' => 'FA123',
            'faa_expiration' => '06/01/2027',
            'signature_data' => 'data:image/png;base64,aa==',
        ];
        $files = [
            'faa_card' => ['tmp_name' => '', 'size' => 0],
        ];
        $result = membership_application_validate_input($pdo, $post, $files, $context);
        $this->assertSame('FAA registration file is required.', $result['errors']['faa_card'] ?? '');
        membership_application_ama_clear_session();
    }

    public function testAmaMinimumExpiryBeforePrebook(): void
    {
        $pdo = $this->createMock(PDO::class);
        $now = new DateTimeImmutable('2026-07-09');
        $this->assertSame('2026-12-31', membership_application_ama_minimum_expiry_ymd($pdo, $now));
    }

    public function testAmaMinimumExpiryAfterPrebook(): void
    {
        $pdo = $this->createMock(PDO::class);
        $now = new DateTimeImmutable('2026-11-01');
        $this->assertSame('2027-12-31', membership_application_ama_minimum_expiry_ymd($pdo, $now));
    }

    public function testAmaMeetsMinimumExpiryLifeMember(): void
    {
        $pdo = $this->createMock(PDO::class);
        $this->assertTrue(membership_application_ama_meets_minimum_expiry($pdo, null, true));
        $this->assertTrue(membership_application_ama_meets_minimum_expiry($pdo, '2020-01-01', true));
    }

    public function testAmaMeetsMinimumExpiryRegularMember(): void
    {
        $pdo = $this->createMock(PDO::class);
        $now = new DateTimeImmutable('2026-07-09');
        $this->assertTrue(membership_application_ama_meets_minimum_expiry($pdo, '2026-12-31', false, $now));
        $this->assertFalse(membership_application_ama_meets_minimum_expiry($pdo, '2026-06-30', false, $now));
    }

    public function testAmaAssertVerifiedRequiresSession(): void
    {
        membership_application_ama_clear_session();
        $error = membership_application_ama_assert_verified([
            'ama_number' => '123456',
            'last_name' => 'User',
            'first_name' => 'Test',
            'ama_expiration' => '2026-12-31',
        ]);
        $this->assertSame('AMA membership must be verified before you can submit the application.', $error);
    }

    public function testRenewalEligibilityClosedOutsideRenewalSeason(): void
    {
        $pdo = $this->createMock(PDO::class);
        $now = new DateTimeImmutable('2026-07-09');
        $result = membership_application_renewal_eligibility($pdo, '123456', 'Test', 'User', $now);
        $this->assertFalse($result['eligible']);
        $this->assertStringContainsString('not open', strtolower($result['message']));
    }

    public function testSuspendedMemberApplyBlockMessage(): void
    {
        $this->assertNull(membership_application_club_member_apply_block_message(null));
        $this->assertNull(membership_application_club_member_apply_block_message(['suspended' => 0, 'inactive' => 0]));

        $suspended = membership_application_club_member_apply_block_message(['suspended' => 1]);
        $this->assertNotNull($suspended);
        $this->assertStringContainsString('suspended', strtolower($suspended));

        $this->assertNull(membership_application_club_member_apply_block_message(['inactive' => 1]));
    }

    public function testFormatUsPhone(): void
    {
        $this->assertSame('(555) 123-4567', membership_application_format_us_phone('5551234567'));
        $this->assertSame('(555) 123-4567', membership_application_format_us_phone('15551234567'));
        $this->assertSame('(555) 123-4567', membership_application_format_us_phone('(555) 123-4567'));
        $this->assertSame('', membership_application_format_us_phone(''));
        $this->assertSame('ext 12', membership_application_format_us_phone('ext 12'));
    }

    public function testClubPrefillFromRowFormatsContactFields(): void
    {
        $prefill = membership_application_club_prefill_from_row([
            'email' => ' flyer@example.com ',
            'phone' => '9095551212',
            'birthday' => '1980-03-15',
            'address_street' => '123 Main',
            'address_street2' => 'Apt 4',
            'address_city' => 'Claremont',
            'address_state' => 'CA',
            'address_postal_code' => '91711',
            'emergency_contact_name' => 'Pat',
            'emergency_contact_relationship' => 'Spouse',
            'emergency_contact_phone' => '9095559999',
            'faa_number' => 'FA123',
            'faa_expiration' => '2027-06-01',
            'membership_type_slot' => 2,
        ]);

        $this->assertSame('flyer@example.com', $prefill['email']);
        $this->assertSame('(909) 555-1212', $prefill['phone']);
        $this->assertSame('03/15/1980', $prefill['birthday']);
        $this->assertSame('123 Main', $prefill['address_street']);
        $this->assertSame('Apt 4', $prefill['address_street2']);
        $this->assertSame('Claremont', $prefill['address_city']);
        $this->assertSame('CA', $prefill['address_state']);
        $this->assertSame('91711', $prefill['address_postal_code']);
        $this->assertSame('Pat', $prefill['emergency_contact_name']);
        $this->assertSame('Spouse', $prefill['emergency_contact_relationship']);
        $this->assertSame('(909) 555-9999', $prefill['emergency_contact_phone']);
        $this->assertSame('FA123', $prefill['faa_number']);
        $this->assertSame('06/01/2027', $prefill['faa_expiration']);
        $this->assertSame('2', $prefill['membership_type_slot']);
    }

    public function testNormalizeClubPrefillKeepsMdyDates(): void
    {
        $normalized = membership_application_normalize_club_prefill([
            'email' => 'a@b.com',
            'birthday' => '03/15/1980',
            'faa_expiration' => '06/01/2027',
            'extra' => 'ignored',
        ]);
        $this->assertSame('a@b.com', $normalized['email']);
        $this->assertSame('03/15/1980', $normalized['birthday']);
        $this->assertSame('06/01/2027', $normalized['faa_expiration']);
        $this->assertSame('', $normalized['phone']);
        $this->assertArrayNotHasKey('extra', $normalized);
    }
}
