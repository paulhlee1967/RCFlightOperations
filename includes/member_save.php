<?php
/**
 * includes/member_save.php
 *
 * Shared member create/update from POST (member_edit, member_wizard, future API).
 */

require_once __DIR__ . '/validation.php';

/**
 * Validate POST, check AMA uniqueness, persist member + phones + addresses + optional photo.
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
    $allowEmail     = $c['allow_email'];
    $allowPostal    = $c['allow_postal'];

    if ($memberId) {
        $stmt = $pdo->prepare('UPDATE members SET title=?, first_name=?, last_name=?, email=?, birthday=?, notes=?, date_joined=?, membership_type_slot=?, membership_renewal_year=?, inactive=?, suspended=?, life_member=?, free_membership=?, gate_key_number=?, ama_number=?, ama_expiration=?, ama_life_member=?, faa_number=?, faa_expiration=?, emergency_contact_name=?, emergency_contact_relationship=?, emergency_contact_phone=?, allow_email=?, allow_postal=? WHERE id=?');
        $stmt->execute([$title, $firstName, $lastName, $email, $birthday, $notes, $dateJoined, $memSlot, $renewalYear, $inactive, $suspended, $lifeMember, $freeMembership, $gateKey, $amaNumber, $amaExp, $amaLife, $faaNumber, $faaExp, $emergencyName, $emergencyRel, $emergencyPhone, $allowEmail, $allowPostal, $memberId]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO members (title, first_name, last_name, email, birthday, notes, date_joined, membership_type_slot, membership_renewal_year, inactive, suspended, life_member, free_membership, gate_key_number, ama_number, ama_expiration, ama_life_member, faa_number, faa_expiration, emergency_contact_name, emergency_contact_relationship, emergency_contact_phone, allow_email, allow_postal) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$title, $firstName, $lastName, $email, $birthday, $notes, $dateJoined, $memSlot, $renewalYear, $inactive, $suspended, $lifeMember, $freeMembership, $gateKey, $amaNumber, $amaExp, $amaLife, $faaNumber, $faaExp, $emergencyName, $emergencyRel, $emergencyPhone, $allowEmail, $allowPostal]);
        $memberId = (int) $pdo->lastInsertId();
    }

    if ($memberId) {
        syncMemberMembershipYearForMember($pdo, $memberId);
    }

    if ($memberId) {
        $pdo->prepare('DELETE FROM member_phones WHERE member_id = ?')->execute([$memberId]);
        $pdo->prepare('DELETE FROM member_addresses WHERE member_id = ?')->execute([$memberId]);
        $phoneIns = $pdo->prepare('INSERT INTO member_phones (member_id, type, number) VALUES (?,?,?)');
        $addrIns  = $pdo->prepare('INSERT INTO member_addresses (member_id, type, street, street2, city, state, postal_code) VALUES (?,?,?,?,?,?,?)');
        if (!empty($post['phones']) && is_array($post['phones'])) {
            foreach ($post['phones'] as $p) {
                $type = in_array($p['type'] ?? '', ['Home', 'Work', 'Cell', 'Other'], true) ? $p['type'] : 'Home';
                $num  = trim($p['number'] ?? '');
                if ($num !== '') {
                    $phoneIns->execute([$memberId, $type, $num]);
                }
            }
        }
        if (!empty($post['addresses']) && is_array($post['addresses'])) {
            foreach ($post['addresses'] as $a) {
                $type = in_array($a['type'] ?? '', ['Home', 'Work', 'Other'], true) ? $a['type'] : 'Home';
                $street  = trim($a['street'] ?? '');
                $street2 = trim($a['street2'] ?? '');
                $city    = trim($a['city'] ?? '');
                $state   = trim($a['state'] ?? '');
                $postal  = trim($a['postal_code'] ?? '');
                if ($street !== '' || $street2 !== '' || $city !== '' || $postal !== '') {
                    $addrIns->execute([$memberId, $type, $street ?: null, $street2 ?: null, $city ?: null, $state ?: null, $postal ?: null]);
                }
            }
        }

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

    return ['ok' => true, 'member_id' => $memberId, 'errors' => []];
}
