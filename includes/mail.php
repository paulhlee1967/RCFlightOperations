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

/**
 * Send an email. Uses SMTP if config has driver=smtp and smtp settings; otherwise PHP mail().
 *
 * @param string      $to           Recipient email address.
 * @param string      $subject      Subject line.
 * @param string      $bodyHtml     HTML body.
 * @param string|null $bodyText     Optional plain-text body (if null, strip_tags of HTML used for mail()).
 * @param array|null  $emailConfig Optional config (from getSystemMailConfig etc.). When null, uses $config['email'].
 * @return bool True if sent successfully, false otherwise.
 */
function send_mail(string $to, string $subject, string $bodyHtml, ?string $bodyText = null, ?array $emailConfig = null): bool
{
    global $config, $mailFromAddress, $mailFromName;

    $to = trim($to);
    if ($to === '') {
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
        return send_mail_via_smtp($to, $subject, $bodyHtml, $bodyText, $email);
    }

    return send_mail_via_php($to, $subject, $bodyHtml, $bodyText, $fromAddress, $fromName);
}

/**
 * Send using PHPMailer and SMTP config.
 *
 * @param string      $to          Recipient email.
 * @param string      $subject     Subject line.
 * @param string      $bodyHtml    HTML body.
 * @param string|null $bodyText    Plain-text alternative (or null to derive from HTML).
 * @param array       $emailConfig Config array with from_address, from_name, smtp (host, port, username, password, encryption).
 * @return bool True on success, false on PHPMailer exception.
 */
function send_mail_via_smtp(string $to, string $subject, string $bodyHtml, ?string $bodyText, array $emailConfig): bool
{
    $fromAddress = $emailConfig['from_address'] ?? 'noreply@localhost';
    $fromName    = $emailConfig['from_name'] ?? 'RC Flight Operations';
    $smtp        = $emailConfig['smtp'] ?? [];

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $smtp['host'];
        $mail->Port       = (int) ($smtp['port'] ?? 587);
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtp['username'];
        $mail->Password   = $smtp['password'];
        $mail->SMTPSecure = ($smtp['encryption'] ?? 'tls') === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet    = PHPMailer::CHARSET_UTF8;

        $mail->setFrom($fromAddress, $fromName);
        $mail->addAddress($to);
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
 * @param string      $to          Recipient email.
 * @param string      $subject     Subject line.
 * @param string      $bodyHtml    HTML body.
 * @param string|null $bodyText    Plain text (or null to strip HTML).
 * @param string      $fromAddress From address.
 * @param string      $fromName    From display name.
 * @return bool True if mail() returned true.
 */
function send_mail_via_php(string $to, string $subject, string $bodyHtml, ?string $bodyText, string $fromAddress, string $fromName): bool
{
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

    $textPart = $bodyText !== null && $bodyText !== '' ? $bodyText : strip_tags($bodyHtml);
    $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$textPart}\r\n"
        . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$bodyHtml}\r\n"
        . "--{$boundary}--";

    return @mail($to, $encodedSubject, $body, implode("\r\n", $headers));
}
