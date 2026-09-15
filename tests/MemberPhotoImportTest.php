<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/member_save.php';

final class MemberPhotoImportTest extends TestCase
{
    public function test_pick_first_url_uses_first_non_empty_value(): void
    {
        $this->assertSame(
            'https://pvmac.com/wp-content/uploads/a.jpg',
            member_photo_pick_first_url('https://pvmac.com/wp-content/uploads/a.jpg, https://pvmac.com/wp-content/uploads/b.jpg')
        );
    }

    public function test_pick_first_url_trims_whitespace(): void
    {
        $this->assertSame(
            'https://pvmac.com/photo.jpg',
            member_photo_pick_first_url('  https://pvmac.com/photo.jpg  ')
        );
    }

    public function test_pick_first_url_returns_empty_for_blank_input(): void
    {
        $this->assertSame('', member_photo_pick_first_url(''));
        $this->assertSame('', member_photo_pick_first_url(' , , '));
    }

    public function test_url_is_allowed_for_configured_host(): void
    {
        $this->assertTrue(member_photo_url_is_allowed(
            'https://www.pvmac.com/wp-content/uploads/2026/03/badge.jpg',
            ['pvmac.com']
        ));
    }

    public function test_url_is_allowed_for_subdomain(): void
    {
        $this->assertTrue(member_photo_url_is_allowed(
            'https://cdn.pvmac.com/uploads/badge.jpg',
            ['pvmac.com']
        ));
    }

    public function test_url_is_rejected_for_unknown_host(): void
    {
        $this->assertFalse(member_photo_url_is_allowed(
            'https://evil.example.com/badge.jpg',
            ['pvmac.com']
        ));
    }

    public function test_url_is_rejected_for_non_http_scheme(): void
    {
        $this->assertFalse(member_photo_url_is_allowed(
            'ftp://pvmac.com/badge.jpg',
            ['pvmac.com']
        ));
    }

    public function test_save_photo_from_local_file_rejects_non_image(): void
    {
        $pdo = $this->createMock(PDO::class);
        $tmp = tempnam(sys_get_temp_dir(), 'member_photo_test_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, '%PDF-1.4 fake pdf content');

        try {
            $result = member_save_photo_from_local_file($pdo, 1, $tmp);
            $this->assertFalse($result['ok']);
            $this->assertSame('Badge photo must be a JPEG, PNG, or GIF image.', $result['error']);
            $this->assertNull($result['photo_path']);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_save_faa_card_from_local_file_rejects_non_supported_format(): void
    {
        $pdo = $this->createMock(PDO::class);
        $tmp = tempnam(sys_get_temp_dir(), 'member_faa_card_test_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, 'not a real image or pdf');

        try {
            $result = member_save_faa_card_from_local_file($pdo, 1, $tmp);
            $this->assertFalse($result['ok']);
            $this->assertSame('FAA card must be a PDF, JPG, or PNG file.', $result['error']);
            $this->assertNull($result['faa_card_path']);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_save_faa_card_from_local_file_accepts_pdf(): void
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);
        $pdo->method('prepare')->willReturn($stmt);
        $stmt->method('execute')->willReturn(true);

        $tmp = tempnam(sys_get_temp_dir(), 'member_faa_pdf_test_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");

        $uploadDir = dirname(__DIR__) . '/uploads/member_faa_cards';
        $destFile = $uploadDir . '/1.pdf';
        if (is_file($destFile)) {
            @unlink($destFile);
        }

        try {
            $result = member_save_faa_card_from_local_file($pdo, 1, $tmp);
            $this->assertTrue($result['ok'], $result['error'] ?? 'expected PDF save to succeed');
            $this->assertSame('uploads/member_faa_cards/1.pdf', $result['faa_card_path']);
        } finally {
            @unlink($tmp);
            if (is_file($destFile)) {
                @unlink($destFile);
            }
        }
    }

    public function test_save_faa_card_removes_sibling_files_with_other_extensions(): void
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);
        $pdo->method('prepare')->willReturn($stmt);
        $stmt->method('execute')->willReturn(true);

        $uploadDir = dirname(__DIR__) . '/uploads/member_faa_cards';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $memberId = 900001;
        $orphanJpg = $uploadDir . '/' . $memberId . '.jpg';
        $orphanPng = $uploadDir . '/' . $memberId . '.png';
        $destPdf = $uploadDir . '/' . $memberId . '.pdf';
        file_put_contents($orphanJpg, 'orphan-jpg');
        file_put_contents($orphanPng, 'orphan-png');

        $tmp = tempnam(sys_get_temp_dir(), 'member_faa_pdf_sib_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");

        try {
            $result = member_save_faa_card_from_local_file($pdo, $memberId, $tmp);
            $this->assertTrue($result['ok'], $result['error'] ?? 'expected PDF save to succeed');
            $this->assertSame('uploads/member_faa_cards/' . $memberId . '.pdf', $result['faa_card_path']);
            $this->assertFileExists($destPdf);
            $this->assertFileDoesNotExist($orphanJpg);
            $this->assertFileDoesNotExist($orphanPng);
        } finally {
            @unlink($tmp);
            @unlink($destPdf);
            @unlink($orphanJpg);
            @unlink($orphanPng);
        }
    }

    public function test_upload_remove_id_files_deletes_all_when_keep_is_null(): void
    {
        $dir = sys_get_temp_dir() . '/flightops_member_upload_test_' . getmypid();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $a = $dir . '/42.jpg';
        $b = $dir . '/42.pdf';
        $other = $dir . '/43.jpg';
        file_put_contents($a, 'a');
        file_put_contents($b, 'b');
        file_put_contents($other, 'c');

        try {
            member_upload_remove_id_files($dir, 42);
            $this->assertFileDoesNotExist($a);
            $this->assertFileDoesNotExist($b);
            $this->assertFileExists($other);
        } finally {
            @unlink($a);
            @unlink($b);
            @unlink($other);
            @rmdir($dir);
        }
    }

    public function test_import_faa_card_from_url_rejects_disallowed_host(): void
    {
        $pdo = $this->createMock(PDO::class);
        $result = member_import_faa_card_from_url($pdo, 1, 'https://evil.example.com/faa.pdf');
        $this->assertFalse($result['ok']);
        $this->assertSame('FAA card URL is not from an allowed host.', $result['error']);
    }
}
