<?php
/**
 * includes/member_completeness.php
 *
 * Shared rules for incomplete member records (reports, review panels, etc.).
 */

/**
 * Missing field labels for a member row that includes joined address/phone counts.
 *
 * Expected keys on $member (from members + subqueries):
 *   email, ama_number, ama_expiration, ama_life_member, faa_number,
 *   membership_type_slot, emergency_contact_name, emergency_contact_phone,
 *   phone_count, addr_street, addr_city
 *
 * @return list<string>
 */
function memberCompletenessMissingFields(array $member): array
{
    $missing = [];

    if (trim((string) ($member['email'] ?? '')) === '') {
        $missing[] = 'Email';
    }

    if ((int) ($member['phone_count'] ?? 0) < 1) {
        $missing[] = 'Phone';
    }

    if (trim((string) ($member['addr_street'] ?? '')) === '' || trim((string) ($member['addr_city'] ?? '')) === '') {
        $missing[] = 'Mailing address';
    }

    $emName  = trim((string) ($member['emergency_contact_name'] ?? ''));
    $emPhone = trim((string) ($member['emergency_contact_phone'] ?? ''));
    if ($emName === '' || $emPhone === '') {
        $missing[] = 'Emergency contact';
    }

    $amaLife = !empty($member['ama_life_member']);
    if (!$amaLife && trim((string) ($member['ama_number'] ?? '')) === '') {
        $missing[] = 'AMA number';
    }
    if (!$amaLife && trim((string) ($member['ama_expiration'] ?? '')) === '') {
        $missing[] = 'AMA expiration';
    }

    if (trim((string) ($member['faa_number'] ?? '')) === '') {
        $missing[] = 'FAA number';
    }

    if (empty($member['membership_type_slot'])) {
        $missing[] = 'Membership type';
    }

    return $missing;
}

/**
 * SQL fragment: member row with phone count and primary mailing address columns.
 */
function memberCompletenessSelectSql(string $alias = 'm'): string
{
    return "{$alias}.id, {$alias}.last_name, {$alias}.first_name, {$alias}.email, {$alias}.allow_email,
            {$alias}.ama_number, {$alias}.ama_expiration, {$alias}.ama_life_member,
            {$alias}.faa_number, {$alias}.membership_type_slot,
            {$alias}.emergency_contact_name, {$alias}.emergency_contact_phone,
            (SELECT COUNT(*) FROM member_phones mp WHERE mp.member_id = {$alias}.id) AS phone_count,
            (SELECT street FROM member_addresses WHERE member_id = {$alias}.id ORDER BY FIELD(type,\"Home\",\"Work\",\"Other\") LIMIT 1) AS addr_street,
            (SELECT city FROM member_addresses WHERE member_id = {$alias}.id ORDER BY FIELD(type,\"Home\",\"Work\",\"Other\") LIMIT 1) AS addr_city";
}
