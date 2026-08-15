<?php
/**
 * GBIF -> MySQL history loader (per-species trailing window).
 *
 * For each MAZA species, finds the most recent recorded sighting in the
 * landscape, then loads every record from the 90 days before that date. Each
 * species anchors to ITS OWN latest sighting, not a shared calendar window,
 * because the five record at different rates and their latest dates are not
 * assumed to line up. Anchor dates are logged so the five can be compared
 * afterward to see how synchronised the recording actually is.
 *
 * Runs on Texo (matches the PHP site), connecting to MySQL over localhost.
 *
 *   php load_history.php                # default 90-day trail per species
 *   php load_history.php --days=120     # custom trail length
 *
 * cron (cPanel -> Cron Jobs):
 *   /usr/local/bin/php /home/USER/maza/load_history.php >> /home/USER/maza/history.log 2>&1
 *
 * Credentials: env MAZA_DB_HOST/_USER/_PASS/_NAME, or edit $DB_* below.
 * Creates its tables if absent; works with manually-created tables if columns match.
 */

$DB_HOST = getenv('MAZA_DB_HOST') ?: 'localhost';
$DB_USER = getenv('MAZA_DB_USER') ?: 'maza';
$DB_PASS = getenv('MAZA_DB_PASS') ?: '';
$DB_NAME = getenv('MAZA_DB_NAME') ?: 'maza';

const LAT_MIN = -14.5, LAT_MAX = -9.5;
const LON_MIN = 31.0,  LON_MAX = 35.0;

$SPECIES = [
    'Loxodonta africana'     => 'African elephant',
    'Panthera leo'           => 'Lion',
    'Hippopotamus amphibius' => 'Hippopotamus',
    'Crocodylus niloticus'   => 'Nile crocodile',
    'Syncerus caffer'        => 'African buffalo',
];

const GBIF = 'https://api.gbif.org/v1';
const PAGE = 300;
const DEFAULT_DAYS = 90;

function arg(string $name, $default = null) {
    global $argv;
    foreach ($argv as $a) {
        if (strpos($a, "--$name=") === 0) return substr($a, strlen("--$name="));
    }
    return $default;
}

function http_get_json(string $url) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'maza-history/1.0',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code !== 200) return null;
        return json_decode($body, true);
    }
    $ctx = stream_context_create(['http' => ['timeout' => 60, 'user_agent' => 'maza-history/1.0']]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? null : json_decode($body, true);
}

function taxon_key(string $name): ?int {
    $j = http_get_json(GBIF . '/species/match?name=' . rawurlencode($name));
    return isset($j['usageKey']) ? (int)$j['usageKey'] : null;
}

function tier_of(string $basis): string {
    if ($basis === 'PRESERVED_SPECIMEN' || $basis === 'MATERIAL_SAMPLE') return 'specimen';
    if ($basis === 'MACHINE_OBSERVATION') return 'machine';
    if ($basis === 'HUMAN_OBSERVATION') return 'observation';
    return 'other';
}

function box_params(int $taxonKey): string {
    return 'taxonKey=' . $taxonKey
         . '&decimalLatitude=' . LAT_MIN . ',' . LAT_MAX
         . '&decimalLongitude=' . LON_MIN . ',' . LON_MAX
         . '&hasCoordinate=true&hasGeospatialIssue=false';
}

/** Most recent eventDate for a species in the box: 'YYYY-MM-DD' or null. */
function latest_event_date(int $taxonKey): ?string {
    $facet = http_get_json(GBIF . '/occurrence/search?' . box_params($taxonKey)
        . '&limit=0&facet=year&facetLimit=300');
    if (!$facet || empty($facet['facets'])) return null;
    $years = [];
    foreach ($facet['facets'] as $f) {
        if (strtoupper($f['field'] ?? '') === 'YEAR') {
            foreach ($f['counts'] as $c) $years[] = (int)$c['name'];
        }
    }
    if (!$years) return null;
    $maxYear = max($years);

    $j = http_get_json(GBIF . '/occurrence/search?' . box_params($taxonKey)
        . '&year=' . $maxYear . '&limit=300');
    if (!$j || empty($j['results'])) return sprintf('%04d-12-31', $maxYear);
    $best = null;
    foreach ($j['results'] as $r) {
        $d = $r['eventDate'] ?? null;
        if ($d) {
            $d = substr($d, 0, 10);
            if ($best === null || $d > $best) $best = $d;
        }
    }
    return $best ?: sprintf('%04d-12-31', $maxYear);
}

function fetch_range(int $taxonKey, string $from, string $to): array {
    $out = [];
    $offset = 0;
    while (true) {
        $url = GBIF . '/occurrence/search?' . box_params($taxonKey)
             . '&eventDate=' . $from . ',' . $to
             . '&limit=' . PAGE . '&offset=' . $offset;
        $j = http_get_json($url);
        if (!$j || empty($j['results'])) break;
        foreach ($j['results'] as $r) $out[] = $r;
        if (!empty($j['endOfRecords'])) break;
        $offset += PAGE;
        usleep(200000);
    }
    return $out;
}

function db(): PDO {
    global $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME;
    return new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

const DDL_NOTE = "Tables are created by schema.sql, not by this loader.";

const UPSERT = "
INSERT INTO occurrence_history (
  gbif_id, occurrence_id, species, common_name, scientific_name,
  individual_count, organism_quantity, organism_quantity_type, occurrence_status,
  sex, life_stage, reproductive_condition, behavior,
  lat, lon, coordinate_uncertainty_m, locality, state_province, country_code,
  elevation_m, depth_m,
  event_date, event_year, event_month, event_day,
  basis_of_record, trust_tier, sampling_protocol, recorded_by, identified_by,
  establishment_means, is_in_cluster,
  dataset_key, dataset_name, publishing_org, institution_code, collection_code,
  license, media_count, loaded_at
) VALUES (
  :gid,:oid,:sp,:common,:sciname,
  :icount,:oquant,:oqtype,:ostatus,
  :sex,:lstage,:repro,:behavior,
  :lat,:lon,:cuncert,:locality,:province,:ccode,
  :elev,:depth,
  :edate,:eyear,:emonth,:eday,
  :basis,:tier,:protocol,:recby,:idby,
  :estmeans,:cluster,
  :dkey,:dname,:porg,:icode,:ccode2,
  :license,:mcount,NOW()
)
ON DUPLICATE KEY UPDATE
  occurrence_id=VALUES(occurrence_id), scientific_name=VALUES(scientific_name),
  individual_count=VALUES(individual_count), organism_quantity=VALUES(organism_quantity),
  organism_quantity_type=VALUES(organism_quantity_type), occurrence_status=VALUES(occurrence_status),
  sex=VALUES(sex), life_stage=VALUES(life_stage), reproductive_condition=VALUES(reproductive_condition),
  behavior=VALUES(behavior), lat=VALUES(lat), lon=VALUES(lon),
  coordinate_uncertainty_m=VALUES(coordinate_uncertainty_m), locality=VALUES(locality),
  state_province=VALUES(state_province), country_code=VALUES(country_code),
  elevation_m=VALUES(elevation_m), depth_m=VALUES(depth_m),
  event_date=VALUES(event_date), event_year=VALUES(event_year),
  event_month=VALUES(event_month), event_day=VALUES(event_day),
  basis_of_record=VALUES(basis_of_record), trust_tier=VALUES(trust_tier),
  sampling_protocol=VALUES(sampling_protocol), recorded_by=VALUES(recorded_by),
  identified_by=VALUES(identified_by), establishment_means=VALUES(establishment_means),
  is_in_cluster=VALUES(is_in_cluster), dataset_key=VALUES(dataset_key),
  dataset_name=VALUES(dataset_name), publishing_org=VALUES(publishing_org),
  institution_code=VALUES(institution_code), collection_code=VALUES(collection_code),
  license=VALUES(license), media_count=VALUES(media_count), loaded_at=NOW();
";

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This loader is a CLI/cron script, not a web endpoint.\n");
}

$days = (int)(arg('days', DEFAULT_DAYS));
if ($days < 1) $days = DEFAULT_DAYS;

try {
    $pdo = db();

    // The loader does not create objects. Verify the tables exist (built by
    // schema.sql) and stop with a clear message if they don't.
    foreach (['occurrence_history', 'load_log'] as $tbl) {
        $ok = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($tbl))->fetch();
        if (!$ok) {
            fwrite(STDERR, "Table '$tbl' not found. Run schema.sql first "
                . "(mysql -u USER -p DBNAME < schema.sql).\n");
            exit(1);
        }
    }

    $ins = $pdo->prepare(UPSERT);
    $log = $pdo->prepare(
        "INSERT INTO load_log
           (species, common_name, latest_date, window_from, window_to, record_count, loaded_at)
         VALUES (:sp,:common,:latest,:from,:to,:rc,NOW())"
    );

    $summary = [];
    foreach ($SPECIES as $name => $common) {
        echo "$common ($name):\n";
        $key = taxon_key($name);
        if (!$key) { echo "  no taxon key, skipped\n\n"; continue; }

        $latest = latest_event_date($key);
        if (!$latest) {
            echo "  no dated records found in box, skipped\n\n";
            $log->execute([':sp'=>$name, ':common'=>$common, ':latest'=>null,
                           ':from'=>null, ':to'=>null, ':rc'=>0]);
            continue;
        }

        $to   = $latest;
        $from = date('Y-m-d', strtotime("$latest -$days days"));
        echo "  latest sighting: $latest  ->  window $from .. $to ($days days)\n";

        $recs = fetch_range($key, $from, $to);
        $n = 0;
        foreach ($recs as $r) {
            $gid = $r['gbifID'] ?? $r['key'] ?? null;
            if ($gid === null) continue;
            $basis = $r['basisOfRecord'] ?? 'OCCURRENCE';
            $edate = isset($r['eventDate']) ? substr($r['eventDate'], 0, 10) : null;
            // media is an array of {type,...}; store how many are attached
            $mcount = isset($r['media']) && is_array($r['media']) ? count($r['media']) : null;
            $ins->execute([
                ':gid'      => (int)$gid,
                ':oid'      => $r['occurrenceID'] ?? null,
                ':sp'       => $name,
                ':common'   => $common,
                ':sciname'  => $r['scientificName'] ?? null,
                ':icount'   => isset($r['individualCount']) ? (int)$r['individualCount'] : null,
                ':oquant'   => isset($r['organismQuantity']) ? (float)$r['organismQuantity'] : null,
                ':oqtype'   => $r['organismQuantityType'] ?? null,
                ':ostatus'  => $r['occurrenceStatus'] ?? null,
                ':sex'      => $r['sex'] ?? null,
                ':lstage'   => $r['lifeStage'] ?? null,
                ':repro'    => $r['reproductiveCondition'] ?? null,
                ':behavior' => $r['behavior'] ?? null,
                ':lat'      => $r['decimalLatitude'] ?? null,
                ':lon'      => $r['decimalLongitude'] ?? null,
                ':cuncert'  => isset($r['coordinateUncertaintyInMeters']) ? (float)$r['coordinateUncertaintyInMeters'] : null,
                ':locality' => $r['locality'] ?? null,
                ':province' => $r['stateProvince'] ?? null,
                ':ccode'    => $r['countryCode'] ?? null,
                ':elev'     => isset($r['elevation']) ? (float)$r['elevation'] : null,
                ':depth'    => isset($r['depth']) ? (float)$r['depth'] : null,
                ':edate'    => $edate,
                ':eyear'    => $r['year'] ?? null,
                ':emonth'   => $r['month'] ?? null,
                ':eday'     => $r['day'] ?? null,
                ':basis'    => $basis,
                ':tier'     => tier_of($basis),
                ':protocol' => $r['samplingProtocol'] ?? null,
                ':recby'    => $r['recordedBy'] ?? null,
                ':idby'     => $r['identifiedBy'] ?? null,
                ':estmeans' => $r['establishmentMeans'] ?? null,
                ':cluster'  => isset($r['isInCluster']) ? ($r['isInCluster'] ? 1 : 0) : null,
                ':dkey'     => $r['datasetKey'] ?? null,
                ':dname'    => $r['datasetName'] ?? null,
                ':porg'     => $r['publishingOrgKey'] ?? null,
                ':icode'    => $r['institutionCode'] ?? null,
                ':ccode2'   => $r['collectionCode'] ?? null,
                ':license'  => $r['license'] ?? null,
                ':mcount'   => $mcount,
            ]);
            $n++;
        }
        $log->execute([':sp'=>$name, ':common'=>$common, ':latest'=>$latest,
                       ':from'=>$from, ':to'=>$to, ':rc'=>$n]);
        echo "  loaded $n records\n\n";
        $summary[] = [$common, $latest, $n];
    }

    echo "Latest-sighting comparison (how synchronised the five are):\n";
    foreach ($summary as [$common, $latest, $n]) {
        echo sprintf("  %-18s latest %s   %d records in trailing %dd\n", $common, $latest, $n, $days);
    }
    $grand = (int)$pdo->query("SELECT COUNT(*) FROM occurrence_history")->fetchColumn();
    echo "\noccurrence_history now holds $grand records total.\n";

} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
