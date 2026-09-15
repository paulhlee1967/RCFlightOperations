<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/board_packet.php';

final class BoardPacketTest extends TestCase
{
    public function test_parse_addresses_dedupes_and_validates(): void
    {
        $raw = ' board@club.org ; treasurer@club.org, board@club.org ';
        $this->assertSame(
            ['board@club.org', 'treasurer@club.org'],
            board_packet_parse_addresses($raw)
        );
    }

    public function test_invalid_address_tokens_lists_bad_entries(): void
    {
        $this->assertSame(
            ['not-an-email', 'also bad'],
            board_packet_invalid_address_tokens('ok@club.org, not-an-email; also bad')
        );
    }

    public function test_month_key_format(): void
    {
        $when = new DateTimeImmutable('2026-07-16 12:00:00');
        $this->assertSame('2026-07', board_packet_month_key($when));
    }

    public function test_period_label_format(): void
    {
        $when = new DateTimeImmutable('2026-07-16 12:00:00');
        $this->assertSame('July 2026', board_packet_period_label($when));
    }

    public function test_is_send_day_respects_configured_day(): void
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(['config_value' => '5']);
        $pdo->method('prepare')->willReturn($stmt);

        $before = new DateTimeImmutable('2026-07-04');
        $onDay  = new DateTimeImmutable('2026-07-05');
        $after  = new DateTimeImmutable('2026-07-20');

        $this->assertFalse(board_packet_is_send_day($pdo, $before));
        $this->assertTrue(board_packet_is_send_day($pdo, $onDay));
        $this->assertTrue(board_packet_is_send_day($pdo, $after));
    }

    public function test_short_text_truncates_long_descriptions(): void
    {
        $long = str_repeat('a', 200);
        $short = board_packet_short_text($long, 40);
        $this->assertLessThanOrEqual(40, mb_strlen($short));
        $this->assertStringEndsWith('…', $short);
    }

    public function test_incident_status_label_maps_under_review(): void
    {
        $this->assertSame('Under review', board_packet_incident_status_label('under_review'));
    }

    public function test_email_subject_includes_period(): void
    {
        $subject = boardPacketEmailSubject([
            'club_name'    => 'Test Club',
            'period_label' => 'July 2026',
        ]);
        $this->assertSame('Test Club — Board packet · July 2026', $subject);
    }

    public function test_not_yet_renewed_report_path(): void
    {
        $this->assertSame(
            'reports.php?report=not_yet_renewed&year=2026',
            board_packet_not_yet_renewed_report_path(2026)
        );
    }

    public function test_not_yet_renewed_report_url_uses_public_base(): void
    {
        $url = board_packet_not_yet_renewed_report_url(2026, [
            'public_base_url' => 'https://club.example.com/flightops',
        ]);
        $this->assertSame(
            'https://club.example.com/flightops/reports.php?report=not_yet_renewed&year=2026',
            $url
        );
    }
}
