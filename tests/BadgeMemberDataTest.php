<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BadgeMemberDataTest extends TestCase
{
    public function testCr80DimensionsMatchLandscapeAspectRatio(): void
    {
        $dims = badge_cr80_dimensions();

        $this->assertSame(400, $dims['cardWidthLandscape']);
        $this->assertSame(252, $dims['cardHeightLandscape']);
        $this->assertSame(252, $dims['cardWidthPortrait']);
        $this->assertSame(400, $dims['cardHeightPortrait']);
    }

    public function testMemberDataFromRowBuildsNamesAndTypeLabel(): void
    {
        $labels = [1 => 'Adult', 2 => 'Youth'];
        $row    = [
            'first_name'            => 'Jane',
            'last_name'             => 'Pilot',
            'date_joined'           => '2020-03-15',
            'membership_type_slot'  => 2,
            'membership_renewal_year' => 2026,
            'ama_number'            => '1234567',
            'faa_number'            => '',
            'gate_key_number'       => '42',
            'street'                => '1 Runway Rd',
            'street2'               => 'Suite 2',
            'city'                  => 'Springfield',
            'state'                 => 'IL',
            'postal_code'           => '62701',
            'emergency_contact_name' => 'Bob',
            'emergency_contact_relationship' => 'Spouse',
            'emergency_contact_phone' => '555-0100',
            'photo_path'            => '',
        ];

        $data = badge_member_data_from_row($row, $labels, 7);

        $this->assertSame('Pilot, Jane', $data['full_name']);
        $this->assertSame('Jane Pilot', $data['full_name_first_last']);
        $this->assertSame('03/15/2020', $data['member_since']);
        $this->assertSame('Youth', $data['membership_type']);
        $this->assertSame('2026', $data['renewal_year']);
        $this->assertSame("1 Runway Rd\nSuite 2\nSpringfield, IL 62701", $data['address_block']);
        $this->assertSame('', $data['photo_data_url']);
        $this->assertSame('', $data['photo_url']);
    }

    public function testMemberDataFromRowIncludesPhotoDataUrlWhenFileExists(): void
    {
        $root = dirname(__DIR__);
        $rel  = 'uploads/test_badge_member_photo.jpg';
        $full = $root . '/' . $rel;
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0755, true);
        }
        file_put_contents($full, 'jpeg-bytes');

        try {
            $row = [
                'first_name'           => 'A',
                'last_name'            => 'B',
                'photo_path'           => $rel,
                'membership_type_slot' => 0,
            ];
            $data = badge_member_data_from_row($row, [], 5);

            $this->assertStringStartsWith('data:image/jpeg;base64,', $data['photo_data_url']);
            $this->assertSame('badge_photo.php?id=5', $data['photo_url']);
        } finally {
            @unlink($full);
        }
    }

    public function testMemberWithAddressSqlUsesPrimaryAddressSubquery(): void
    {
        $sql = badge_member_with_address_sql();

        $this->assertStringContainsString('FROM members m', $sql);
        $this->assertStringContainsString('LEFT JOIN member_addresses a', $sql);
        $this->assertStringContainsString('ORDER BY FIELD(type, "Home", "Work", "Other")', $sql);
        $this->assertStringContainsString('WHERE m.id = ?', $sql);
    }
}
