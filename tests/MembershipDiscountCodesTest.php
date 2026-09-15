<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/membership_discount_codes.php';

final class MembershipDiscountCodesTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function code(string $type, float $amount, string $applies, string $code = 'EARLY50'): array
    {
        return [
            'code'           => $code,
            'discount_type'  => $type,
            'amount'         => $amount,
            'applies_to'     => $applies,
        ];
    }

    public function testDollarOffDuesKeepsInitiation(): void
    {
        $result = membership_discount_code_apply(160.0, 50.0, $this->code('amount', 50.0, 'dues'));
        $this->assertTrue($result['ok']);
        $this->assertSame(110.0, $result['dues']);
        $this->assertSame(50.0, $result['initiation']);
        $this->assertSame(50.0, $result['discount_amount']);
    }

    public function testDollarOffInitiationKeepsDues(): void
    {
        $result = membership_discount_code_apply(160.0, 50.0, $this->code('amount', 50.0, 'initiation', 'NOINIT'));
        $this->assertTrue($result['ok']);
        $this->assertSame(160.0, $result['dues']);
        $this->assertSame(0.0, $result['initiation']);
        $this->assertSame(50.0, $result['discount_amount']);
    }

    public function testInitiationOnlyDoesNotApplyWhenInitiationIsZero(): void
    {
        $result = membership_discount_code_apply(160.0, 0.0, $this->code('amount', 50.0, 'initiation', 'NOINIT'));
        $this->assertFalse($result['ok']);
        $this->assertSame(160.0, $result['dues']);
        $this->assertSame(0.0, $result['initiation']);
    }

    public function testPercentOffBothScalesEachLine(): void
    {
        $result = membership_discount_code_apply(160.0, 50.0, $this->code('percent', 25.0, 'both', 'SAVE25'));
        $this->assertTrue($result['ok']);
        $this->assertSame(120.0, $result['dues']);
        $this->assertSame(37.5, $result['initiation']);
        $this->assertSame(52.5, $result['discount_amount']);
    }

    public function testDollarOffBothIsProportional(): void
    {
        $result = membership_discount_code_apply(160.0, 50.0, $this->code('amount', 50.0, 'both', 'BOTH50'));
        $this->assertTrue($result['ok']);
        $this->assertSame(50.0, $result['discount_amount']);
        $this->assertSame(160.0, round($result['dues'] + $result['initiation'], 2));
        $this->assertGreaterThan(0.0, $result['dues']);
        $this->assertGreaterThan(0.0, $result['initiation']);
    }

    public function testRejectsWhenDiscountWouldZeroTheQuote(): void
    {
        $result = membership_discount_code_apply(20.0, 0.0, $this->code('amount', 50.0, 'dues', 'BIG50'));
        $this->assertFalse($result['ok']);
        $this->assertSame(20.0, $result['dues']);
    }

    public function testRejectsOneHundredPercent(): void
    {
        $result = membership_discount_code_apply(160.0, 50.0, $this->code('percent', 100.0, 'both', 'FREE'));
        $this->assertFalse($result['ok']);
    }

    public function testNormalizeCodeStripsJunkAndUppercases(): void
    {
        $this->assertSame('EARLY-50', membership_discount_normalize_code(' early-50! '));
        $this->assertSame('', membership_discount_normalize_code('***'));
    }

    public function testExpiredRowIsNotUsable(): void
    {
        $now = new DateTimeImmutable('2026-09-15 12:00:00');
        $this->assertFalse(membership_discount_code_is_usable([
            'active'     => 1,
            'expires_at' => '2026-09-01 23:59:59',
        ], $now));
        $this->assertTrue(membership_discount_code_is_usable([
            'active'     => 1,
            'expires_at' => '2026-12-31 23:59:59',
        ], $now));
    }

    public function testDisabledRowIsNotUsable(): void
    {
        $this->assertFalse(membership_discount_code_is_usable([
            'active'       => 0,
            'expires_at'   => '',
            'disabled_at'  => '2026-01-01 00:00:00',
        ]));
    }

    public function testSummaryCopy(): void
    {
        $this->assertSame('$50.00 off membership dues', membership_discount_code_summary($this->code('amount', 50.0, 'dues')));
        $this->assertSame('25% off dues and initiation', membership_discount_code_summary($this->code('percent', 25.0, 'both', 'SAVE25')));
    }
}
