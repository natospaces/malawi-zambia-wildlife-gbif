<?php
/**
 * api.php  —  the map's data source (CSV-backed).
 *
 * Reads the published CSV export (occurrence_history.csv) and returns the same
 * JSON the map's JavaScript already consumes. The CSV is produced by etl.php
 * from MySQL (the system of record); this endpoint never calls GBIF and never
 * touches the database, so page loads are fast and don't depend on GBIF uptime.
 *
 *   ?q=meta    -> snapshot label + bbox + record count
 *   ?q=counts  -> per-species record counts (clean/flagged/total)
 *   ?q=points  -> occurrence points (optional &species=Panthera+leo)
 *
 * CSV is semicolon-delimited, quoted, literal NULL for empties (phpMyAdmin
 * export). Column order is read from the header row.
 */

header('Content-Type: application/json; charset=utf-8');

const CSV_FILE = __DIR__ . '/occurrence_history.csv';
const LAT_MIN = -14.5, LAT_MAX = -9.5;
const LON_MIN = 31.0,  LON_MAX = 35.0;

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

// json_encode returns false on invalid UTF-8; the flag makes it substitute bad
// bytes instead, so a stray character can never turn into an empty 500 response.
function say($data): void {
    // JSON_INVALID_UTF8_SUBSTITUTE needs PHP 7.2+; fall back to 0 on older.
    $flags = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;
    $json = json_encode($data, $flags);
    if ($json === false) {
        http_response_code(500);
        echo json_encode(['error' => 'encoding error in data', 'detail' => json_last_error_msg()]);
        return;
    }
    echo $json;
}

function read_csv(): array {
    if (!is_readable(CSV_FILE)) {
        fail(503, 'Data file not found. Run etl.php to produce occurrence_history.csv.');
    }
    $raw = file_get_contents(CSV_FILE);
    if ($raw === false) fail(500, 'Cannot open data file.');

    // Encoding safety: the export may arrive as UTF-8, UTF-8-with-BOM, or (from
    // some Windows/phpMyAdmin paths) Windows-1252. Normalise to UTF-8 so
    // accented names/localities don't break parsing or JSON output.
    // mbstring may be absent on some shared hosts, so every mb_* call is guarded.
    $raw = str_replace("\xEF\xBB\xBF", '', $raw); // strip UTF-8 BOM if present
    if (function_exists('mb_check_encoding') && !mb_check_encoding($raw, 'UTF-8')) {
        $conv = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        if ($conv !== false) $raw = $conv;
    } elseif (!function_exists('mb_check_encoding')) {
        // No mbstring: best-effort clean of invalid UTF-8 byte sequences so
        // json_encode still succeeds. iconv is more widely available.
        if (function_exists('iconv')) {
            $conv = @iconv('UTF-8', 'UTF-8//IGNORE', $raw);
            if ($conv !== false) $raw = $conv;
        }
    }

    // Parse the normalised text line by line with str_getcsv.
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    $header = null;
    $rows = [];
    foreach ($lines as $line) {
        if ($line === '' ) continue;
        $fields = str_getcsv($line, ';', '"');
        if ($header === null) { $header = array_map('trim', $fields); continue; }
        if (count($fields) === 1 && ($fields[0] === null || $fields[0] === '')) continue;
        $row = [];
        foreach ($header as $i => $col) {
            $v = $fields[$i] ?? null;
            $row[$col] = ($v === 'NULL' || $v === '') ? null : $v;
        }
        $rows[] = $row;
    }
    if ($header === null) fail(500, 'Empty data file.');
    return $rows;
}

function snapshot_label(array $rows): string {
    $latest = null;
    foreach ($rows as $r) {
        $t = $r['loaded_at'] ?? null;
        if ($t && ($latest === null || $t > $latest)) $latest = $t;
    }
    return $latest ? ('data as of ' . substr($latest, 0, 10)) : 'no data';
}

$q = $_GET['q'] ?? 'meta';

switch ($q) {
    case 'meta': {
        $rows = read_csv();
        say([
            'snapshot' => snapshot_label($rows),
            'bbox'     => LAT_MIN . ',' . LON_MIN . ',' . LAT_MAX . ',' . LON_MAX,
            'built_at' => (string)@filemtime(CSV_FILE),
            'records'  => count($rows),
        ]);
        break;
    }
    case 'counts': {
        $rows = read_csv();
        $agg = [];
        foreach ($rows as $r) {
            $sp = $r['species'] ?? null;
            if (!$sp) continue;
            if (!isset($agg[$sp])) {
                $agg[$sp] = ['species'=>$sp, 'common'=>$r['common_name'] ?? $sp,
                             'clean'=>0, 'flagged'=>0, 'total'=>0];
            }
            $agg[$sp]['total']++;
            if (($r['is_in_cluster'] ?? '0') === '1') $agg[$sp]['flagged']++;
            else $agg[$sp]['clean']++;
        }
        $out = array_values($agg);
        usort($out, function($a, $b) { return $b['clean'] <=> $a['clean']; });
        foreach ($out as &$r) { $r['clean']=(int)$r['clean']; $r['flagged']=(int)$r['flagged']; $r['total']=(int)$r['total']; }
        say($out);
        break;
    }
    case 'points': {
        $rows = read_csv();
        $species = $_GET['species'] ?? null;
        $out = [];
        foreach ($rows as $r) {
            if ($species !== null && ($r['species'] ?? null) !== $species) continue;
            $lat = $r['lat'] ?? null; $lon = $r['lon'] ?? null;
            if ($lat === null || $lon === null) continue;
            $out[] = [
                'species'     => $r['species'] ?? null,
                'common'      => $r['common_name'] ?? null,
                'lat'         => (float)$lat,
                'lon'         => (float)$lon,
                'year'        => isset($r['event_year']) ? (int)$r['event_year'] : null,
                'month'       => isset($r['event_month']) ? (int)$r['event_month'] : null,
                'trust_tier'  => $r['trust_tier'] ?? null,
                'count'       => isset($r['individual_count']) ? (int)$r['individual_count'] : null,
                'uncertainty' => isset($r['coordinate_uncertainty_m']) ? (float)$r['coordinate_uncertainty_m'] : null,
                'media'       => isset($r['media_count']) ? (int)$r['media_count'] : 0,
                'protocol'    => $r['sampling_protocol'] ?? null,
                'locality'    => $r['locality'] ?? null,
                'settlement'  => $r['nearest_settlement'] ?? null,
            ];
        }
        say($out);
        break;
    }
    default:
        fail(400, 'Unknown query. Use q=meta, q=counts, or q=points.');
}
