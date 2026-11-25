<?php
declare(strict_types=1);

if (!defined('OFFGRID_APP')) {
    http_response_code(404);
    exit;
}

/**
 * The group directory: local Meshtastic and MeshCore groups in the U.S., read from includes/groups.json.
 *
 * The list was compiled by an automated search. Nobody here has checked these groups or their pages, and the site does not
 * endorse any of them. Every outside link opens in a new tab and is marked rel="nofollow", so it is not a recommendation.
 * Regenerate the data with tests/tools/import-groups.py.
 */

const GROUP_PROTOCOLS = ['meshtastic'=>'Meshtastic', 'meshcore'=>'MeshCore', 'both'=>'Meshtastic + MeshCore'];

/** The caution shown on every directory page. One wording, used everywhere. */
const GROUPS_DISCLAIMER = 'These groups were found by an automated search. We have not personally checked them or their websites, Discord servers or Facebook pages. '
    . 'We are not responsible for anything on those pages, and we do not endorse any group. Links can be out of date and invites can expire, '
    . 'so use your own judgment and follow each group\'s own rules for joining.';

/** Tile map layout: code => [row, column]. A tile map shows every state at the same size, so small states stay clickable. */
const GROUP_TILES = [
    'AK'=>[0, 0], 'ME'=>[0, 10],
    'VT'=>[1, 9], 'NH'=>[1, 10],
    'WA'=>[2, 1], 'ID'=>[2, 2], 'MT'=>[2, 3], 'ND'=>[2, 4], 'MN'=>[2, 5], 'IL'=>[2, 6], 'WI'=>[2, 7], 'MI'=>[2, 8], 'NY'=>[2, 9], 'RI'=>[2, 10], 'MA'=>[2, 11],
    'OR'=>[3, 1], 'NV'=>[3, 2], 'WY'=>[3, 3], 'SD'=>[3, 4], 'IA'=>[3, 5], 'IN'=>[3, 6], 'OH'=>[3, 7], 'PA'=>[3, 8], 'NJ'=>[3, 9], 'CT'=>[3, 10],
    'CA'=>[4, 1], 'UT'=>[4, 2], 'CO'=>[4, 3], 'NE'=>[4, 4], 'MO'=>[4, 5], 'KY'=>[4, 6], 'WV'=>[4, 7], 'VA'=>[4, 8], 'MD'=>[4, 9], 'DE'=>[4, 10],
    'AZ'=>[5, 2], 'NM'=>[5, 3], 'KS'=>[5, 4], 'AR'=>[5, 5], 'TN'=>[5, 6], 'NC'=>[5, 7], 'SC'=>[5, 8], 'DC'=>[5, 9],
    'OK'=>[6, 4], 'LA'=>[6, 5], 'MS'=>[6, 6], 'AL'=>[6, 7], 'GA'=>[6, 8],
    'HI'=>[7, 0], 'TX'=>[7, 4], 'FL'=>[7, 9],
];

const GROUP_STATE_CODES = [
    'Alabama'=>'AL', 'Alaska'=>'AK', 'Arizona'=>'AZ', 'Arkansas'=>'AR', 'California'=>'CA', 'Colorado'=>'CO', 'Connecticut'=>'CT', 'Delaware'=>'DE',
    'District of Columbia'=>'DC', 'Florida'=>'FL', 'Georgia'=>'GA', 'Hawaii'=>'HI', 'Idaho'=>'ID', 'Illinois'=>'IL', 'Indiana'=>'IN', 'Iowa'=>'IA',
    'Kansas'=>'KS', 'Kentucky'=>'KY', 'Louisiana'=>'LA', 'Maine'=>'ME', 'Maryland'=>'MD', 'Massachusetts'=>'MA', 'Michigan'=>'MI', 'Minnesota'=>'MN',
    'Mississippi'=>'MS', 'Missouri'=>'MO', 'Montana'=>'MT', 'Nebraska'=>'NE', 'Nevada'=>'NV', 'New Hampshire'=>'NH', 'New Jersey'=>'NJ', 'New Mexico'=>'NM',
    'New York'=>'NY', 'North Carolina'=>'NC', 'North Dakota'=>'ND', 'Ohio'=>'OH', 'Oklahoma'=>'OK', 'Oregon'=>'OR', 'Pennsylvania'=>'PA', 'Rhode Island'=>'RI',
    'South Carolina'=>'SC', 'South Dakota'=>'SD', 'Tennessee'=>'TN', 'Texas'=>'TX', 'Utah'=>'UT', 'Vermont'=>'VT', 'Virginia'=>'VA', 'Washington'=>'WA',
    'West Virginia'=>'WV', 'Wisconsin'=>'WI', 'Wyoming'=>'WY',
];

/** A web address that is safe to put in an href: https, a real host, no spaces or control characters. Empty when it is not. */
function groups_url(mixed $value): string {
    if (!is_string($value) || $value === '' || strlen($value) > 500 || preg_match('/[\x00-\x20\x7f"<>\\\\]/', $value)) {
        return '';
    }
    $parts = parse_url($value);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !preg_match('/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$/i', (string)($parts['host'] ?? '')) || isset($parts['user']) || isset($parts['pass'])) {
        return '';
    }
    return $value;
}

function groups_text(mixed $value, int $limit): string {
    return is_string($value) ? substr(trim((string)preg_replace('/[\x00-\x20\x7f]+/', ' ', $value)), 0, $limit) : '';
}

function groups_slug(string $name): string {
    return trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
}

/**
 * Reads and cleans the data: [ 'verified' => 'YYYY-MM-DD', 'groups' => [...] ]. Anything that does not look right is dropped,
 * so a bad row can never put a broken link or odd text on the page.
 */
function groups_load(?string $file = null): array {
    static $cache = [];
    $file ??= __DIR__ . '/groups.json';
    if (isset($cache[$file])) {
        return $cache[$file];
    }
    $raw = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    $verified = is_array($raw) && is_string($raw['verified'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $raw['verified']) ? $raw['verified'] : '';
    $groups = [];
    foreach (is_array($raw['groups'] ?? null) ? $raw['groups'] : [] as $g) {
        if (!is_array($g) || !isset(GROUP_PROTOCOLS[$g['protocol'] ?? '']) || !is_string($g['id'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,20}$/D', $g['id'])) {
            continue;
        }
        $name = groups_text($g['name'] ?? '', 80);
        $url = groups_url($g['url'] ?? '');
        $states = array_values(array_filter(array_map(static fn($s) => groups_text($s, 40), is_array($g['states'] ?? null) ? $g['states'] : []), static fn($s) => $s !== ''));
        if ($name === '' || $url === '' || !$states) {
            continue;
        }
        $groups[] = [
            'id'=>$g['id'], 'name'=>$name, 'city'=>groups_text($g['city'] ?? '', 60), 'protocol'=>$g['protocol'], 'type'=>groups_text($g['type'] ?? '', 40),
            'url'=>$url, 'discord'=>groups_url($g['discord'] ?? ''), 'discord_members_only'=>($g['discord_members_only'] ?? false) === true,
            'facebook'=>groups_url($g['facebook'] ?? ''), 'other'=>groups_url($g['other'] ?? ''), 'map'=>groups_url($g['map'] ?? ''),
            'coverage'=>groups_text($g['coverage'] ?? '', 110), 'notes'=>groups_text($g['notes'] ?? '', 160),
            'review'=>in_array($g['review'] ?? '', ['reviewed', 'directory', 'unconfirmed'], true) ? $g['review'] : 'directory', 'states'=>$states,
        ];
    }
    return $cache[$file] = ['verified'=>$verified, 'groups'=>$groups];
}

/** [state name => ['slug'=>..., 'code'=>..., 'count'=>n]] for every state or region that has at least one group, in name order. */
function groups_states(array $groups): array {
    $out = [];
    foreach ($groups as $g) {
        foreach ($g['states'] as $state) {
            $out[$state] ??= ['name'=>$state, 'slug'=>groups_slug($state), 'code'=>GROUP_STATE_CODES[$state] ?? '', 'count'=>0];
            $out[$state]['count']++;
        }
    }
    ksort($out, SORT_STRING | SORT_FLAG_CASE);
    return $out;
}

/** Groups that serve a state, by name. Pass '' for all groups. */
function groups_in(array $groups, string $state): array {
    return $state === '' ? $groups : array_values(array_filter($groups, static fn($g) => in_array($state, $g['states'], true)));
}

/** Filters by protocol, by "has a Discord people can join", and by words in the name, city or coverage. */
function groups_filter(array $groups, string $protocol, bool $discord, string $query): array {
    $terms = array_values(array_filter(preg_split('/\s+/', strtolower(trim($query))) ?: [], 'strlen'));
    return array_values(array_filter($groups, static function ($g) use ($protocol, $discord, $terms) {
        if ($protocol !== '' && $g['protocol'] !== $protocol && !($g['protocol'] === 'both' && in_array($protocol, ['meshtastic', 'meshcore'], true))) {
            return false;
        }
        if ($discord && ($g['discord'] === '' || $g['discord_members_only']) && !str_contains($g['url'], 'discord.')) {
            return false;
        }
        $hay = strtolower($g['name'] . ' ' . $g['city'] . ' ' . $g['coverage'] . ' ' . implode(' ', $g['states']));
        foreach ($terms as $term) {
            if (!str_contains($hay, $term)) {
                return false;
            }
        }
        return true;
    }));
}

/** What to call a link, from where it goes. */
function groups_link_label(string $url, string $fallback): string {
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    return match (true) {
        str_contains($host, 'discord') => 'Discord',
        str_contains($host, 'facebook.') => 'Facebook',
        str_contains($host, 'reddit.') => 'Reddit',
        str_contains($host, 'groups.io') => 'Groups.io',
        $host === 't.me' => 'Telegram',
        default => $fallback,
    };
}

/** The buttons for one group: [[label, url, primary?], ...]. The main link comes first, and nothing is listed twice. */
function groups_links(array $g): array {
    $links = [[groups_link_label($g['url'], 'Website'), $g['url'], true]];
    $seen = [$g['url'] => true];
    foreach ([['discord', 'Discord'], ['facebook', 'Facebook'], ['other', 'More'], ['map', 'Map']] as [$key, $label]) {
        $url = $g[$key];
        if ($url === '' || isset($seen[$url]) || ($key === 'discord' && $g['discord_members_only'])) {
            continue;
        }
        $seen[$url] = true;
        $links[] = [groups_link_label($url, $label), $url, false];
    }
    return $links;
}

/** The tile map as inline SVG. Each state is a link to its page. $selected is the slug of the state on show, if any. */
function groups_map_svg(array $states, string $selected, string $page = 'groups.php'): string {
    $size = 56;
    $gap = 3;
    $step = $size + $gap;
    $byCode = [];
    foreach ($states as $s) {
        if ($s['code'] !== '') {
            $byCode[$s['code']] = $s;
        }
    }
        $out = '<svg class="usmap" viewBox="0 0 ' . (12 * $step) . ' ' . (8 * $step) . '" role="group" aria-labelledby="map-title">'
        . '<title id="map-title">Map of the United States with one tile per state. The number on a tile is how many groups are listed there.</title>';
    foreach (GROUP_TILES as $code => [$row, $col]) {
        $s = $byCode[$code] ?? null;
        $x = $col * $step;
        $y = $row * $step;
        $level = $s === null ? 0 : ($s['count'] >= 10 ? 4 : ($s['count'] >= 6 ? 3 : ($s['count'] >= 3 ? 2 : 1)));
        $tile = '<rect class="tile l' . $level . ($s !== null && $s['slug'] === $selected ? ' on' : '') . '" x="' . $x . '" y="' . $y . '" width="' . $size . '" height="' . $size . '" rx="5"/>'
            . '<text class="code" x="' . ($x + $size / 2) . '" y="' . ($y + 25) . '" text-anchor="middle">' . $code . '</text>'
            . ($s !== null ? '<text class="num" x="' . ($x + $size / 2) . '" y="' . ($y + 43) . '" text-anchor="middle">' . $s['count'] . '</text>' : '');
        $out .= $s === null
            ? '<g class="empty"><title>' . h($code) . ': no groups listed</title>' . $tile . '</g>'
            : '<a href="' . h($page . '?state=' . $s['slug']) . '#groups"><title>' . h($s['name'] . ': ' . $s['count'] . ($s['count'] === 1 ? ' group' : ' groups')) . '</title>' . $tile . '</a>';
    }
    return $out . '</svg>';
}
