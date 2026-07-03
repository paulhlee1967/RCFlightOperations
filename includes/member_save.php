<?php
/**
 * includes/member_save.php
 *
 * Shared member create/update from POST (member_edit, member_wizard, future API).
 */

require_once __DIR__ . '/validation.php';

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

    return ['ok' => true, 'member_id' => $memberId, 'errors' => []];
}
