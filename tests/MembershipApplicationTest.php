<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/dues_helpers.php';
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
}
