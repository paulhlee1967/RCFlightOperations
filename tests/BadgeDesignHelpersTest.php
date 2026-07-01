<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BadgeDesignHelpersTest extends TestCase
{
    public function testBackgroundRelPathForSavedTemplate(): void
    {
        $this->assertSame(
            'uploads/branding/badge_bg_12.png',
            badge_background_rel_path(12, 3, 'png')
        );
    }

    public function testBackgroundRelPathForPendingUpload(): void
    {
        $this->assertSame(
            'uploads/branding/badge_bg_pending_7.jpg',
            badge_background_rel_path(0, 7, 'jpg')
        );
    }

    public function testFinalizeBackgroundRenamesPendingPathInJson(): void
    {
        $root = badge_design_root();
        $dir  = $root . '/uploads/branding';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $userId = 99;
        $pendingRel = badge_background_rel_path(0, $userId, 'png');
        $pendingFull = $root . '/' . $pendingRel;
        file_put_contents($pendingFull, 'fake-png');

        $json = json_encode([
            'backgroundPath' => $pendingRel,
            'orientation' => 'landscape',
        ]);

        $out = badge_finalize_background_in_template($json, 42, $userId);
        $data = json_decode($out, true);

        $this->assertIsArray($data);
        $this->assertSame('uploads/branding/badge_bg_42.png', $data['backgroundPath']);
        $this->assertArrayNotHasKey('backgroundDataUrl', $data);
        $this->assertFileDoesNotExist($pendingFull);
        $this->assertFileExists($root . '/uploads/branding/badge_bg_42.png');

        @unlink($root . '/uploads/branding/badge_bg_42.png');
    }

    public function testFinalizeBackgroundLeavesUnrelatedJsonUntouched(): void
    {
        $json = '{"backgroundPath":"uploads/other.png"}';
        $this->assertSame($json, badge_finalize_background_in_template($json, 1, 1));
    }
}
