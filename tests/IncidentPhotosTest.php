<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/incident_photos.php';

final class IncidentPhotosTest extends TestCase
{
    public function test_allowed_mimes_are_images(): void
    {
        $mimes = incident_photo_allowed_mimes();
        $this->assertArrayHasKey('image/jpeg', $mimes);
        $this->assertArrayHasKey('image/png', $mimes);
        $this->assertArrayHasKey('image/gif', $mimes);
        $this->assertSame('jpg', $mimes['image/jpeg']);
    }

    public function test_relative_path_format(): void
    {
        $this->assertSame(
            'uploads/incidents/42/7.jpg',
            incident_photo_relative_path(42, 7, 'jpg')
        );
    }

    public function test_is_local_path(): void
    {
        $this->assertTrue(incident_photo_is_local_path('uploads/incidents/3/1.png'));
        $this->assertFalse(incident_photo_is_local_path('uploads/member_photos/3.jpg'));
        $this->assertFalse(incident_photo_is_local_path('../etc/passwd'));
    }

    public function test_absolute_path_rejects_traversal(): void
    {
        $this->assertSame('', incident_photo_absolute_path('uploads/incidents/../secret.jpg'));
        $this->assertSame('', incident_photo_absolute_path('uploads/member_photos/1.jpg'));
        $abs = incident_photo_absolute_path('uploads/incidents/5/2.jpg');
        $this->assertStringEndsWith('/uploads/incidents/5/2.jpg', str_replace('\\', '/', $abs));
    }

    public function test_serve_url(): void
    {
        $this->assertSame('incident_photo.php?id=9', incident_photo_serve_url(9));
    }

    public function test_normalize_uploads_multi(): void
    {
        $files = [
            'name'     => ['a.jpg', 'b.png'],
            'type'     => ['image/jpeg', 'image/png'],
            'tmp_name' => ['/tmp/a', '/tmp/b'],
            'error'    => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
            'size'     => [100, 200],
        ];
        $out = incident_photos_normalize_uploads($files);
        $this->assertCount(2, $out);
        $this->assertSame('a.jpg', $out[0]['name']);
        $this->assertSame('b.png', $out[1]['name']);
        $this->assertSame(200, $out[1]['size']);
    }

    public function test_normalize_uploads_single(): void
    {
        $files = [
            'name'     => 'solo.jpg',
            'type'     => 'image/jpeg',
            'tmp_name' => '/tmp/solo',
            'error'    => UPLOAD_ERR_OK,
            'size'     => 50,
        ];
        $out = incident_photos_normalize_uploads($files);
        $this->assertCount(1, $out);
        $this->assertSame('solo.jpg', $out[0]['name']);
    }

    public function test_max_constants(): void
    {
        $this->assertSame(10, INCIDENT_PHOTOS_MAX);
        $this->assertSame(5242880, INCIDENT_PHOTO_MAX_BYTES);
    }
}
