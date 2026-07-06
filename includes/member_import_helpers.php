<?php
/**
 * includes/member_import_helpers.php
 *
 * Shared CSV/import date and membership-type parsing.
 */

/** Parse US-style dates (M/D/YY, M/D/YYYY, MM-DD-YYYY, etc.) to Y-m-d for MySQL. */
function parseDateForDb(?string $v): ?string
{
    if ($v === null || trim($v) === '') {
        return null;
    }
    $v = trim($v);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        return $v;
    }
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/', $v, $m)) {
        $y = (int) $m[3];
        if ($y < 100) {
            $y += $y < 50 ? 2000 : 1900;
        }
        $mo  = (int) $m[1];
        $day = (int) $m[2];
        if ($mo >= 1 && $mo <= 12 && $day >= 1 && $day <= 31) {
            $t = mktime(0, 0, 0, $mo, $day, $y);
            if ($t !== false) {
                return date('Y-m-d', $t);
            }
        }
    }
    if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{2,4})$/', $v, $m)) {
        $y = (int) $m[3];
        if ($y < 100) {
            $y += $y < 50 ? 2000 : 1900;
        }
        $mo  = (int) $m[1];
        $day = (int) $m[2];
        if ($mo >= 1 && $mo <= 12 && $day >= 1 && $day <= 31) {
            $t = mktime(0, 0, 0, $mo, $day, $y);
            if ($t !== false) {
                return date('Y-m-d', $t);
            }
        }
    }
    return null;
}

function normalizeBool($v): bool
{
    if (is_numeric($v)) {
        return (int) $v !== 0;
    }
    $v = strtolower(trim((string) $v));
    return in_array($v, ['1', 'yes', 'true', 'y', 'checked'], true);
}

/**
 * Map membership type label (optionally with price suffix) to slot 1–4.
 */
function normalizeMembershipTypeSlot(?string $v, array $enabledLabels): ?int
{
    if ($v === null || trim($v) === '') {
        return null;
    }
    $v = trim($v);
    if (str_contains($v, '&#') || str_contains($v, '&amp;')) {
        $v = trim(html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (str_contains($v, '&#') || str_contains($v, '&amp;')) {
            $v = trim(html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
    }
    if (preg_match('/^(.+?)\s*-\s*\$/', $v, $m)) {
        $v = trim($m[1]);
    }
    $lower = strtolower($v);
    if (ctype_digit($lower)) {
        $n = (int) $lower;
        return ($n >= 1 && $n <= 4) ? $n : null;
    }

    $legacy = ['adult' => 1, 'youth' => 2, 'senior' => 3, 'spouse' => 4];
    if (isset($legacy[$lower])) {
        return $legacy[$lower];
    }

    $first = strtok($lower, " \t,-/");
    if ($first !== false && isset($legacy[$first])) {
        return $legacy[$first];
    }

    foreach ($enabledLabels as $slot => $label) {
        if (strtolower(trim((string) $label)) === $lower) {
            return (int) $slot;
        }
    }

    return null;
}

/**
 * Pick one phone from legacy import columns (Home / Mobile / Work).
 * Uses the first mapped, non-empty value in priority order.
 */
function member_phone_from_import_row(array $row, array $mapping): ?string
{
    foreach (['phone', 'phone_mobile', 'phone_work'] as $field) {
        if (!isset($mapping[$field])) {
            continue;
        }
        $v = trim((string) ($row[$mapping[$field]] ?? ''));
        if ($v !== '') {
            return $v;
        }
    }
    return null;
}

/**
 * Pick one mailing address from import row (primary columns, then legacy address2).
 *
 * @return array{street:?string,street2:?string,city:?string,state:?string,postal_code:?string}
 */
function member_address_from_import_row(array $row, array $mapping): array
{
    $empty = ['street' => null, 'street2' => null, 'city' => null, 'state' => null, 'postal_code' => null];

    $read = static function (array $fields) use ($row, $mapping): array {
        $out = [];
        foreach ($fields as $key => $mapKey) {
            if (!isset($mapping[$mapKey])) {
                $out[$key] = '';
                continue;
            }
            $out[$key] = trim((string) ($row[$mapping[$mapKey]] ?? ''));
        }
        return $out;
    };

    $primary = $read([
        'street'      => 'street',
        'street2'     => 'street2',
        'city'        => 'city',
        'state'       => 'state',
        'postal_code' => 'postal_code',
    ]);
    if ($primary['street'] !== '' || $primary['city'] !== '' || $primary['state'] !== '' || $primary['postal_code'] !== '') {
        return [
            'street'      => $primary['street'] !== '' ? $primary['street'] : null,
            'street2'     => $primary['street2'] !== '' ? $primary['street2'] : null,
            'city'        => $primary['city'] !== '' ? $primary['city'] : null,
            'state'       => $primary['state'] !== '' ? $primary['state'] : null,
            'postal_code' => $primary['postal_code'] !== '' ? $primary['postal_code'] : null,
        ];
    }

    $legacy = $read([
        'street'      => 'address2_street',
        'street2'     => 'address2_street2',
        'city'        => 'address2_city',
        'state'       => 'address2_state',
        'postal_code' => 'address2_postal_code',
    ]);
    if ($legacy['street'] === '' && $legacy['city'] === '' && $legacy['state'] === '' && $legacy['postal_code'] === '') {
        return $empty;
    }

    return [
        'street'      => $legacy['street'] !== '' ? $legacy['street'] : null,
        'street2'     => $legacy['street2'] !== '' ? $legacy['street2'] : null,
        'city'        => $legacy['city'] !== '' ? $legacy['city'] : null,
        'state'       => $legacy['state'] !== '' ? $legacy['state'] : null,
        'postal_code' => $legacy['postal_code'] !== '' ? $legacy['postal_code'] : null,
    ];
}
