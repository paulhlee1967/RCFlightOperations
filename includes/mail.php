<?php
/**
 * Email sending: config-driven transport (SMTP or PHP mail()).
 * Requires config.php with optional 'email' key. Use send_mail() to send.
 */

$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

if (!isset($config)) {
    $configFile = dirname(__DIR__) . '/config.php';
    if (!is_file($configFile)) {
        die('Missing config.php.');
    }
    $config = require $configFile;
}

$emailConfig = $config['email'] ?? null;
$mailFromAddress = $emailConfig['from_address'] ?? 'noreply@localhost';
$mailFromName    = $emailConfig['from_name'] ?? 'RC Flight Operations';

/** @var array{mail: PHPMailer, config_key: string}|null */
$_smtp_batch = null;

/**
 * Stable key for matching batch SMTP sessions to a mail config array.
 */
function mail_config_batch_key(array $emailConfig): string
{
    $smtp = $emailConfig['smtp'] ?? [];

    return hash('sha256', json_encode([
        $emailConfig['from_address'] ?? '',
        $emailConfig['from_name'] ?? '',
        $smtp['host'] ?? '',
        $smtp['port'] ?? '',
        $smtp['encryption'] ?? '',
        $smtp['username'] ?? '',
        $smtp['password'] ?? '',
    ], JSON_THROW_ON_ERROR));
}

/**
 * Begin reusing one SMTP connection for multiple send_mail() calls in this request.
 * No-op when the config does not use SMTP. Pair with send_mail_batch_end().
 */
function send_mail_batch_begin(array $emailConfig): bool
{
    global $_smtp_batch;

    send_mail_batch_end();

    $driver = $emailConfig['driver'] ?? 'mail';
    $smtp   = $emailConfig['smtp'] ?? [];
    if ($driver !== 'smtp' || empty($smtp['host']) || empty($smtp['username'])) {
        return false;
    }

    try {
        $mail = new PHPMailer(true);
        mail_configure_smtp($mail, $emailConfig, true);
        $_smtp_batch = [
            'mail'        => $mail,
            'config_key'  => mail_config_batch_key($emailConfig),
        ];

        return true;
    } catch (PHPMailerException $e) {
        mail_last_error($e->getMessage());
        error_log('SMTP batch begin failed: ' . $e->getMessage());

        return false;
    }
}

/**
 * Close a batched SMTP session opened by send_mail_batch_begin().
 */
function send_mail_batch_end(): void
{
    global $_smtp_batch;

    if ($_smtp_batch === null) {
        return;
    }

    try {
        $_smtp_batch['mail']->smtpClose();
    } catch (Throwable $e) {
        // Connection may already be closed.
    }

    $_smtp_batch = null;
}

/**
 * @return PHPMailer|null Active batched mailer when config matches, else null.
 */
function mail_batch_smtp_mailer(array $emailConfig): ?PHPMailer
{
    global $_smtp_batch;

    if ($_smtp_batch === null) {
        return null;
    }
    if ($_smtp_batch['config_key'] !== mail_config_batch_key($emailConfig)) {
        return null;
    }

    return $_smtp_batch['mail'];
}

/**
 * Apply SMTP settings to a PHPMailer instance.
 */
function mail_configure_smtp(PHPMailer $mail, array $emailConfig, bool $keepAlive): void
{
    $smtp = $emailConfig['smtp'] ?? [];

    $mail->isSMTP();
    $mail->Host         = $smtp['host'];
    $mail->Port         = (int) ($smtp['port'] ?? 587);
    $mail->SMTPAuth     = true;
    $mail->Username     = $smtp['username'];
    $mail->Password     = $smtp['password'];
    $mail->SMTPSecure   = ($smtp['encryption'] ?? 'tls') === 'ssl'
        ? PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->CharSet      = PHPMailer::CHARSET_UTF8;
    $mail->SMTPKeepAlive = $keepAlive;
    $mail->Timeout      = 30;
}

/**
 * Send an email. Uses SMTP if config has driver=smtp and smtp settings; otherwise PHP mail().
 *
 * @param string      $to           Recipient email address.
 * @param string      $subject      Subject line.
 * @param string      $bodyHtml     HTML body.
 * @param string|null $bodyText     Optional plain-text body (if null, strip_tags of HTML used for mail()).
 * @param array|null  $emailConfig Optional config (from getSystemMailConfig etc.). When null, uses $config['email'].
 * @param array|null  $options     Optional send options, e.g. list_unsubscribe_url for RFC 2369 header.
 * @return bool True if sent successfully, false otherwise.
 */
function send_mail(string $to, string $subject, string $bodyHtml, ?string $bodyText = null, ?array $emailConfig = null, ?array $options = null): bool
{
    return send_mail_to_many([$to], $subject, $bodyHtml, $bodyText, $emailConfig, $options);
}

/**
 * Send one message to multiple recipients (all addresses on the To line).
 *
 * @param  string[]    $to
 * @return bool
 */
function send_mail_to_many(array $to, string $subject, string $bodyHtml, ?string $bodyText = null, ?array $emailConfig = null, ?array $options = null): bool
{
    global $config, $mailFromAddress, $mailFromName;

    $recipients = [];
    foreach ($to as $addr) {
        $addr = trim((string) $addr);
        if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL)) {
            $recipients[$addr] = true;
        }
    }
    $recipients = array_keys($recipients);
    if ($recipients === []) {
        return false;
    }

    if ($emailConfig !== null) {
        $email = $emailConfig;
    } else {
        $email = $config['email'] ?? null;
    }
    $driver = $email['driver'] ?? 'mail';
    $smtp = $email['smtp'] ?? [];
    $fromAddress = $email['from_address'] ?? $mailFromAddress;
    $fromName = $email['from_name'] ?? $mailFromName;

    if ($driver === 'smtp' && !empty($smtp['host']) && !empty($smtp['username'])) {
        return send_mail_via_smtp($recipients, $subject, $bodyHtml, $bodyText, $email, $options);
    }

    return send_mail_via_php($recipients, $subject, $bodyHtml, $bodyText, $fromAddress, $fromName, $options);
}

/**
 * Send using PHPMailer and SMTP config.
 *
 * @param  string|string[] $to         One recipient or a list (all on the To line).
 * @param string      $subject     Subject line.
 * @param string      $bodyHtml    HTML body.
 * @param string|null $bodyText    Plain-text alternative (or null to derive from HTML).
 * @param array       $emailConfig Config array with from_address, from_name, smtp (host, port, username, password, encryption).
 * @param array|null  $options     Optional send options (list_unsubscribe_url).
 * @return bool True on success, false on PHPMailer exception.
 */
function send_mail_via_smtp(string|array $to, string $subject, string $bodyHtml, ?string $bodyText, array $emailConfig, ?array $options = null): bool
{
    $fromAddress = $emailConfig['from_address'] ?? 'noreply@localhost';
    $fromName    = $emailConfig['from_name'] ?? 'RC Flight Operations';
    $recipients  = is_array($to) ? $to : [trim($to)];

    try {
        $batchMail = mail_batch_smtp_mailer($emailConfig);
        if ($batchMail !== null) {
            $mail = $batchMail;
            $mail->clearAllRecipients();
            $mail->clearReplyTos();
            $mail->clearCustomHeaders();
        } else {
            $mail = new PHPMailer(true);
            mail_configure_smtp($mail, $emailConfig, false);
        }

        $listUnsub = trim((string) ($options['list_unsubscribe_url'] ?? ''));
        if ($listUnsub !== '') {
            $mail->addCustomHeader('List-Unsubscribe', '<' . $listUnsub . '>');
        }

        $mail->setFrom($fromAddress, $fromName);
        foreach ($recipients as $addr) {
            $mail->addAddress($addr);
        }
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body    = $bodyHtml;
        $mail->AltBody = $bodyText !== null && $bodyText !== '' ? $bodyText : strip_tags($bodyHtml);

        $mail->send();

        return true;
    } catch (PHPMailerException $e) {
        mail_last_error($e->getMessage());
        error_log('SMTP send failed: ' . $e->getMessage());

        return false;
    }
}

/** Last error message from send_mail_via_smtp (for UI feedback). */
function get_last_mail_error(): ?string {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    if (!isset($_SESSION['_mail_last_error'])) {
        return null;
    }
    $msg = $_SESSION['_mail_last_error'];
    unset($_SESSION['_mail_last_error']);
    return $msg;
}

function mail_last_error(string $msg): void {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $_SESSION['_mail_last_error'] = $msg;
}

/**
 * Send using PHP mail() with multipart/alternative (plain + HTML).
 *
 * @param  string|string[] $to          One recipient or a list (comma-separated To header).
 * @param string      $subject     Subject line.
 * @param string      $bodyHtml    HTML body.
 * @param string|null $bodyText    Plain text (or null to strip HTML).
 * @param string      $fromAddress From address.
 * @param string      $fromName    From display name.
 * @param array|null  $options     Optional send options (list_unsubscribe_url).
 * @return bool True if mail() returned true.
 */
function send_mail_via_php(string|array $to, string $subject, string $bodyHtml, ?string $bodyText, string $fromAddress, string $fromName, ?array $options = null): bool
{
    $recipients = is_array($to) ? $to : [trim($to)];
    $toHeader   = implode(', ', $recipients);

    $encodeHeader = static function (string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (function_exists('mb_encode_mimeheader')) {
            return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
        }
        // Fallback: RFC 2047 base64 (single line).
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    };

    $encodedFromName = $fromName !== '' ? $encodeHeader($fromName) : '';
    $encodedSubject  = $encodeHeader($subject);

    $fromHeader = 'From: ' . ($encodedFromName ? "\"{$encodedFromName}\" <{$fromAddress}>" : $fromAddress);
    $boundary   = '----_' . md5(uniqid());
    $headers    = [
        $fromHeader,
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];

    $listUnsub = trim((string) ($options['list_unsubscribe_url'] ?? ''));
    if ($listUnsub !== '') {
        $headers[] = 'List-Unsubscribe: <' . $listUnsub . '>';
    }

    $textPart = $bodyText !== null && $bodyText !== '' ? $bodyText : strip_tags($bodyHtml);
    $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$textPart}\r\n"
        . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$bodyHtml}\r\n"
        . "--{$boundary}--";

    return @mail($toHeader, $encodedSubject, $body, implode("\r\n", $headers));
}
