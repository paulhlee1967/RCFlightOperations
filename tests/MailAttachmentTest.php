<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!is_file(dirname(__DIR__) . '/config.php') && !isset($config)) {
    $config = [
        'email' => [
            'driver'       => 'mail',
            'from_address' => 'noreply@example.com',
            'from_name'    => 'Test',
        ],
    ];
}
require_once dirname(__DIR__) . '/includes/mail.php';

final class MailAttachmentTest extends TestCase
{
    public function test_message_without_attachments_stays_alternative(): void
    {
        $mime = mail_php_mime_message(
            'noreply@example.com',
            'Club',
            'Roster',
            '<p>Hello</p>',
            'Hello',
            ['boundary_seed' => 'plain']
        );

        $this->assertStringContainsString('multipart/alternative', $mime['headers']);
        $this->assertStringNotContainsString('multipart/mixed', $mime['headers']);
        $this->assertStringContainsString('Hello', $mime['body']);
    }

    public function test_pdf_attachment_is_base64_mixed_part(): void
    {
        $pdf = "%PDF-1.4\nroster";
        $mime = mail_php_mime_message(
            'noreply@example.com',
            'Club',
            'Roster',
            '<p>See attached</p>',
            'See attached',
            [
                'boundary_seed' => 'pdf',
                'attachments'   => [[
                    'filename' => 'report_current_members_2027_2026-10-20.pdf',
                    'content'  => $pdf,
                    'mime'     => 'application/pdf',
                ]],
            ]
        );

        $this->assertStringContainsString('multipart/mixed', $mime['headers']);
        $this->assertStringContainsString('filename="report_current_members_2027_2026-10-20.pdf"', $mime['body']);
        $this->assertStringContainsString('Content-Type: application/pdf', $mime['body']);
        $this->assertStringContainsString(base64_encode($pdf), $mime['body']);
    }

    public function test_unsafe_attachment_filename_is_sanitized(): void
    {
        $files = mail_normalize_attachments([
            'attachments' => [[
                'filename' => '../secret roster.pdf',
                'content'  => 'pdf-bytes',
                'mime'     => 'application/pdf',
            ]],
        ]);

        $this->assertSame('secret_roster.pdf', $files[0]['filename']);
    }
}
