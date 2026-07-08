<?php
/**
 * includes/member_save.php
 *
 * Shared member create/update from POST (member_edit, member_wizard, future API).
 */

require_once __DIR__ . '/validation.php';

/** @return array<string, string> */
function member_photo_allowed_mimes(): array
{
    return ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];
}

/** @return array<string, string> */
function member_faa_card_allowed_mimes(): array
{
    // FAA registration “card” is commonly uploaded as PDF or an image.
    return [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
    ];
}

/**
 * When Automator sends multiple file URLs, use the first non-empty value.
 */
function member_photo_pick_first_url(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (!str_contains($raw, ',')) {
        return $raw;
    }
    foreach (explode(',', $raw) as $part) {
        $part = trim($part);
        if ($part !== '') {
            return $part;
        }
    }

    return '';
}

/**
 * @return list<string>
 */
function member_photo_import_allowed_hosts(): array
{
    static $hosts = null;
    if ($hosts !== null) {
        return $hosts;
    }

    $hosts = ['pvmac.com', 'www.pvmac.com'];
    $configPath = dirname(__DIR__) . '/config.php';
    if (is_file($configPath)) {
        $config = require $configPath;
        if (!empty($config['wpforms_media_hosts']) && is_array($config['wpforms_media_hosts'])) {
            $hosts = [];
            foreach ($config['wpforms_media_hosts'] as $host) {
                $host = strtolower(trim((string) $host));
                if ($host !== '') {
                    $hosts[] = $host;
                }
            }
            if ($hosts === []) {
                $hosts = ['pvmac.com', 'www.pvmac.com'];
            }
        }
    }

    return $hosts;
}

function member_photo_url_is_allowed(string $url, ?array $allowedHosts = null): bool
{
    $url = member_photo_pick_first_url($url);
    if ($url === '') {
        return false;
    }

    $parts = parse_url($url);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }
    if (!in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
        return false;
    }

    $host = strtolower((string) $parts['host']);
    $allowed = $allowedHosts ?? member_photo_import_allowed_hosts();
    foreach ($allowed as $allowedHost) {
        $allowedHost = strtolower(trim($allowedHost));
        if ($allowedHost === '') {
            continue;
        }
        if ($host === $allowedHost || str_ends_with($host, '.' . $allowedHost)) {
            return true;
        }
    }

    return false;
}

/**
 * Validate a local image file and persist it as the member photo.
 *
 * @return array{ok:bool, error:?string, photo_path:?string}
 */
function member_save_photo_from_local_file(PDO $pdo, int $memberId, string $localPath, int $maxBytes = 5242880): array
{
    if ($memberId <= 0 || !is_file($localPath) || !is_readable($localPath)) {
        return ['ok' => false, 'error' => 'Badge photo file is not readable.', 'photo_path' => null];
    }

    $size = filesize($localPath);
    if ($size === false || $size > $maxBytes) {
        return ['ok' => false, 'error' => 'Badge photo exceeds the 5 MB size limit.', 'photo_path' => null];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($localPath);
    $allowed = member_photo_allowed_mimes();
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Badge photo must be a JPEG, PNG, or GIF image.', 'photo_path' => null];
    }

    $dir = dirname(__DIR__) . '/uploads/member_photos';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Could not create member photo directory.', 'photo_path' => null];
    }

    $ext = $allowed[$mime];
    $filename = $memberId . '.' . $ext;
    $dest = $dir . '/' . $filename;
    if (!copy($localPath, $dest)) {
        return ['ok' => false, 'error' => 'Could not save badge photo.', 'photo_path' => null];
    }

    $photoPath = 'uploads/member_photos/' . $filename;
    $pdo->prepare('UPDATE members SET photo_path = ? WHERE id = ?')->execute([$photoPath, $memberId]);

    return ['ok' => true, 'error' => null, 'photo_path' => $photoPath];
}

/**
 * Download a WPForms media URL to a temp file (host allowlist, size cap).
 *
 * @return array{ok:bool, error:?string, local_path:?string}
 */
function member_download_media_url_to_temp_file(
    string $url,
    int $memberId,
    string $logContext,
    int $maxBytes = 5242880
): array {
    $url = member_photo_pick_first_url($url);
    if ($url === '') {
        return ['ok' => false, 'error' => 'No file URL provided.', 'local_path' => null];
    }
    if (!member_photo_url_is_allowed($url)) {
        return ['ok' => false, 'error' => 'File URL is not from an allowed host.', 'local_path' => null];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'Server cannot download files from the website (cURL unavailable).', 'local_path' => null];
    }

    $tmp = tempnam(sys_get_temp_dir(), 'member_media_');
    if ($tmp === false) {
        return ['ok' => false, 'error' => 'Could not create temporary file.', 'local_path' => null];
    }

    $fp = fopen($tmp, 'wb');
    if ($fp === false) {
        @unlink($tmp);

        return ['ok' => false, 'error' => 'Could not create temporary file.', 'local_path' => null];
    }

    $bytes = 0;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_MAXREDIRS       => 5,
        CURLOPT_TIMEOUT         => 30,
        CURLOPT_FAILONERROR     => true,
        CURLOPT_WRITEFUNCTION   => static function ($curl, string $data) use ($fp, &$bytes, $maxBytes) {
            $len = strlen($data);
            if ($bytes + $len > $maxBytes) {
                return 0;
            }
            $written = fwrite($fp, $data);
            if ($written === false) {
                return 0;
            }
            $bytes += $written;

            return $len;
        },
    ]);

    $ok = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if (!$ok || $httpCode < 200 || $httpCode >= 300) {
        @unlink($tmp);
        error_log($logContext . ': download failed for member ' . $memberId . ': HTTP ' . $httpCode . ' ' . $curlError);

        return ['ok' => false, 'error' => 'Could not download file from the website.', 'local_path' => null];
    }

    return ['ok' => true, 'error' => null, 'local_path' => $tmp];
}

/**
 * Download a WPForms badge photo URL and save it on the member record.
 *
 * @return array{ok:bool, error:?string, photo_path:?string}
 */
function member_import_photo_from_url(PDO $pdo, int $memberId, string $url): array
{
    $download = member_download_media_url_to_temp_file($url, $memberId, 'member_import_photo_from_url');
    if (!$download['ok']) {
        $error = $download['error'] ?? 'Could not download badge photo from the website.';
        if ($error === 'No file URL provided.') {
            $error = 'No badge photo URL provided.';
        } elseif ($error === 'File URL is not from an allowed host.') {
            $error = 'Badge photo URL is not from an allowed host.';
        } elseif ($error === 'Server cannot download files from the website (cURL unavailable).') {
            $error = 'Server cannot download badge photos (cURL unavailable).';
        } elseif ($error === 'Could not download file from the website.') {
            $error = 'Could not download badge photo from the website.';
        }

        return ['ok' => false, 'error' => $error, 'photo_path' => null];
    }

    $result = member_save_photo_from_local_file($pdo, $memberId, (string) $download['local_path']);
    @unlink((string) $download['local_path']);

    return $result;
}

/**
 * Validate an uploaded FAA card file and persist it as a member attachment.
 *
 * @return array{ok:bool, error:?string, faa_card_path:?string}
 */
function member_save_faa_card_from_local_file(PDO $pdo, int $memberId, string $localPath, int $maxBytes = 5242880): array
{
    if ($memberId <= 0 || !is_file($localPath) || !is_readable($localPath)) {
        return ['ok' => false, 'error' => 'FAA card file is not readable.', 'faa_card_path' => null];
    }

    $size = filesize($localPath);
    if ($size === false || $size > $maxBytes) {
        return ['ok' => false, 'error' => 'FAA card exceeds the 5 MB size limit.', 'faa_card_path' => null];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($localPath);
    $allowed = member_faa_card_allowed_mimes();
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'FAA card must be a PDF, JPG, or PNG file.', 'faa_card_path' => null];
    }

    $dir = dirname(__DIR__) . '/uploads/member_faa_cards';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Could not create FAA card directory.', 'faa_card_path' => null];
    }

    $ext = $allowed[$mime];
    $filename = $memberId . '.' . $ext;
    $dest = $dir . '/' . $filename;
    if (!copy($localPath, $dest)) {
        return ['ok' => false, 'error' => 'Could not save FAA card.', 'faa_card_path' => null];
    }

    $path = 'uploads/member_faa_cards/' . $filename;
    $pdo->prepare('UPDATE members SET faa_card_path = ? WHERE id = ?')->execute([$path, $memberId]);

    return ['ok' => true, 'error' => null, 'faa_card_path' => $path];
}

/**
 * Download a WPForms FAA registration URL and save it on the member record.
 *
 * @return array{ok:bool, error:?string, faa_card_path:?string}
 */
function member_import_faa_card_from_url(PDO $pdo, int $memberId, string $url): array
{
    $download = member_download_media_url_to_temp_file($url, $memberId, 'member_import_faa_card_from_url');
    if (!$download['ok']) {
        $error = $download['error'] ?? 'Could not download FAA card from the website.';
        if ($error === 'No file URL provided.') {
            $error = 'No FAA card URL provided.';
        } elseif ($error === 'File URL is not from an allowed host.') {
            $error = 'FAA card URL is not from an allowed host.';
        } elseif ($error === 'Server cannot download files from the website (cURL unavailable).') {
            $error = 'Server cannot download FAA cards (cURL unavailable).';
        } elseif ($error === 'Could not download file from the website.') {
            $error = 'Could not download FAA card from the website.';
        }

        return ['ok' => false, 'error' => $error, 'faa_card_path' => null];
    }

    $result = member_save_faa_card_from_local_file($pdo, $memberId, (string) $download['local_path']);
    @unlink((string) $download['local_path']);

    return $result;
}

/**
 * Validate POST, check AMA uniqueness, persist member + optional photo.
 *
 * @return array{ok:bool, member_id:?int, errors:array<string,string>}
 */
function save_member_from_post(PDO $pdo, ?int $memberId, array $post, array $files = []): array
{
    [$valErrors, $c] = validate_member_input($post);
    if ($valErrors !== []) {
        return ['ok' => false, 'member_id' => $memberId, 'errors' => $valErrors];
    }

    $amaConflict = member_find_by_ama_number($pdo, $c['ama_number'], $memberId ?: null);
    if ($amaConflict !== null) {
        return [
            'ok'        => false,
            'member_id' => $memberId,
            'errors'    => ['ama_number' => member_ama_number_conflict_message($amaConflict)],
        ];
    }

    $title          = $c['title'];
    $firstName      = $c['first_name'];
    $lastName       = $c['last_name'];
    $email          = $c['email'];
    $birthday       = $c['birthday'];
    $notes          = $c['notes'];
    $dateJoined     = $c['date_joined'];
    $memSlot        = $c['membership_type_slot'];
    $renewalYear    = $c['membership_renewal_year'];
    $inactive       = $c['inactive'];
    $suspended      = $c['suspended'];
    $lifeMember     = $c['life_member'];
    $freeMembership = $c['free_membership'];
    $gateKey        = $c['gate_key_number'];
    $amaNumber      = $c['ama_number'];
    $amaExp         = $c['ama_expiration'];
    $amaLife        = $c['ama_life_member'];
    $faaNumber      = $c['faa_number'];
    $faaExp         = $c['faa_expiration'];
    $emergencyName  = $c['emergency_contact_name'];
    $emergencyRel   = $c['emergency_contact_relationship'];
    $emergencyPhone = $c['emergency_contact_phone'];
    $phone          = $c['phone'];
    $addressStreet  = $c['address_street'];
    $addressStreet2 = $c['address_street2'];
    $addressCity    = $c['address_city'];
    $addressState   = $c['address_state'];
    $addressPostal  = $c['address_postal_code'];

    if ($memberId) {
        $stmt = $pdo->prepare('UPDATE members SET title=?, first_name=?, last_name=?, email=?, phone=?, birthday=?, notes=?, date_joined=?, membership_type_slot=?, membership_renewal_year=?, inactive=?, suspended=?, life_member=?, free_membership=?, gate_key_number=?, ama_number=?, ama_expiration=?, ama_life_member=?, faa_number=?, faa_expiration=?, emergency_contact_name=?, emergency_contact_relationship=?, emergency_contact_phone=?, address_street=?, address_street2=?, address_city=?, address_state=?, address_postal_code=? WHERE id=?');
        $stmt->execute([$title, $firstName, $lastName, $email, $phone, $birthday, $notes, $dateJoined, $memSlot, $renewalYear, $inactive, $suspended, $lifeMember, $freeMembership, $gateKey, $amaNumber, $amaExp, $amaLife, $faaNumber, $faaExp, $emergencyName, $emergencyRel, $emergencyPhone, $addressStreet, $addressStreet2, $addressCity, $addressState, $addressPostal, $memberId]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO members (title, first_name, last_name, email, phone, birthday, notes, date_joined, membership_type_slot, membership_renewal_year, inactive, suspended, life_member, free_membership, gate_key_number, ama_number, ama_expiration, ama_life_member, faa_number, faa_expiration, emergency_contact_name, emergency_contact_relationship, emergency_contact_phone, address_street, address_street2, address_city, address_state, address_postal_code) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$title, $firstName, $lastName, $email, $phone, $birthday, $notes, $dateJoined, $memSlot, $renewalYear, $inactive, $suspended, $lifeMember, $freeMembership, $gateKey, $amaNumber, $amaExp, $amaLife, $faaNumber, $faaExp, $emergencyName, $emergencyRel, $emergencyPhone, $addressStreet, $addressStreet2, $addressCity, $addressState, $addressPostal]);
        $memberId = (int) $pdo->lastInsertId();
    }

    if ($memberId) {
        syncMemberMembershipYearForMember($pdo, $memberId);
    }

    if ($memberId) {
        if (!empty($files['photo']['tmp_name']) && is_uploaded_file($files['photo']['tmp_name'])) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($files['photo']['tmp_name']);
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];
            if (isset($allowed[$mime]) && $files['photo']['size'] <= 5 * 1024 * 1024) {
                $dir = dirname(__DIR__) . '/uploads/member_photos';
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $ext = $allowed[$mime];
                $filename = $memberId . '.' . $ext;
                $path = $dir . '/' . $filename;
                if (move_uploaded_file($files['photo']['tmp_name'], $path)) {
                    $photoPath = 'uploads/member_photos/' . $filename;
                    $pdo->prepare('UPDATE members SET photo_path = ? WHERE id = ?')->execute([$photoPath, $memberId]);
                }
            }
        }
    }

    if ($memberId) {
        if (!empty($files['faa_card']['tmp_name']) && is_uploaded_file($files['faa_card']['tmp_name'])) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($files['faa_card']['tmp_name']);
            $allowed = member_faa_card_allowed_mimes();
            if (isset($allowed[$mime]) && (int) $files['faa_card']['size'] <= 5 * 1024 * 1024) {
                $dir = dirname(__DIR__) . '/uploads/member_faa_cards';
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $ext = $allowed[$mime];
                $filename = $memberId . '.' . $ext;
                $path = $dir . '/' . $filename;
                if (move_uploaded_file($files['faa_card']['tmp_name'], $path)) {
                    $faaPath = 'uploads/member_faa_cards/' . $filename;
                    $pdo->prepare('UPDATE members SET faa_card_path = ? WHERE id = ?')->execute([$faaPath, $memberId]);
                }
            }
        }
    }

    return ['ok' => true, 'member_id' => $memberId, 'errors' => []];
}
