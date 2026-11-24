<?php
declare(strict_types=1);

if (!defined('OFFGRID_APP')) {
    http_response_code(404);
    exit;
}

const VISIT_DAYS_KEPT = 90;
const VISIT_SEEN_LIMIT = 20000;

function visit_is_bot(string $agent): bool {
    return $agent === '' || preg_match('/bot|crawl|spider|slurp|preview|monitor|curl|wget|python|httpclient|headless|facebookexternalhit|lighthouse/i', $agent) === 1;
}

function visits_load(string $dir): array {
    $file = $dir . '/visits.json';
    $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    return (is_array($data) ? $data : []) + ['salt'=>bin2hex(random_bytes(16)),'since'=>gmdate('Y-m-d'),'total'=>0,
        'days'=>[],'seen_day'=>'','seen'=>[]];
}

/**
 * Counts one page view (unless $record is false) and returns today's and all-time unique visitors.
 * A visitor is counted once per day. Only a salted daily hash is kept to avoid double counting, and it is
 * deleted when the day changes. No cookies are set and no IP addresses or user agents are stored.
 */
function visits_touch(string $dir, bool $record, string $ip, string $agent): array {
    $lock = fopen($dir . '/visits.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Could not lock the visitor counts.');
    }
    try {
        $data = visits_load($dir);
        $today = gmdate('Y-m-d');
        if ($data['seen_day'] !== $today) {
            $data['seen_day'] = $today;
            $data['seen'] = [];
        }
        if ($record && !visit_is_bot($agent)) {
            $day = $data['days'][$today] ?? ['views'=>0,'unique'=>0];
            $day['views']++;
            $id = substr(hash('sha256', $data['salt'] . '|' . $today . '|' . $ip . '|' . $agent), 0, 20);
            if (!isset($data['seen'][$id]) && count($data['seen']) < VISIT_SEEN_LIMIT) {
                $data['seen'][$id] = 1;
                $day['unique']++;
                $data['total']++;
            }
            $data['days'][$today] = $day;
            krsort($data['days']);
            $data['days'] = array_slice($data['days'], 0, VISIT_DAYS_KEPT, true);
            $temp = tempnam($dir, 'visits-');
            if ($temp === false) {
                throw new RuntimeException('Could not create a data file.');
            }
            chmod($temp, 0600);
            $json = json_encode($data, JSON_THROW_ON_ERROR);
            if (file_put_contents($temp, $json) !== strlen($json) || !rename($temp, $dir . '/visits.json')) {
                @unlink($temp);
                throw new RuntimeException('Could not save the visitor counts.');
            }
        }
        return ['today'=>(int)($data['days'][$today]['unique'] ?? 0),'total'=>(int)$data['total'],
            'since'=>$data['since'],'days'=>$data['days']];
    } finally {
        fclose($lock);
    }
}
