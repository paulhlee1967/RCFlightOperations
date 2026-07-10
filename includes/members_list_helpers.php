<?php
/**
 * includes/members_list_helpers.php
 *
 * Display helpers and URL builder for members.php.
 */

declare(strict_types=1);

/**
 * Build a members.php URL preserving current active params, with overrides.
 */
function membersUrl(array $params, ?int $pg = null): string
{
    $p = $params;
    if ($pg !== null) {
        $p['page'] = $pg;
    }

    if (isset($p['flag'])) {
        $flags = is_array($p['flag']) ? array_values($p['flag']) : [(string) $p['flag']];
        $flags = array_values(array_filter(array_map('strval', $flags), static fn (string $f) => $f !== ''));
        if ($flags === []) {
            unset($p['flag']);
        } else {
            $p['flag'] = $flags;
        }
    }

    return 'members.php' . (count($p) > 0 ? '?' . http_build_query($p) : '');
}

/**
 * Build query params with a flag toggled on or off.
 *
 * @param  list<string>  $activeFlags
 * @return array<string, mixed>
 */
function members_list_toggle_flag_params(array $queryParams, array $activeFlags, string $flag): array
{
    $params = $queryParams;
    unset($params['page']);

    $flags = $activeFlags;
    if (in_array($flag, $flags, true)) {
        $flags = array_values(array_filter($flags, static fn (string $f) => $f !== $flag));
    } else {
        $flags[] = $flag;
    }
    sort($flags);

    if ($flags === []) {
        unset($params['flag']);
    } else {
        $params['flag'] = $flags;
    }

    return $params;
}

/** Return CSS initials-avatar background color deterministically from a name. */
function members_initials_color(string $name): string
{
    $palette = ['#5b7fa6', '#6b8f6b', '#9b6b6b', '#7b6b9b', '#9b8b5b', '#5b9b8b', '#9b6b8b', '#6b7b9b'];

    return $palette[abs(crc32($name)) % count($palette)];
}

/** Bootstrap badge HTML for membership type slot. */
function members_type_badge(?int $slot, array $labels): string
{
    $slot = (int) ($slot ?? 0);
    $map  = [
        1 => ['bg-primary', $labels[1] ?? 'Type 1'],
        2 => ['bg-info', $labels[2] ?? 'Type 2'],
        3 => ['bg-success', $labels[3] ?? 'Type 3'],
        4 => ['bg-secondary', $labels[4] ?? 'Type 4'],
    ];
    [$cls, $label] = $map[$slot] ?? ['bg-light text-dark', '—'];

    return '<span class="badge ' . $cls . ' member-type-badge">' . h($label) . '</span>';
}

/** Colored badge for renewal year. */
function members_year_badge(mixed $year, int $currentYear): string
{
    $y = (int) $year;
    if ($y <= 0) {
        return '<span class="badge bg-light text-muted border">—</span>';
    }
    $cls = match (true) {
        $y >= $currentYear      => 'badge-year-current',
        $y === $currentYear - 1 => 'badge-year-due',
        default                 => 'badge-year-lapsed',
    };

    return '<span class="badge ' . $cls . '">' . $y . '</span>';
}
