<?php
/**
 * Import members (and optional payments) from CSV.
 * Steps: Upload → Map columns → Preview → Import.
 *
 * Field rules align with includes/validation.php (dates, lengths, etc.) where
 * applicable; CSV parsing uses import-specific helpers (parseDateForDb, etc.).
 */
ob_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/validation.php';
require_once __DIR__ . '/includes/member_import_helpers.php';
require_once __DIR__ . '/includes/member_match.php';

requireLogin();

if (!canEditMembers()) {
    header('Location: index.php');
    exit;
}
$membershipTypeLabels = enabledMembershipTypeLabels($pdo);
$enabledLabels = $membershipTypeLabels;

$importFields = [
    'first_name' => ['label' => 'First name', 'required' => true],
    'last_name'  => ['label' => 'Last name', 'required' => true],
    'email'      => ['label' => 'Email', 'required' => false],
    'title'      => ['label' => 'Title', 'required' => false],
    'birthday'   => ['label' => 'Birthday', 'required' => false],
    'notes'      => ['label' => 'Notes', 'required' => false],
    'date_joined'=> ['label' => 'Date Joined', 'required' => false],
    'membership_type_slot' => ['label' => 'Membership type slot (1-4)', 'required' => false],
    'membership_renewal_year' => ['label' => 'Renewal year', 'required' => false],
    'inactive'   => ['label' => 'Member Inactive', 'required' => false],
    'suspended'  => ['label' => 'Member Suspended', 'required' => false],
    'life_member'=> ['label' => 'Life Member', 'required' => false],
    'free_membership' => ['label' => 'Free Membership', 'required' => false],
    'gate_key_number' => ['label' => 'Gate key number', 'required' => false],
    'ama_number' => ['label' => 'AMA number', 'required' => false],
    'ama_expiration' => ['label' => 'AMA Expiry', 'required' => false],
    'ama_life_member' => ['label' => 'AMA Life Member', 'required' => false],
    'faa_number' => ['label' => 'FAA Number', 'required' => false],
    'faa_expiration' => ['label' => 'FAA Expiry', 'required' => false],
    'emergency_contact_name' => ['label' => 'Emergency contact name', 'required' => false],
    'emergency_contact_relationship' => ['label' => 'Emergency contact relationship', 'required' => false],
    'emergency_contact_phone' => ['label' => 'Emergency contact phone', 'required' => false],
    'phone'      => ['label' => 'Phone', 'required' => false],
    'phone_mobile' => ['label' => 'Mobile (legacy)', 'required' => false],
    'phone_work' => ['label' => 'Work (legacy)', 'required' => false],
    'street'     => ['label' => 'Street', 'required' => false],
    'street2'    => ['label' => 'Street 2', 'required' => false],
    'city'       => ['label' => 'City', 'required' => false],
    'state'      => ['label' => 'State', 'required' => false],
    'postal_code'=> ['label' => 'Postal / ZIP', 'required' => false],
    'address2_street' => ['label' => 'Address 2 Street (legacy)', 'required' => false],
    'address2_street2' => ['label' => 'Address 2 Street 2 (legacy)', 'required' => false],
    'address2_city' => ['label' => 'Address 2 City (legacy)', 'required' => false],
    'address2_state' => ['label' => 'Address 2 State (legacy)', 'required' => false],
    'address2_postal_code' => ['label' => 'Address 2 Postal Code (legacy)', 'required' => false],
    'payment_year' => ['label' => 'Payment year', 'required' => false],
    'payment_date' => ['label' => 'Payment date', 'required' => false],
    'amount_dues'=> ['label' => 'Amount dues', 'required' => false],
    'amount_initiation' => ['label' => 'Amount initiation', 'required' => false],
];

function parseCsvUpload(string $tmpPath): array {
    $fh = fopen($tmpPath, 'rb');
    if ($fh === false) return ['headers' => [], 'rows' => [], 'error' => 'Could not read file'];
    // Strip UTF-8 BOM if present
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($fh);
    }
    $delim = ',';
    $headers = fgetcsv($fh, 0, $delim, '"', '\\');
    if ($headers === false || count($headers) < 2) {
        fclose($fh);
        return ['headers' => [], 'rows' => [], 'error' => 'File has no valid header row'];
    }
    $headers = array_map('trim', $headers);
    $rows = [];
    while (($row = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
        if (count($row) === 1 && trim($row[0] ?? '') === '') continue;
        if (count($row) !== count($headers)) {
            $row = array_pad($row, count($headers), '');
        }
        $rows[] = array_combine($headers, array_slice($row, 0, count($headers)));
    }
    fclose($fh);
    return ['headers' => $headers, 'rows' => $rows, 'error' => null];
}

/** Above this count, parsed rows are stored in a temp file instead of $_SESSION (avoids session size limits). */
// Above this row count, parsed CSV is stored in a temp file instead of $_SESSION
// to avoid exceeding PHP session size limits on shared hosts.
const IMPORT_ROWS_SESSION_THRESHOLD = 400;

function import_clear_row_blob(): void {
    if (!empty($_SESSION['import_rows_file']) && is_string($_SESSION['import_rows_file'])) {
        $p = $_SESSION['import_rows_file'];
        if (is_file($p)) {
            @unlink($p);
        }
    }
    unset($_SESSION['import_rows_file']);
}

function import_rows_from_session(): array {
    if (!empty($_SESSION['import_rows_file']) && is_string($_SESSION['import_rows_file'])) {
        $p = $_SESSION['import_rows_file'];
        if (!is_readable($p)) {
            return [];
        }
        $raw = file_get_contents($p);
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
    return $_SESSION['import_rows'] ?? [];
}

$step = $_GET['step'] ?? 'upload';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    if (isset($_POST['action']) && $_POST['action'] === 'upload') {
        if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
            $error = 'Please choose a CSV file.';
        } elseif ($_FILES['csv']['size'] > 2 * 1024 * 1024) {
            $error = 'File is too large. Maximum size is 2 MB.';
        } else {
            $parsed = parseCsvUpload($_FILES['csv']['tmp_name']);
            if ($parsed['error']) {
                $error = $parsed['error'];
            } else {
                import_clear_row_blob();
                $parsedRows = $parsed['rows'];
                if (count($parsedRows) > IMPORT_ROWS_SESSION_THRESHOLD) {
                    $tmp = tempnam(sys_get_temp_dir(), 'rcfo_imp_');
                    if ($tmp === false || file_put_contents($tmp, json_encode($parsedRows, JSON_UNESCAPED_UNICODE)) === false) {
                        if ($tmp !== false) {
                            @unlink($tmp);
                        }
                        $error = 'Could not stage this large import. Split the CSV into smaller files (under '
                            . IMPORT_ROWS_SESSION_THRESHOLD . ' rows each) or raise PHP session limits.';
                    } else {
                        $_SESSION['import_rows_file'] = $tmp;
                        $_SESSION['import_headers']   = $parsed['headers'];
                        unset($_SESSION['import_rows']);
                        $_SESSION['import_mapping'] = [];
                        header('Location: import.php?step=map');
                        exit;
                    }
                } else {
                    $_SESSION['import_headers'] = $parsed['headers'];
                    $_SESSION['import_rows']    = $parsedRows;
                    $_SESSION['import_mapping'] = [];
                    header('Location: import.php?step=map');
                    exit;
                }
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'map') {
        $mapping = [];
        foreach (array_keys($importFields) as $field) {
            $col = $_POST['map_' . $field] ?? '';
            if ($col !== '') {
                $mapping[$field] = $col;
            }
        }
        if (empty($mapping['first_name']) || empty($mapping['last_name'])) {
            $error = 'First name and Last name columns are required.';
        } else {
            $_SESSION['import_mapping'] = $mapping;
            header('Location: import.php?step=preview');
            exit;
        }
        $step = 'map';
    } elseif (isset($_POST['action']) && $_POST['action'] === 'import') {
        // Large imports can hit PHP max execution time; extend for this action.
        @set_time_limit(300);
        $mapping = $_SESSION['import_mapping'] ?? [];
        $rows = import_rows_from_session();
        // Capture the original CSV header order before we clear session state.
        // Used for the "download failed rows" CSV.
        $sourceHeaders = $_SESSION['import_headers'] ?? [];
        $updateExistingOrAdd = !empty($_POST['update_existing_or_add']);
        if (empty($mapping['first_name']) || empty($mapping['last_name']) || empty($rows)) {
            $error = 'Session expired. Please upload your CSV again.';
            $step = 'upload';
        } else {
            $insertMember = $pdo->prepare('
                INSERT INTO members (title, first_name, last_name, email, phone, birthday, notes, date_joined, membership_type_slot, membership_renewal_year, inactive, suspended, life_member, free_membership, gate_key_number, ama_number, ama_expiration, ama_life_member, faa_number, faa_expiration, emergency_contact_name, emergency_contact_relationship, emergency_contact_phone, address_street, address_street2, address_city, address_state, address_postal_code)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ');
            $insertPayment = $pdo->prepare('INSERT INTO payments (member_id, paid_at, year, amount_dues, amount_initiation, amount_late_fee, comp) VALUES (?,?,?,?,?,?,?)');
            // Match tiers delegated to includes/member_match.php
            $paymentExists = $pdo->prepare('
                SELECT 1 FROM payments
                WHERE member_id = ? AND paid_at = ? AND year = ? AND amount_dues = ? AND amount_initiation = ?
                LIMIT 1
            ');
            $added = 0;
            $updated = 0;
            $ambiguous = 0;
            $failures = [];
            foreach ($rows as $i => $row) {
                $first = trim((string) ($row[$mapping['first_name']] ?? ''));
                $last = trim((string) ($row[$mapping['last_name']] ?? ''));
                if ($first === '' || $last === '') {
                    $failures[] = [
                        'row' => $i + 2,
                        'reason' => 'Both first name and last name are required.',
                        'data' => $row,
                    ];
                    continue;
                }
                $email = isset($mapping['email']) ? trim((string) ($row[$mapping['email']] ?? '')) : '';
                $memTypeSlot = isset($mapping['membership_type_slot'])
                    ? normalizeMembershipTypeSlot((string) ($row[$mapping['membership_type_slot']] ?? ''), $enabledLabels)
                    : null;
                $renewalYear = isset($mapping['membership_renewal_year']) ? trim((string) ($row[$mapping['membership_renewal_year']] ?? '')) : null;
                $renewalYear = $renewalYear !== '' && is_numeric($renewalYear) ? (int) $renewalYear : null;
                $dateJoinedRaw = isset($mapping['date_joined']) ? trim((string) ($row[$mapping['date_joined']] ?? '')) : '';
                $dateJoined = $dateJoinedRaw !== '' ? parseDateForDb($dateJoinedRaw) : null;
                $birthdayRaw = isset($mapping['birthday']) ? trim((string) ($row[$mapping['birthday']] ?? '')) : '';
                $birthday = $birthdayRaw !== '' ? parseDateForDb($birthdayRaw) : null;
                $amaExpRaw = isset($mapping['ama_expiration']) ? trim((string) ($row[$mapping['ama_expiration']] ?? '')) : '';
                $amaExp = $amaExpRaw !== '' ? parseDateForDb($amaExpRaw) : null;
                $faaExpRaw = isset($mapping['faa_expiration']) ? trim((string) ($row[$mapping['faa_expiration']] ?? '')) : '';
                $faaExp = $faaExpRaw !== '' ? parseDateForDb($faaExpRaw) : null;
                try {
                    $titleVal = isset($mapping['title']) ? trim((string) ($row[$mapping['title']] ?? '')) : '';
                    $notesVal = isset($mapping['notes']) ? trim((string) ($row[$mapping['notes']] ?? '')) : '';
                    $gateVal = isset($mapping['gate_key_number']) ? trim((string) ($row[$mapping['gate_key_number']] ?? '')) : '';
                    $amaNumVal = isset($mapping['ama_number']) ? trim((string) ($row[$mapping['ama_number']] ?? '')) : '';
                    $faaNumVal = isset($mapping['faa_number']) ? trim((string) ($row[$mapping['faa_number']] ?? '')) : '';
                    if ($amaNumVal !== '') {
                        require_once __DIR__ . '/includes/ama_verify.php';
                        $amaNumVal = ama_verify_normalize_number($amaNumVal);
                    }
                    $suspendedVal = isset($mapping['suspended']) ? (normalizeBool($row[$mapping['suspended']] ?? '') ? 1 : 0) : 0;
                    $amaLifeVal = isset($mapping['ama_life_member']) ? (normalizeBool($row[$mapping['ama_life_member']] ?? '') ? 1 : 0) : 0;

                    $memberId = null;
                    if ($updateExistingOrAdd) {
                        $match = member_match_find(
                            $pdo,
                            $amaNumVal !== '' ? $amaNumVal : null,
                            $first,
                            $last,
                            $email !== '' ? $email : null,
                            $birthday
                        );
                        if ($match['confidence'] === 'ambiguous') {
                            $ambiguous++;
                        } elseif ($match['member_id'] !== null) {
                            $memberId = (int) $match['member_id'];
                            if ($amaNumVal !== '') {
                                $amaConflict = member_find_by_ama_number($pdo, $amaNumVal, $memberId);
                                if ($amaConflict !== null) {
                                    $failures[] = [
                                        'row' => $i + 2,
                                        'reason' => member_ama_number_conflict_message($amaConflict),
                                        'data' => $row,
                                    ];
                                    continue;
                                }
                            }
                            // Update only mapped fields (preserve existing data for unmapped columns)
                            $sets = [];
                            $vals = [];
                            $sets[] = 'first_name = ?'; $vals[] = $first;
                            $sets[] = 'last_name = ?';  $vals[] = $last;
                            if (isset($mapping['title'])) { $sets[] = 'title = ?'; $vals[] = ($titleVal !== '' ? $titleVal : null); }
                            if (isset($mapping['email'])) { $sets[] = 'email = ?'; $vals[] = ($email !== '' ? $email : null); }
                            if (isset($mapping['birthday'])) { $sets[] = 'birthday = ?'; $vals[] = $birthday; }
                            if (isset($mapping['notes'])) { $sets[] = 'notes = ?'; $vals[] = ($notesVal !== '' ? $notesVal : null); }
                            if (isset($mapping['date_joined'])) { $sets[] = 'date_joined = ?'; $vals[] = $dateJoined; }
                            if (isset($mapping['membership_type_slot'])) { $sets[] = 'membership_type_slot = ?'; $vals[] = $memTypeSlot; }
                            if (isset($mapping['membership_renewal_year'])) { $sets[] = 'membership_renewal_year = ?'; $vals[] = $renewalYear; }
                            if (isset($mapping['inactive'])) { $sets[] = 'inactive = ?'; $vals[] = (normalizeBool($row[$mapping['inactive']] ?? '') ? 1 : 0); }
                            if (isset($mapping['suspended'])) { $sets[] = 'suspended = ?'; $vals[] = $suspendedVal; }
                            if (isset($mapping['life_member'])) { $sets[] = 'life_member = ?'; $vals[] = (normalizeBool($row[$mapping['life_member']] ?? '') ? 1 : 0); }
                            if (isset($mapping['free_membership'])) { $sets[] = 'free_membership = ?'; $vals[] = (normalizeBool($row[$mapping['free_membership']] ?? '') ? 1 : 0); }
                            if (isset($mapping['gate_key_number'])) { $sets[] = 'gate_key_number = ?'; $vals[] = ($gateVal !== '' ? $gateVal : null); }
                            if (isset($mapping['ama_number'])) { $sets[] = 'ama_number = ?'; $vals[] = ($amaNumVal !== '' ? $amaNumVal : null); }
                            if (isset($mapping['ama_expiration'])) { $sets[] = 'ama_expiration = ?'; $vals[] = $amaExp; }
                            if (isset($mapping['ama_life_member'])) { $sets[] = 'ama_life_member = ?'; $vals[] = $amaLifeVal; }
                            if (isset($mapping['faa_number'])) { $sets[] = 'faa_number = ?'; $vals[] = ($faaNumVal !== '' ? $faaNumVal : null); }
                            if (isset($mapping['faa_expiration'])) { $sets[] = 'faa_expiration = ?'; $vals[] = $faaExp; }
                            if (isset($mapping['emergency_contact_name'])) { $sets[] = 'emergency_contact_name = ?'; $vals[] = trim((string) ($row[$mapping['emergency_contact_name']] ?? '')) ?: null; }
                            if (isset($mapping['emergency_contact_relationship'])) { $sets[] = 'emergency_contact_relationship = ?'; $vals[] = trim((string) ($row[$mapping['emergency_contact_relationship']] ?? '')) ?: null; }
                            if (isset($mapping['emergency_contact_phone'])) { $sets[] = 'emergency_contact_phone = ?'; $vals[] = trim((string) ($row[$mapping['emergency_contact_phone']] ?? '')) ?: null; }
                            $phoneVal = member_phone_from_import_row($row, $mapping);
                            if ($phoneVal !== null) { $sets[] = 'phone = ?'; $vals[] = $phoneVal; }
                            $addrVal = member_address_from_import_row($row, $mapping);
                            if ($addrVal['street'] !== null || $addrVal['city'] !== null) {
                                $sets[] = 'address_street = ?'; $vals[] = $addrVal['street'];
                                $sets[] = 'address_street2 = ?'; $vals[] = $addrVal['street2'];
                                $sets[] = 'address_city = ?'; $vals[] = $addrVal['city'];
                                $sets[] = 'address_state = ?'; $vals[] = $addrVal['state'];
                                $sets[] = 'address_postal_code = ?'; $vals[] = $addrVal['postal_code'];
                            }

                            $sql = 'UPDATE members SET ' . implode(', ', $sets) . ' WHERE id = ?';
                            $vals[] = $memberId;
                            $pdo->prepare($sql)->execute($vals);
                            $updated++;
                        }
                    }

                    $emergencyNameVal = isset($mapping['emergency_contact_name']) ? trim((string) ($row[$mapping['emergency_contact_name']] ?? '')) : '';
                    $emergencyRelVal  = isset($mapping['emergency_contact_relationship']) ? trim((string) ($row[$mapping['emergency_contact_relationship']] ?? '')) : '';
                    $emergencyPhoneVal = isset($mapping['emergency_contact_phone']) ? trim((string) ($row[$mapping['emergency_contact_phone']] ?? '')) : '';
                    $phoneVal = member_phone_from_import_row($row, $mapping);
                    $addrVal = member_address_from_import_row($row, $mapping);

                    if ($memberId === null) {
                        if ($amaNumVal !== '') {
                            $amaConflict = member_find_by_ama_number($pdo, $amaNumVal, null);
                            if ($amaConflict !== null) {
                                $failures[] = [
                                    'row' => $i + 2,
                                    'reason' => member_ama_number_conflict_message($amaConflict),
                                    'data' => $row,
                                ];
                                continue;
                            }
                        }
                        $insertMember->execute([
                            $titleVal !== '' ? $titleVal : null,
                            $first,
                            $last,
                            $email !== '' ? $email : null,
                            $phoneVal,
                            $birthday,
                            $notesVal !== '' ? $notesVal : null,
                            $dateJoined,
                            $memTypeSlot,
                            $renewalYear,
                            isset($mapping['inactive']) ? (normalizeBool($row[$mapping['inactive']] ?? '') ? 1 : 0) : 0,
                            $suspendedVal,
                            isset($mapping['life_member']) ? (normalizeBool($row[$mapping['life_member']] ?? '') ? 1 : 0) : 0,
                            isset($mapping['free_membership']) ? (normalizeBool($row[$mapping['free_membership']] ?? '') ? 1 : 0) : 0,
                            $gateVal !== '' ? $gateVal : null,
                            $amaNumVal !== '' ? $amaNumVal : null,
                            $amaExp,
                            $amaLifeVal,
                            $faaNumVal !== '' ? $faaNumVal : null,
                            $faaExp,
                            $emergencyNameVal !== '' ? $emergencyNameVal : null,
                            $emergencyRelVal !== '' ? $emergencyRelVal : null,
                            $emergencyPhoneVal !== '' ? $emergencyPhoneVal : null,
                            $addrVal['street'],
                            $addrVal['street2'],
                            $addrVal['city'],
                            $addrVal['state'],
                            $addrVal['postal_code'],
                        ]);
                        $memberId = (int) $pdo->lastInsertId();
                        $added++;
                    }
                    if (isset($mapping['payment_date'], $mapping['payment_year']) && trim((string) ($row[$mapping['payment_date']] ?? '')) !== '' && trim((string) ($row[$mapping['payment_year']] ?? '')) !== '') {
                        $payDate = parseDateForDb(trim((string) $row[$mapping['payment_date']]));
                        if ($payDate !== null) {
                            $payYear = (int) $row[$mapping['payment_year']];
                            $dues = isset($mapping['amount_dues']) ? (float) ($row[$mapping['amount_dues']] ?? 0) : 0;
                            $init = isset($mapping['amount_initiation']) ? (float) ($row[$mapping['amount_initiation']] ?? 0) : 0;
                            if ($updateExistingOrAdd) {
                                $paymentExists->execute([$memberId, $payDate, $payYear, $dues, $init]);
                                if (!$paymentExists->fetch()) {
                                    $insertPayment->execute([$memberId, $payDate, $payYear, $dues, $init, 0, 0]);
                                    recordMemberMembershipYear($pdo, $memberId, $payYear, 'import');
                                }
                            } else {
                                $insertPayment->execute([$memberId, $payDate, $payYear, $dues, $init, 0, 0]);
                                recordMemberMembershipYear($pdo, $memberId, $payYear, 'import');
                            }
                        }
                    }
                } catch (Throwable $e) {
                    $failures[] = ['row' => $i + 2, 'reason' => $e->getMessage(), 'data' => $row];
                }
            }
            if (count($failures) > 0) {
                $_SESSION['import_failures']       = $failures;
                // Prefer the original CSV headers (preserves column order).
                // Fallback to keys from the first failure row if needed.
                if (is_array($sourceHeaders) && count($sourceHeaders) > 0) {
                    $_SESSION['import_failures_headers'] = $sourceHeaders;
                } else {
                    $firstFailureData = $failures[0]['data'] ?? [];
                    $_SESSION['import_failures_headers'] = is_array($firstFailureData) ? array_keys($firstFailureData) : [];
                }
            }
            import_clear_row_blob();
            unset($_SESSION['import_headers'], $_SESSION['import_rows'], $_SESSION['import_mapping']);
            $message = "Added $added member(s).";
            if ($updated > 0) $message .= " Updated $updated existing member(s).";
            if ($ambiguous > 0) $message .= " $ambiguous row(s) matched multiple existing members; added new records.";
            if (count($failures) > 0) {
                $message .= ' ' . count($failures) . ' row(s) failed.';
            }
            $step = 'upload';
        }
    }
}

$headers = $_SESSION['import_headers'] ?? [];
$rows = import_rows_from_session();
$mapping = $_SESSION['import_mapping'] ?? [];

// Count rows that have at least first or last name (what we actually attempt to import)
$importableCount = 0;
if (count($mapping) > 0 && count($rows) > 0 && !empty($mapping['first_name']) && !empty($mapping['last_name'])) {
    foreach ($rows as $row) {
        $first = trim((string) ($row[$mapping['first_name']] ?? ''));
        $last = trim((string) ($row[$mapping['last_name']] ?? ''));
        if ($first !== '' && $last !== '') $importableCount++;
    }
}

// Download sample CSV (no HTML/deprecation output in file)
if (isset($_GET['download']) && $_GET['download'] === 'sample') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    $prevError = error_reporting(E_ALL & ~E_DEPRECATED);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="import_members_sample.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    $sampleHeaders = ['first_name', 'last_name', 'email', 'title', 'birthday', 'notes', 'date_joined', 'membership_type_slot', 'membership_renewal_year', 'Member Inactive', 'Member Suspended', 'Life Member', 'Free Membership', 'AMA Life Member', 'gate_key_number', 'ama_number', 'AMA Expiry', 'FAA Number', 'FAA Expiry', 'Emergency contact name', 'Emergency contact relationship', 'Emergency contact phone', 'Phone', 'street', 'street2', 'city', 'state', 'postal_code', 'payment_year', 'payment_date', 'amount_dues', 'amount_initiation'];
    $sampleRow = ['Jane', 'Doe', 'jane@example.com', 'Ms', '1990-05-15', 'Sample note', '2020-03-15', 'Adult', '2025', '0', '0', '0', '0', '0', 'G-01', '123456', '2026-12-31', '123456789', '2026-06-30', 'John Doe', 'Spouse', '555-987-6543', '555-123-4567', '123 Main St', 'Apt 4', 'Anytown', 'CA', '90210', '2025', '2025-01-10', '50.00', '25.00'];
    fputcsv($out, $sampleHeaders, ',', '"', '\\');
    fputcsv($out, $sampleRow, ',', '"', '\\');
    fclose($out);
    error_reporting($prevError);
    exit;
}

// Download failed rows CSV (reason column added)
if (isset($_GET['download']) && $_GET['download'] === 'failed') {
    $failures = $_SESSION['import_failures'] ?? [];
    $headers  = $_SESSION['import_failures_headers'] ?? [];
    // If headers weren't stored for some reason, derive them from the failure payload.
    if (empty($headers) && !empty($failures) && is_array($failures[0]['data'] ?? null)) {
        $headers = array_keys($failures[0]['data']);
    }
    if (empty($failures) || empty($headers)) {
        header('Location: import.php');
        exit;
    }
    while (ob_get_level()) {
        ob_end_clean();
    }
    $prevError = error_reporting(E_ALL & ~E_DEPRECATED);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="import_failed_rows.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, array_merge($headers, ['reason']), ',', '"', '\\');
    foreach ($failures as $f) {
        $row = $f['data'];
        $ordered = [];
        foreach ($headers as $h) {
            $ordered[] = $row[$h] ?? '';
        }
        $ordered[] = $f['reason'];
        fputcsv($out, $ordered, ',', '"', '\\');
    }
    fclose($out);
    unset($_SESSION['import_failures'], $_SESSION['import_failures_headers']);
    error_reporting($prevError);
    exit;
}

$pageTitle = 'Import members';
$breadcrumbs = [
    ['label' => 'Members', 'url' => 'members.php'],
    ['label' => 'Import', 'url' => ''],
];
require_once __DIR__ . '/includes/page_header.php';
require_once __DIR__ . '/includes/header.php';

render_page_header([
    'title' => 'Import members',
    'subtitle' => 'Upload a CSV file, map columns to member fields, then import.',
]);
?>
<p class="text-muted small mb-3"><a href="import.php?download=sample">Download sample CSV</a></p>

<?php if ($message): ?>
<div class="alert alert-success">
    <?= htmlspecialchars($message) ?>
    <?php if (!empty($_SESSION['import_failures'])): ?>
        <a href="import.php?download=failed" class="alert-link">Download failed rows CSV</a>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($step === 'upload'): ?>
<form method="post" action="import.php" enctype="multipart/form-data" class="card mb-3">
    <?= csrf_field() ?>
    <div class="card-body">
        <input type="hidden" name="action" value="upload">
        <div class="mb-3">
            <label class="form-label">CSV file</label>
            <input type="file" name="csv" class="form-control" accept=".csv,text/csv,text/plain" required>
            <small class="text-muted">Use comma or semicolon. UTF-8 with optional BOM is fine. Max 2 MB.
                Imports over <?= (int) IMPORT_ROWS_SESSION_THRESHOLD ?> rows are staged in a server temp file so the session is not overloaded.</small>
        </div>
        <button type="submit" class="btn btn-primary">Upload and map columns</button>
    </div>
</form>
<p class="small text-muted">After upload you will choose which CSV column maps to each field (e.g. First name, Last name, Email). You can also map optional payment columns to add one payment per row.</p>
<?php endif; ?>

<?php if ($step === 'map' && count($headers) > 0): ?>
<form method="post" action="import.php?step=map">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="map">
    <div class="card mb-3">
        <div class="card-header">Map CSV columns to fields</div>
        <div class="card-body">
            <p class="small text-muted">Your CSV has <?= count($rows) ?> row(s). Select the column for each field you want to import. Rows with both first and last name empty are skipped. Leave "Don't map" for fields not in your file.</p>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>Field</th><th>CSV column</th></tr></thead>
                    <tbody>
                        <?php foreach ($importFields as $field => $opts): ?>
                        <tr>
                            <td><?= htmlspecialchars($opts['label']) ?><?= $opts['required'] ? ' <span class="text-danger">*</span>' : '' ?></td>
                            <td>
                                <select name="map_<?= htmlspecialchars($field) ?>" class="form-select form-select-sm">
                                    <option value="">Don't map</option>
                                    <?php foreach ($headers as $h): ?>
                                        <option value="<?= htmlspecialchars($h) ?>" <?= (($mapping[$field] ?? '') === $h) ? 'selected' : '' ?>><?= htmlspecialchars($h) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <button type="submit" class="btn btn-primary">Save mapping and preview</button>
</form>
<?php endif; ?>

<?php if ($step === 'preview' && count($mapping) > 0 && count($rows) > 0): ?>
<div class="card mb-3">
    <div class="card-header">Preview (first 20 rows)</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
                <thead class="table-light">
                    <tr>
                        <th>First name</th>
                        <th>Last name</th>
                        <th>Email</th>
                        <th>Type</th>
                        <th>Renewal year</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $previewFirst = $mapping['first_name'] ?? '';
                    $previewLast = $mapping['last_name'] ?? '';
                    $previewEmail = isset($mapping['email']) && $mapping['email'] !== '' ? $mapping['email'] : null;
                    $previewType = isset($mapping['membership_type_slot']) && $mapping['membership_type_slot'] !== '' ? $mapping['membership_type_slot'] : null;
                    $previewYear = isset($mapping['membership_renewal_year']) && $mapping['membership_renewal_year'] !== '' ? $mapping['membership_renewal_year'] : null;
                    foreach (array_slice($rows, 0, 20) as $row):
                    ?>
                    <tr>
                        <td><?= htmlspecialchars(trim((string) ($row[$previewFirst] ?? ''))) ?></td>
                        <td><?= htmlspecialchars(trim((string) ($row[$previewLast] ?? ''))) ?></td>
                        <td><?= htmlspecialchars(trim((string) ($previewEmail !== null ? ($row[$previewEmail] ?? '') : ''))) ?></td>
                        <td><?= htmlspecialchars(trim((string) ($previewType !== null ? ($row[$previewType] ?? '') : ''))) ?></td>
                        <td><?= htmlspecialchars(trim((string) ($previewYear !== null ? ($row[$previewYear] ?? '') : ''))) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<form method="post" action="import.php?step=import">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="import">
    <?php if ($importableCount < count($rows)): ?>
    <p class="small text-muted mb-2"><?= count($rows) ?> row(s) in file; <?= $importableCount ?> have at least first or last name and will be imported. Empty-name rows are skipped.</p>
    <?php endif; ?>
    <div class="mb-3">
        <label class="form-check">
            <input type="checkbox" name="update_existing_or_add" value="1" class="form-check-input" checked>
            <span class="form-check-label">When checked: update existing members if a unique match is found — tries <strong>first + last + email + birthday</strong> (all mapped and present), then <strong>first + last + email</strong>, then <strong>first + last</strong> only when exactly one member has that name. If unchecked: always add as new.</span>
        </label>
    </div>
    <button type="submit" class="btn btn-primary">Import <?= $importableCount ?> member(s)</button>
    <a href="import.php" class="btn btn-outline-secondary">Cancel</a>
</form>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
