<?php
/**
 * includes/badge_member_data.php
 *
 * Build badge template field values from a member row (design preview + print).
 */

declare(strict_types=1);

/** CR80 card dimensions in Fabric pixels (85.6 × 53.98 mm at ~118 px/in). */
function badge_cr80_dimensions(): array
{
    $landscapeW = 400;
    $landscapeH = (int) round(400 * 53.98 / 85.6);

    return [
        'cardWidthLandscape'  => $landscapeW,
        'cardHeightLandscape' => $landscapeH,
        'cardWidthPortrait'   => $landscapeH,
        'cardHeightPortrait'  => $landscapeW,
    ];
}

/**
 * @param array<string, mixed> $m Member row with optional address columns
 * @param array<int, string>     $membershipTypeLabels
 *
 * @return array<string, string>
 */
function badge_member_data_from_row(array $m, array $membershipTypeLabels, int $memberId): array
{
    $memberSince = '';
    if (!empty($m['date_joined'])) {
        $memberSince = date('m/d/Y', strtotime((string) $m['date_joined']));
    }

    $photoDataUrl = '';
    $photoUrl     = '';
    if (!empty($m['photo_path'])) {
        $root      = dirname(__DIR__);
        $photoFile = $root . '/' . $m['photo_path'];
        if (is_file($photoFile) && is_readable($photoFile)) {
            if ($memberId > 0) {
                $photoUrl = 'badge_photo.php?id=' . $memberId;
            }
            $ext  = strtolower(pathinfo($photoFile, PATHINFO_EXTENSION));
            $mime = $ext === 'png' ? 'image/png' : ($ext === 'gif' ? 'image/gif' : 'image/jpeg');
            $photoDataUrl = 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($photoFile));
        }
    }

    $addressBlock = '';
    if (!empty($m['street']) && !empty($m['city'])) {
        $addressBlock = trim((string) $m['street']);
        if (!empty($m['street2'])) {
            $addressBlock .= "\n" . trim((string) $m['street2']);
        }
        $addressBlock .= "\n" . trim(
            ($m['city'] ?? '') . ', ' . ($m['state'] ?? '') . ' ' . ($m['postal_code'] ?? '')
        );
    }

    $slot = (int) ($m['membership_type_slot'] ?? 0);

    return [
        'full_name'                      => trim(($m['last_name'] ?? '') . ', ' . ($m['first_name'] ?? '')),
        'full_name_first_last'           => trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')),
        'first_name'                     => (string) ($m['first_name'] ?? ''),
        'last_name'                      => (string) ($m['last_name'] ?? ''),
        'member_since'                   => $memberSince,
        'date_joined'                    => (string) ($m['date_joined'] ?? ''),
        'membership_type'                => $slot > 0
            ? ($membershipTypeLabels[$slot] ?? ('Type ' . $slot))
            : '',
        'renewal_year'                   => (string) ($m['membership_renewal_year'] ?? ''),
        'ama_number'                     => (string) ($m['ama_number'] ?? ''),
        'faa_number'                     => (string) ($m['faa_number'] ?? ''),
        'gate_key_number'                => (string) ($m['gate_key_number'] ?? ''),
        'street'                         => (string) ($m['street'] ?? ''),
        'street2'                        => (string) ($m['street2'] ?? ''),
        'city'                           => (string) ($m['city'] ?? ''),
        'state'                          => (string) ($m['state'] ?? ''),
        'postal_code'                    => (string) ($m['postal_code'] ?? ''),
        'address_block'                  => $addressBlock,
        'emergency_contact_name'         => (string) ($m['emergency_contact_name'] ?? ''),
        'emergency_contact_relationship' => (string) ($m['emergency_contact_relationship'] ?? ''),
        'emergency_contact_phone'        => (string) ($m['emergency_contact_phone'] ?? ''),
        'photo_path'                     => (string) ($m['photo_path'] ?? ''),
        'photo_data_url'                 => $photoDataUrl,
        'photo_url'                      => $photoUrl,
    ];
}

/**
 * SQL fragment: member + primary address (Home > Work > Other).
 */
function badge_member_with_address_sql(): string
{
    return '
        SELECT m.id, m.first_name, m.last_name, m.email, m.date_joined, m.membership_type_slot,
               m.membership_renewal_year, m.ama_number, m.faa_number, m.gate_key_number, m.photo_path,
               m.emergency_contact_name, m.emergency_contact_relationship, m.emergency_contact_phone,
               m.address_street AS street, m.address_street2 AS street2, m.address_city AS city,
               m.address_state AS state, m.address_postal_code AS postal_code
        FROM members m
        WHERE m.id = ?
    ';
}
