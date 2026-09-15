<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/payments_ledger.php';

final class PaymentsLedgerTest extends TestCase
{
    public function testStripeDetectedFromPaymentIntent(): void
    {
        $this->assertTrue(payment_is_stripe(['payment_transaction_id' => 'pi_abc']));
        $this->assertFalse(payment_is_stripe(['payment_transaction_id' => '', 'amount_dues' => 160]));
    }

    public function testClubNetIgnoresProcessingAndRefundedRows(): void
    {
        $row = [
            'amount_dues' => 160,
            'amount_initiation' => 50,
            'amount_late_fee' => 0,
            'amount_processing_fee' => 6.40,
            'ledger_status' => 'recorded',
        ];
        $this->assertSame(210.0, payment_club_net($row));
        $this->assertSame(216.4, payment_gross_collected($row));

        $row['ledger_status'] = 'refunded';
        $this->assertSame(0.0, payment_club_net($row));
    }

    public function testOccupiesMembershipYearIgnoresRefunded(): void
    {
        $this->assertTrue(payment_occupies_membership_year([
            'ledger_status' => 'recorded',
            'amount_dues' => 160,
        ]));
        $this->assertTrue(payment_occupies_membership_year([
            'amount_dues' => 160,
        ]));
        $this->assertFalse(payment_occupies_membership_year([
            'ledger_status' => 'refunded',
            'amount_dues' => 160,
        ]));
    }

    public function testRefundAmountCannotExceedGross(): void
    {
        $this->assertSame(216.4, payment_gross_collected([
            'amount_dues' => 160,
            'amount_initiation' => 50,
            'amount_late_fee' => 0,
            'amount_processing_fee' => 6.40,
        ]));
    }
}
