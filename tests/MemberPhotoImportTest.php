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
}
