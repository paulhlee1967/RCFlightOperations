<?php
/**
 * Helpers for AMA/FAA compliance fields and FAA card attachments.
 */

/**
 * Resolve a readable local path for a member's FAA card, if present.
 */
function member_faa_card_local_path(array $member, ?string $baseDir = null): ?string
{
    $relative = ltrim((string) ($member['faa_card_path'] ?? ''), '/');
    if ($relative === '' || str_contains($relative, '..')) {
        return null;
    }

    $baseDir = $baseDir ?? dirname(__DIR__);
    $full = $baseDir . '/' . $relative;
    if (!is_file($full) || !is_readable($full)) {
        return null;
    }

    return $full;
}

function member_faa_card_has_file(array $member, ?string $baseDir = null): bool
{
    return member_faa_card_local_path($member, $baseDir) !== null;
}

function member_faa_card_extension(array $member): string
{
    $path = member_faa_card_local_path($member);
    if ($path === null) {
        return '';
    }

    return strtolower(pathinfo($path, PATHINFO_EXTENSION));
}

function member_faa_card_is_image(array $member): bool
{
    return in_array(member_faa_card_extension($member), ['jpg', 'jpeg', 'png'], true);
}

function member_faa_card_is_pdf(array $member): bool
{
    return member_faa_card_extension($member) === 'pdf';
}

function member_faa_card_serve_url(int $memberId): string
{
    return 'faa_card.php?id=' . $memberId;
}
