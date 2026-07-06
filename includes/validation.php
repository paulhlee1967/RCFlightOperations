<?php
/**
 * includes/validation.php
 *
 * Server-side validation helpers. Returns structured error arrays so the
 * same validation logic works for both page-level POST handlers and any
 * future API endpoints.
 *
 * Functions:
 *   validate_member_input()   — validate the full member save form
 *   validate_payment_input()  — validate the add-payment form
 *   validate_email()          — validate a single email address
 *   validate_date()           — validate a Y-m-d date string
 *   validate_positive_number()— validate a non-negative numeric value
 *
 * All validators return an array: [bool $ok, string $error].
 * validate_member_input() and validate_payment_input() return
 * [array $errors, array $clean] where $errors is field => message.
 * Member clean data is returned in the $clean array.
 */

require_once __DIR__ . '/helpers.php';

/**
 * Validate and sanitise the member save form POST data.
 *
 * Returns [$errors, $clean]:
 *   $errors — associative array of field => error message (empty = valid)
 *   $clean  — sanitised values ready for use in SQL
 *
 * @param  array  $post  Raw $_POST data.
 * @return array{array, array}
 */
function validate_member_input(array $post): array {
    $errors = [];
    $clean  = [];

    // ── Required: first name ──────────────────────────────────────────────
    $clean['first_name'] = trim($post['first_name'] ?? '');
    if ($clean['first_name'] === '') {
        $errors['first_name'] = 'First name is required.';
    } elseif (mb_strlen($clean['first_name']) > 100) {
        $errors['first_name'] = 'First name must be 100 characters or fewer.';
    }

    // ── Required: last name ───────────────────────────────────────────────
    $clean['last_name'] = trim($post['last_name'] ?? '');
    if ($clean['last_name'] === '') {
        $errors['last_name'] = 'Last name is required.';
    } elseif (mb_strlen($clean['last_name']) > 100) {
        $errors['last_name'] = 'Last name must be 100 characters or fewer.';
    }

    // ── Optional: title ───────────────────────────────────────────────────
    $clean['title'] = trim($post['title'] ?? '');

    // ── Optional: email ───────────────────────────────────────────────────
    $rawEmail = trim($post['email'] ?? '');
    if ($rawEmail !== '') {
        [$emailOk, $emailErr] = validate_email($rawEmail);
        if (!$emailOk) {
            $errors['email'] = $emailErr;
            $clean['email']  = null;
        } else {
            $clean['email'] = normalize_email($rawEmail);
        }
    } else {
        $clean['email'] = null;
    }

    // ── Optional: birthday ────────────────────────────────────────────────
    $rawBirthday = trim($post['birthday'] ?? '');
    if ($rawBirthday !== '') {
        [$dateOk, $dateErr] = validate_date($rawBirthday);
        if (!$dateOk) {
            $errors['birthday'] = $dateErr;
            $clean['birthday']  = null;
        } else {
            $clean['birthday'] = $rawBirthday;
        }
    } else {
        $clean['birthday'] = null;
    }

    // ── Optional: date joined ─────────────────────────────────────────────
    $rawJoined = trim($post['date_joined'] ?? '');
    if ($rawJoined !== '') {
        [$dateOk, $dateErr] = validate_date($rawJoined);
        if (!$dateOk) {
            $errors['date_joined'] = 'Date joined: ' . $dateErr;
            $clean['date_joined']  = null;
        } else {
            $clean['date_joined'] = $rawJoined;
        }
    } else {
        $clean['date_joined'] = null;
    }

    // ── Optional: membership type slot (1–4) ──────────────────────────────
    $rawSlot = $post['membership_type_slot'] ?? ($post['membership_type'] ?? '');
    $slot = is_numeric($rawSlot) ? (int) $rawSlot : 0;
    $clean['membership_type_slot'] = ($slot >= 1 && $slot <= 4) ? $slot : null;

    // ── Optional: renewal year ────────────────────────────────────────────
    $rawYear = trim($post['membership_renewal_year'] ?? '');
    if ($rawYear !== '') {
        $year = (int) $rawYear;
        if ($year < 1990 || $year > 2100) {
            $errors['membership_renewal_year'] = 'Renewal year must be between 1990 and 2100.';
            $clean['membership_renewal_year']  = null;
        } else {
            $clean['membership_renewal_year'] = $year;
        }
    } else {
        $clean['membership_renewal_year'] = null;
    }

    // ── Optional: AMA number (normalized; one membership per number) ──────
    $rawAma = trim($post['ama_number'] ?? '');
    if ($rawAma !== '') {
        require_once __DIR__ . '/ama_verify.php';
        $normalizedAma = ama_verify_normalize_number($rawAma);
        $clean['ama_number'] = $normalizedAma !== '' ? $normalizedAma : null;
    } else {
        $clean['ama_number'] = null;
    }

    // ── Optional: AMA expiration ──────────────────────────────────────────
    $rawAmaExp = trim($post['ama_expiration'] ?? '');
    if ($rawAmaExp !== '') {
        [$dateOk, $dateErr] = validate_date($rawAmaExp);
        if (!$dateOk) {
            $errors['ama_expiration'] = 'AMA expiration: ' . $dateErr;
            $clean['ama_expiration']  = null;
        } else {
            $clean['ama_expiration'] = $rawAmaExp;
        }
    } else {
        $clean['ama_expiration'] = null;
    }

    // ── Optional: FAA number ──────────────────────────────────────────────
    $clean['faa_number'] = trim($post['faa_number'] ?? '') ?: null;

    // ── Optional: FAA expiration ──────────────────────────────────────────
    $rawFaaExp = trim($post['faa_expiration'] ?? '');
    if ($rawFaaExp !== '') {
        [$dateOk, $dateErr] = validate_date($rawFaaExp);
        if (!$dateOk) {
            $errors['faa_expiration'] = 'FAA expiration: ' . $dateErr;
            $clean['faa_expiration']  = null;
        } else {
            $clean['faa_expiration'] = $rawFaaExp;
        }
    } else {
        $clean['faa_expiration'] = null;
    }

    // ── Boolean flags ─────────────────────────────────────────────────────
    $clean['inactive']       = !empty($post['inactive'])       ? 1 : 0;
    $clean['suspended']      = !empty($post['suspended'])      ? 1 : 0;
    $clean['life_member']    = !empty($post['life_member'])    ? 1 : 0;
    $clean['free_membership']= !empty($post['free_membership'])? 1 : 0;
    $clean['ama_life_member']= !empty($post['ama_life_member'])? 1 : 0;

    // ── Optional free-text fields ─────────────────────────────────────────
    $clean['notes']                      = trim($post['notes'] ?? '') ?: null;
    $clean['gate_key_number']            = trim($post['gate_key_number'] ?? '') ?: null;
    $clean['emergency_contact_name']     = trim($post['emergency_contact_name'] ?? '') ?: null;
    $clean['emergency_contact_relationship'] = trim($post['emergency_contact_relationship'] ?? '') ?: null;
    $clean['emergency_contact_phone']    = trim($post['emergency_contact_phone'] ?? '') ?: null;
    $clean['phone']                      = trim($post['phone'] ?? '') ?: null;
    $clean['address_street']             = trim($post['address_street'] ?? '') ?: null;
    $clean['address_street2']            = trim($post['address_street2'] ?? '') ?: null;
    $clean['address_city']               = trim($post['address_city'] ?? '') ?: null;
    $clean['address_state']              = trim($post['address_state'] ?? '') ?: null;
    $clean['address_postal_code']        = trim($post['address_postal_code'] ?? '') ?: null;

    return [$errors, $clean];
}

/**
 * Validate the add-payment form POST data.
 *
 * Returns [$errors, $clean].
 *
 * @param  array  $post  Raw $_POST data.
 * @return array{array, array}
 */
function validate_payment_input(array $post): array {
    $errors = [];
    $clean  = [];

    // ── Required: paid_at date ────────────────────────────────────────────
    $rawDate = trim($post['paid_at'] ?? '');
    if ($rawDate === '') {
        $errors['paid_at'] = 'Payment date is required.';
        $clean['paid_at']  = null;
    } else {
        [$dateOk, $dateErr] = validate_date($rawDate);
        if (!$dateOk) {
            $errors['paid_at'] = 'Payment date: ' . $dateErr;
            $clean['paid_at']  = null;
        } else {
            $clean['paid_at'] = $rawDate;
        }
    }

    // ── Required: year ────────────────────────────────────────────────────
    $year = (int) ($post['year'] ?? 0);
    if ($year < 1990 || $year > 2100) {
        $errors['year'] = 'Year must be between 1990 and 2100.';
        $clean['year']  = (int) date('Y');
    } else {
        $clean['year'] = $year;
    }

    // ── Amounts: non-negative numbers ─────────────────────────────────────
    foreach (['amount_dues', 'amount_initiation', 'amount_late_fee'] as $field) {
        $raw = $post[$field] ?? '0';
        [$numOk, $numErr] = validate_positive_number($raw);
        if (!$numOk) {
            $errors[$field] = ucwords(str_replace('_', ' ', $field)) . ': ' . $numErr;
            $clean[$field]  = 0.0;
        } else {
            $clean[$field] = (float) $raw;
        }
    }

    $clean['comp'] = !empty($post['comp']) ? 1 : 0;

    return [$errors, $clean];
}

/**
 * Validate a single email address.
 *
 * @param  string  $email
 * @return array{bool, string}  [true, ''] or [false, error message]
 */
function validate_email(string $email): array {
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return [false, 'Please enter a valid email address.'];
    }
    if (mb_strlen($email) > 254) {
        return [false, 'Email address is too long (max 254 characters).'];
    }
    return [true, ''];
}

/**
 * Validate a date string. Accepts Y-m-d format (the value produced by
 * <input type="date"> and stored in the database).
 *
 * @param  string  $date
 * @return array{bool, string}
 */
function validate_date(string $date): array {
    // Accept Y-m-d only (the HTML date input format).
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return [false, 'Date must be in YYYY-MM-DD format.'];
    }
    $parts = explode('-', $date);
    if (!checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
        return [false, 'That date is not valid.'];
    }
    return [true, ''];
}

/**
 * Validate that a value is a non-negative number (suitable for dollar amounts).
 *
 * @param  mixed  $value
 * @return array{bool, string}
 */
function validate_positive_number(mixed $value): array {
    if (!is_numeric($value)) {
        return [false, 'Must be a number.'];
    }
    if ((float) $value < 0) {
        return [false, 'Must be zero or greater.'];
    }
    return [true, ''];
}

/**
 * Whether another member already uses this AMA number (normalized comparison).
 *
 * @return array{id:int,first_name:string,last_name:string,ama_number:string}|null
 */
function member_find_by_ama_number(PDO $pdo, ?string $amaNumber, ?int $excludeMemberId = null): ?array
{
    require_once __DIR__ . '/ama_verify.php';

    $normalized = ama_verify_normalize_number((string) $amaNumber);
    if ($normalized === '') {
        return null;
    }

    $stmt = $pdo->query(
        "SELECT id, first_name, last_name, ama_number
         FROM members
         WHERE ama_number IS NOT NULL AND TRIM(ama_number) != ''"
    );

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (ama_verify_normalize_number((string) $row['ama_number']) !== $normalized) {
            continue;
        }
        if ($excludeMemberId !== null && (int) $row['id'] === $excludeMemberId) {
            continue;
        }

        return [
            'id'         => (int) $row['id'],
            'first_name' => (string) $row['first_name'],
            'last_name'  => (string) $row['last_name'],
            'ama_number' => (string) $row['ama_number'],
        ];
    }

    return null;
}

/**
 * Human-readable error when an AMA number is already assigned to another member.
 */
function member_ama_number_conflict_message(?array $conflict): ?string
{
    if ($conflict === null) {
        return null;
    }

    return 'AMA number ' . $conflict['ama_number'] . ' is already assigned to '
        . $conflict['first_name'] . ' ' . $conflict['last_name'] . '.';
}
