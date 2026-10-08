<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/current_members_digest.php';
require_once dirname(__DIR__) . '/includes/report_pdf.php';

final class CurrentMembersDigestTest extends TestCase
{
    public function test_report_year_rolls_on_default_prebook_date(): void
    {
        $this->assertSame(2026, current_members_digest_report_year(10, 15, new DateTimeImmutable('2026-10-14')));
        $this->assertSame(2027, current_members_digest_report_year(10, 15, new DateTimeImmutable('2026-10-15')));
        $this->assertSame(2027, current_members_digest_report_year(10, 15, new DateTimeImmutable('2026-12-31')));
        $this->assertSame(2027, current_members_digest_report_year(10, 15, new DateTimeImmutable('2027-01-01')));
    }

    public function test_report_year_follows_a_custom_prebook_date(): void
    {
        $this->assertSame(2026, current_members_digest_report_year(11, 1, new DateTimeImmutable('2026-10-31')));
        $this->assertSame(2027, current_members_digest_report_year(11, 1, new DateTimeImmutable('2026-11-01')));
    }

    public function test_report_year_matches_default_renewal_year_for_today(): void
    {
        $this->assertSame(
            defaultRenewalYear(null),
            current_members_digest_report_year(10, 15, new DateTimeImmutable('now'))
        );
    }

    public function test_week_key_is_iso_week(): void
    {
        $this->assertSame('2026-W41', current_members_digest_week_key(new DateTimeImmutable('2026-10-08')));
        $this->assertSame('2026-W53', current_members_digest_week_key(new DateTimeImmutable('2027-01-01')));
    }

    public function test_is_send_day_is_on_or_after_configured_weekday(): void
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(['config_value' => '5']);
        $pdo->method('prepare')->willReturn($stmt);

        $wednesday = new DateTimeImmutable('2026-10-14');
        $friday    = new DateTimeImmutable('2026-10-16');

        $this->assertFalse(current_members_digest_is_send_day($pdo, $wednesday));
        $this->assertTrue(current_members_digest_is_send_day($pdo, $friday));
    }

    public function test_year_explanation_distinguishes_renewal_window(): void
    {
        $during = current_members_digest_year_explanation(2027, new DateTimeImmutable('2026-10-20'));
        $this->assertStringContainsString('renewal window', $during);
        $this->assertStringContainsString('2027', $during);

        $regular = current_members_digest_year_explanation(2026, new DateTimeImmutable('2026-06-01'));
        $this->assertStringContainsString('current members for 2026', $regular);
    }

    public function test_pdf_filename_includes_year_and_date(): void
    {
        $name = reportPdfFilename(['slug' => 'current_members'], 2027, '2026-10-20');
        $this->assertSame('report_current_members_2027_2026-10-20.pdf', $name);
    }
}
