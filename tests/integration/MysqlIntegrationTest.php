<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/helpers.php';
require_once dirname(__DIR__, 2) . '/includes/membership_status.php';
require_once dirname(__DIR__, 2) . '/includes/member_applications.php';
require_once dirname(__DIR__, 2) . '/includes/payments_ledger.php';
require_once dirname(__DIR__, 2) . '/includes/run_report.php';

/**
 * MySQL integration tests. Skipped unless TEST_DB_HOST is set (CI / docker-compose.test.yml).
 *
 * @group integration
 */
final class MysqlIntegrationTest extends TestCase
{
    private static ?PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('TEST_DB_HOST') ?: '';
        if ($host === '') {
            return;
        }
        $name = getenv('TEST_DB_NAME') ?: 'flightops_test';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $pass = getenv('TEST_DB_PASSWORD') ?: '';
        $port = getenv('TEST_DB_PORT') ?: '3306';
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    protected function setUp(): void
    {
        if (self::$pdo === null) {
            $this->markTestSkipped('Set TEST_DB_HOST to run MySQL integration tests.');
        }
    }

    public function testCurrentMemberSqlMatchesRenewalYearAndFlags(): void
    {
        $pdo = self::$pdo;
        $this->assertNotNull($pdo);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('DELETE FROM payments');
        $pdo->exec('DELETE FROM member_fulfillments');
        $pdo->exec('DELETE FROM member_membership_years');
        $pdo->exec('DELETE FROM member_applications');
        $pdo->exec('DELETE FROM members');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $ins = $pdo->prepare('INSERT INTO members (first_name, last_name, membership_renewal_year, inactive, suspended) VALUES (?,?,?,?,?)');
        $ins->execute(['Current', 'Pilot', 2026, 0, 0]);
        $currentId = (int) $pdo->lastInsertId();
        $ins->execute(['Inactive', 'Pilot', 2026, 1, 0]);
        $ins->execute(['Old', 'Year', 2025, 0, 0]);

        $sql = 'SELECT id FROM members m WHERE ' . currentMemberWhereSql('m', 2026);
        $stmt = $pdo->prepare($sql);
        $stmt->execute(currentMemberWhereParams(2026));
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame([$currentId], $ids);
    }

    public function testApprovedStripeApplicationWritesLedger(): void
    {
        $pdo = self::$pdo;
        $this->assertNotNull($pdo);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('DELETE FROM payments');
        $pdo->exec('DELETE FROM member_fulfillments');
        $pdo->exec('DELETE FROM member_applications');
        $pdo->exec('DELETE FROM members');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $pdo->prepare('INSERT INTO members (first_name, last_name, membership_renewal_year) VALUES (?,?,?)')
            ->execute(['Stripe', 'Member', 2025]);
        $memberId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO member_applications
             (status, wpforms_entry_id, submitted_at, first_name, last_name, payment_status, payment_total, payment_processing_fee, payment_initiation, payment_gateway, payment_transaction_id)
             VALUES ('pending', ?, NOW(), 'Stripe', 'Member', 'succeeded', 134.19, 4.19, 50.00, 'Stripe', 'pi_test_integration')"
        )->execute(['native-test-' . uniqid()]);
        $appId = (int) $pdo->lastInsertId();
        $app = $pdo->query('SELECT * FROM member_applications WHERE id = ' . $appId)->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($app);

        $result = application_record_approved_ledger($pdo, $app, $memberId, 1, 'new', 2026);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertTrue($result['recorded']);

        $pay = $pdo->query('SELECT * FROM payments WHERE member_id = ' . $memberId)->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($pay);
        $this->assertSame('pi_test_integration', $pay['payment_transaction_id']);
        $this->assertEqualsWithDelta(4.19, (float) $pay['amount_processing_fee'], 0.001);
        $this->assertGreaterThan(0, (float) $pay['amount_dues']);
    }

    public function testRevenueReportExcludesRefundedStripePayments(): void
    {
        $pdo = self::$pdo;
        $this->assertNotNull($pdo);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('DELETE FROM payments');
        $pdo->exec('DELETE FROM members');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $pdo->prepare('INSERT INTO members (first_name, last_name) VALUES (?,?)')->execute(['Rev', 'Test']);
        $memberId = (int) $pdo->lastInsertId();

        payment_ensure_refund_schema($pdo);
        $pdo->prepare(
            'INSERT INTO payments (member_id, paid_at, year, amount_dues, amount_initiation, amount_late_fee, amount_processing_fee, payment_transaction_id, ledger_status)
             VALUES (?, CURDATE(), 2026, 160, 0, 0, 5.00, ?, ?)'
        )->execute([$memberId, 'pi_keep', 'recorded']);
        $pdo->prepare(
            'INSERT INTO payments (member_id, paid_at, year, amount_dues, amount_initiation, amount_late_fee, amount_processing_fee, payment_transaction_id, ledger_status, amount_refunded)
             VALUES (?, CURDATE(), 2026, 160, 0, 0, 5.00, ?, ?, 165.00)'
        )->execute([$memberId, 'pi_refund', 'refunded']);

        $report = reportRevenueByYear($pdo);
        $row2026 = null;
        foreach ($report['rows'] as $row) {
            if ((int) $row['year'] === 2026) {
                $row2026 = $row;
                break;
            }
        }
        $this->assertNotNull($row2026);
        $this->assertSame(1, $row2026['payments']);
        $this->assertEqualsWithDelta(160.0, $row2026['dues'], 0.001);
        $this->assertEqualsWithDelta(160.0, $row2026['club_net'], 0.001);
        $this->assertEqualsWithDelta(5.0, $row2026['processing'], 0.001);
        $this->assertEqualsWithDelta(165.0, $row2026['gross'], 0.001);
        $this->assertEqualsWithDelta(165.0, $row2026['refunds'], 0.001);
    }
}
