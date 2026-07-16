<?php
/**
 * Branded applicant status emails for membership applications.
 *
 * Received and approved emails are deduplicated atomically via member_application_emails.
 * Email failures are logged and never roll back business state transitions.
 */

require_once __DIR__ . '/installation_config.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/email_templates.php';

/** Allow an interrupted send claim to be retried after ten minutes. */
const APPLICATION_EMAIL_STALE_CLAIM_SECONDS = 600;

/**
 * Idempotent schema for application status emails and info-request history.
 */
function application_email_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `member_application_emails` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `application_id` int unsigned NOT NULL,
              `email_type` enum('received','approved','request_info') NOT NULL,
              `idempotency_key` varchar(128) NOT NULL,
              `recipient` varchar(255) NOT NULL DEFAULT '',
              `subject` varchar(255) NOT NULL DEFAULT '',
              `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
              `error_message` text DEFAULT NULL,
              `sent_at` datetime DEFAULT NULL,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uniq_idempotency_key` (`idempotency_key`),
              KEY `idx_application_emails_app` (`application_id`),
              KEY `idx_application_emails_type_status` (`email_type`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `member_application_info_requests` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `application_id` int unsigned NOT NULL,
              `message` text NOT NULL,
              `requested_by` int unsigned NOT NULL,
              `dedup_key` varchar(64) NOT NULL,
              `requested_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uniq_info_request_dedup` (`dedup_key`),
              KEY `idx_info_requests_application` (`application_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
    }

    try {
        $columns = [];
        $stmt = $pdo->query('SHOW COLUMNS FROM member_applications');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[(string) ($row['Field'] ?? '')] = true;
        }
        if (!isset($columns['latest_info_request_message'])) {
            $pdo->exec('ALTER TABLE member_applications ADD COLUMN latest_info_request_message text DEFAULT NULL AFTER rejection_reason');
        }
        if (!isset($columns['latest_info_request_at'])) {
            $pdo->exec('ALTER TABLE member_applications ADD COLUMN latest_info_request_at datetime DEFAULT NULL AFTER latest_info_request_message');
        }
    } catch (Throwable $e) {
    }
}

/**
 * @return array{club_name:string,support_email:string,footer_note:string,eyebrow:string}
 */
function application_email_layout_context(PDO $pdo): array
{
    $clubName = 'RC Flight Operations';
    try {
        $stmt = $pdo->query('SELECT name FROM club WHERE id = 1 LIMIT 1');
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if ($row && trim((string) ($row['name'] ?? '')) !== '') {
            $clubName = trim((string) $row['name']);
        }
    } catch (Throwable $e) {
    }

    $sysConfig = installation_load_system_config($pdo);
    $supportEmail = trim((string) ($sysConfig['support_email'] ?? ''));
    $membershipEmail = trim((string) ($sysConfig['membership_email'] ?? ''));

    $contactEmail = $supportEmail !== '' ? $supportEmail : $membershipEmail;
    $contactHtml = $contactEmail !== ''
        ? '<a href="mailto:' . htmlspecialchars($contactEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" style="color:inherit;text-decoration:none;">' . htmlspecialchars($contactEmail) . '</a>'
        : 'your club membership team';

    $footerNote = 'This email was sent regarding your membership application with '
        . htmlspecialchars($clubName) . '.<br>'
        . 'Please do not reply to this address. If you need to contact the club, email '
        . $contactHtml . '.';

    return [
        'club_name'     => $clubName,
        'support_email' => $contactEmail,
        'footer_note'   => $footerNote,
        'eyebrow'       => 'Membership Application',
    ];
}

/**
 * Build template vars from an application row.
 *
 * @return array<string, mixed>
 */
function application_email_template_vars(PDO $pdo, array $app, array $extra = []): array
{
    $layout = application_email_layout_context($pdo);
    $addressParts = array_filter([
        $app['address_street'] ?? '',
        $app['address_street2'] ?? '',
        $app['address_city'] ?? '',
        $app['address_state'] ?? '',
        $app['address_postal_code'] ?? '',
    ], static fn ($v) => trim((string) $v) !== '');

    return array_merge($layout, [
        'first_name'        => trim((string) ($app['first_name'] ?? '')),
        'last_name'         => trim((string) ($app['last_name'] ?? '')),
        'application_id'    => (int) ($app['id'] ?? 0),
        'reference'         => trim((string) ($app['wpforms_entry_id'] ?? '')),
        'payment_total'     => isset($app['payment_total']) ? (float) $app['payment_total'] : 0.0,
        'mailing_address'   => trim(implode(', ', $addressParts)),
        'application_kind'  => (string) ($app['application_kind'] ?? ''),
    ], $extra);
}

/**
 * Atomically claim a send slot. Returns log row id when send should proceed,
 * null when a successful send already exists.
 */
function application_email_try_claim(
    PDO $pdo,
    int $applicationId,
    string $emailType,
    string $recipient,
    string $idempotencyKey
): ?int {
    application_email_ensure_schema($pdo);

    $recipient = strtolower(trim($recipient));
    if ($recipient === '') {
        return null;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('
            SELECT id, status, updated_at
            FROM member_application_emails
            WHERE idempotency_key = ?
            LIMIT 1
            FOR UPDATE
        ');
        $stmt->execute([$idempotencyKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            if (($row['status'] ?? '') === 'sent') {
                $pdo->commit();
                return null;
            }
            if (($row['status'] ?? '') === 'pending') {
                $updated = strtotime((string) ($row['updated_at'] ?? ''));
                $claimActive = $updated === false
                    || (time() - $updated) < APPLICATION_EMAIL_STALE_CLAIM_SECONDS;
                if ($claimActive) {
                    $pdo->commit();
                    return null;
                }
            }

            $logId = (int) $row['id'];
            $pdo->prepare('
                UPDATE member_application_emails
                SET status = \'pending\', recipient = ?, error_message = NULL
                WHERE id = ?
            ')->execute([$recipient, $logId]);
            $pdo->commit();

            return $logId;
        }

        $pdo->prepare('
            INSERT INTO member_application_emails
                (application_id, email_type, idempotency_key, recipient, status)
            VALUES (?, ?, ?, ?, \'pending\')
        ')->execute([$applicationId, $emailType, $idempotencyKey, $recipient]);
        $logId = (int) $pdo->lastInsertId();
        $pdo->commit();

        return $logId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('application_email_try_claim failed: ' . $e->getMessage());

        return null;
    }
}

function application_email_mark_result(PDO $pdo, int $logId, bool $success, string $subject = '', ?string $error = null): void
{
    if ($logId <= 0) {
        return;
    }

    try {
        if ($success) {
            $pdo->prepare('
                UPDATE member_application_emails
                SET status = \'sent\', subject = ?, error_message = NULL, sent_at = NOW()
                WHERE id = ?
            ')->execute([$subject, $logId]);
        } else {
            $pdo->prepare('
                UPDATE member_application_emails
                SET status = \'failed\', subject = ?, error_message = ?
                WHERE id = ?
            ')->execute([$subject, $error, $logId]);
        }
    } catch (Throwable $e) {
        error_log('application_email_mark_result failed: ' . $e->getMessage());
    }
}

/**
 * @return array{sent:bool,skipped:bool,error:?string}
 */
function application_email_send_templated(
    PDO $pdo,
    int $applicationId,
    string $emailType,
    string $templateKey,
    string $idempotencyKey,
    array $templateVars
): array {
    $recipient = strtolower(trim((string) ($templateVars['recipient'] ?? '')));
    if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        return ['sent' => false, 'skipped' => true, 'error' => 'No valid applicant email.'];
    }

    $logId = application_email_try_claim($pdo, $applicationId, $emailType, $recipient, $idempotencyKey);
    if ($logId === null) {
        return ['sent' => false, 'skipped' => true, 'error' => null];
    }

    try {
        $rendered = render_email_template($templateKey, $templateVars, $pdo);
    } catch (Throwable $e) {
        $msg = 'Template render failed: ' . $e->getMessage();
        application_email_mark_result($pdo, $logId, false, '', $msg);
        error_log('application_email_send_templated: ' . $msg);
        return ['sent' => false, 'skipped' => false, 'error' => $msg];
    }

    $subject = (string) ($rendered['subject'] ?? '');
    $html = (string) ($rendered['html'] ?? '');
    $text = $rendered['text'] ?? null;
    if ($text === null || $text === '') {
        $text = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)));
    }

    $mailConfig = installation_mail_config($pdo);
    $ok = send_mail($recipient, $subject, $html, $text, $mailConfig);
    if (!$ok) {
        $err = function_exists('get_last_mail_error') ? get_last_mail_error() : 'Mail send failed.';
        application_email_mark_result($pdo, $logId, false, $subject, (string) $err);
        error_log(sprintf(
            'application_email_send_templated: %s email failed for application #%d (%s): %s',
            $emailType,
            $applicationId,
            $recipient,
            $err
        ));
        return ['sent' => false, 'skipped' => false, 'error' => (string) $err];
    }

    application_email_mark_result($pdo, $logId, true, $subject);
    return ['sent' => true, 'skipped' => false, 'error' => null];
}

/**
 * Best-effort branded "application received" email (deduplicated per application).
 */
function application_email_send_received(PDO $pdo, int $applicationId): void
{
    require_once __DIR__ . '/member_applications.php';

    $app = application_fetch($pdo, $applicationId);
    if (
        $app === null
        || empty($app['email'])
        || !in_array((string) ($app['status'] ?? ''), ['pending', 'approved', 'rejected'], true)
    ) {
        return;
    }

    $vars = application_email_template_vars($pdo, $app, [
        'recipient' => (string) $app['email'],
    ]);

    application_email_send_templated(
        $pdo,
        $applicationId,
        'received',
        'application_received',
        'received:' . $applicationId,
        $vars
    );
}

/**
 * Best-effort branded approval email (deduplicated per application).
 */
function application_email_send_approved(PDO $pdo, int $applicationId): void
{
    require_once __DIR__ . '/member_applications.php';

    $app = application_fetch($pdo, $applicationId);
    if (
        $app === null
        || empty($app['email'])
        || (string) ($app['status'] ?? '') !== 'approved'
    ) {
        return;
    }

    $vars = application_email_template_vars($pdo, $app, [
        'recipient' => (string) $app['email'],
    ]);

    application_email_send_templated(
        $pdo,
        $applicationId,
        'approved',
        'application_approved',
        'approved:' . $applicationId,
        $vars
    );
}

/**
 * Dedup key for accidental duplicate POSTs (same staff/message within ~2 minutes).
 */
function application_request_info_dedup_key(int $applicationId, int $requestedBy, string $message): string
{
    $normalized = preg_replace('/\s+/u', ' ', trim($message)) ?? trim($message);
    $bucket = (int) floor(time() / 120);

    return hash('sha256', $applicationId . '|' . $requestedBy . '|' . $normalized . '|' . $bucket);
}

/**
 * @return list<array<string, mixed>>
 */
function application_info_request_history(PDO $pdo, int $applicationId): array
{
    application_email_ensure_schema($pdo);

    try {
        $stmt = $pdo->prepare('
            SELECT r.id, r.message, r.requested_at, r.requested_by, u.name AS requested_by_name
            FROM member_application_info_requests r
            LEFT JOIN users u ON u.id = r.requested_by
            WHERE r.application_id = ?
            ORDER BY r.requested_at DESC, r.id DESC
        ');
        $stmt->execute([$applicationId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return array{ok:bool,duplicate:bool,request_id:?int,email_sent:bool,email_error:?string,error:?string}
 */
function application_request_info(PDO $pdo, int $applicationId, int $requestedBy, string $message): array
{
    require_once __DIR__ . '/member_applications.php';

    application_email_ensure_schema($pdo);

    $message = trim($message);
    if ($message === '') {
        return [
            'ok'          => false,
            'duplicate'   => false,
            'request_id'  => null,
            'email_sent'  => false,
            'email_error' => null,
            'error'       => 'Please enter a message for the applicant.',
        ];
    }

    $app = application_fetch($pdo, $applicationId);
    if ($app === null) {
        return [
            'ok'          => false,
            'duplicate'   => false,
            'request_id'  => null,
            'email_sent'  => false,
            'email_error' => null,
            'error'       => 'Application not found.',
        ];
    }

    if (!application_is_reviewable_status($app['status'] ?? null)) {
        return [
            'ok'          => false,
            'duplicate'   => false,
            'request_id'  => null,
            'email_sent'  => false,
            'email_error' => null,
            'error'       => 'Only pending applications can receive information requests.',
        ];
    }

    if (empty($app['email']) || !filter_var((string) $app['email'], FILTER_VALIDATE_EMAIL)) {
        return [
            'ok'          => false,
            'duplicate'   => false,
            'request_id'  => null,
            'email_sent'  => false,
            'email_error' => null,
            'error'       => 'Applicant does not have a valid email address on file.',
        ];
    }

    $dedupKey = application_request_info_dedup_key($applicationId, $requestedBy, $message);

    try {
        $pdo->prepare('
            INSERT INTO member_application_info_requests
                (application_id, message, requested_by, dedup_key)
            VALUES (?, ?, ?, ?)
        ')->execute([$applicationId, $message, $requestedBy, $dedupKey]);
        $requestId = (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            return [
                'ok'          => true,
                'duplicate'   => true,
                'request_id'  => null,
                'email_sent'  => false,
                'email_error' => null,
                'error'       => null,
            ];
        }

        return [
            'ok'          => false,
            'duplicate'   => false,
            'request_id'  => null,
            'email_sent'  => false,
            'email_error' => null,
            'error'       => 'Could not save the information request.',
        ];
    }

    $pdo->prepare('
        UPDATE member_applications
        SET latest_info_request_message = ?, latest_info_request_at = NOW()
        WHERE id = ?
    ')->execute([$message, $applicationId]);

    $vars = application_email_template_vars($pdo, $app, [
        'recipient'        => (string) $app['email'],
        'request_message'  => $message,
    ]);

    $emailResult = application_email_send_templated(
        $pdo,
        $applicationId,
        'request_info',
        'application_request_info',
        'request_info:' . $requestId,
        $vars
    );

    return [
        'ok'          => true,
        'duplicate'   => false,
        'request_id'  => $requestId,
        'email_sent'  => $emailResult['sent'],
        'email_error' => $emailResult['error'],
        'error'       => null,
    ];
}

/**
 * @return array<string, mixed>|null
 */
function application_email_delivery_status(PDO $pdo, int $applicationId, string $emailType): ?array
{
    application_email_ensure_schema($pdo);

    $key = match ($emailType) {
        'received' => 'received:' . $applicationId,
        'approved' => 'approved:' . $applicationId,
        default    => null,
    };
    if ($key === null) {
        return null;
    }

    try {
        $stmt = $pdo->prepare('
            SELECT status, sent_at, error_message
            FROM member_application_emails
            WHERE idempotency_key = ?
            LIMIT 1
        ');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}
