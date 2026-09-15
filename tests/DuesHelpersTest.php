<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DuesHelpersTest extends TestCase
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

    public function testNewMemberUsesProratedDuesAndInitiation(): void
    {
        $pdo = $this->createMock(PDO::class);
        $result = calculateDues($pdo, 1, 'new', $this->sampleRules());

        $this->assertSame(80.0, $result['dues']);
        $this->assertSame(50.0, $result['init']);
        $this->assertSame(160.0, $result['regularDues']);
    }

    public function testOnTimeRenewalUsesAnnualDuesOnly(): void
    {
        $pdo = $this->createMock(PDO::class);
        $result = calculateDues($pdo, 1, 'on_time', $this->sampleRules());

        $this->assertSame(160.0, $result['dues']);
        $this->assertSame(0.0, $result['init']);
    }

    public function testLateRenewalChargesInitiationAgain(): void
    {
        $pdo = $this->createMock(PDO::class);
        $result = calculateDues($pdo, 1, 'late', $this->sampleRules());

        $this->assertSame(160.0, $result['dues']);
        $this->assertSame(50.0, $result['init']);
    }

    public function testUnknownSlotReturnsZeros(): void
    {
        $pdo = $this->createMock(PDO::class);
        $result = calculateDues($pdo, 9, 'on_time', $this->sampleRules());

        $this->assertSame(0.0, $result['dues']);
        $this->assertSame(0.0, $result['init']);
        $this->assertSame(0.0, $result['regularDues']);
    }

    public function testYouthSlotUsesReducedRate(): void
    {
        $pdo = $this->createMock(PDO::class);
        $result = calculateDues($pdo, 2, 'on_time', $this->sampleRules());

        $this->assertSame(20.0, $result['dues']);
        $this->assertSame(0.0, $result['init']);
    }
}
