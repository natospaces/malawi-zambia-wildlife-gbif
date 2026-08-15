<?php
/**
 * etl.php  —  mini-ETL for the MAZA occurrence viewer.
 *
 * Flow:  GBIF  ->  MySQL (raw upsert)  ->  enrich in MySQL  ->  export CSV
 *
 * MySQL is the system of record (where enrichment and history live). The CSV
 * it exports is the published read-copy that api.php serves to the map. Each
 * run is logged so the ETL can LEARN how often updating is actually worth it
 * (see the cadence report at the end).
 *
 * Runs on Texo (CLI or cron), connecting to MySQL over localhost.
 *
 *   php etl.php                 # default 3000-day trailing window per species
 *   php etl.php --days=1825      # custom window
 *   php etl.php --no-fetch       # skip GBIF, just re-enrich + re-export
 *   php etl.php --cadence        # only print the update-cadence reading
 *
 * Tables come from schema.sql. This script does not create them.
 * Credentials: a config file kept ABOVE the webroot (see below).
 */

// ---------------------------------------------------------------------------
// Database credentials.
// Load them from a config file kept ABOVE the webroot. The web server cannot
// serve that file, so the password stays private. Set MAZA_CONFIG to override
// the path, else the default is one folder up from this script.
// ---------------------------------------------------------------------------
// ---------------------------------------------------------------------------
// Load DB credentials from a config file kept ABOVE the webroot, and VALIDATE
// the load so a missing/broken config fails loudly here instead of surfacing
// later as an opaque connection error (a common cause of a 500).
// ---------------------------------------------------------------------------
$cfgPath = getenv('MAZA_CONFIG') ?: (dirname(__DIR__) . '/maza_config.php');

function config_fail(string $msg): void {
    // CLI: write to stderr and exit. Web: 500 with a short JSON note.
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "CONFIG ERROR: $msg\n");
    } else {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'server configuration problem', 'detail' => $msg]);
    }
    exit(1);
}

if (!is_file($cfgPath))       config_fail("config file not found at: $cfgPath");
if (!is_readable($cfgPath))   config_fail("config file not readable (check permissions): $cfgPath");

$cfg = require $cfgPath;
if (!is_array($cfg))          config_fail("config file did not return an array. It must end with: return ['DB_HOST'=>..., 'DB_USER'=>..., 'DB_PASS'=>..., 'DB_NAME'=>...];");

$required = ['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME'];
$missing = [];
foreach ($required as $k) {
    // DB_PASS may legitimately be empty; the others must be non-empty.
    if (!array_key_exists($k, $cfg)) { $missing[] = $k; continue; }
    if ($k !== 'DB_PASS' && ($cfg[$k] === '' || $cfg[$k] === null)) $missing[] = "$k (empty)";
}
if ($missing) config_fail("config missing/empty keys: " . implode(', ', $missing));

$DB_HOST = $cfg['DB_HOST'];
$DB_USER = $cfg['DB_USER'];
$DB_PASS = $cfg['DB_PASS'];
$DB_NAME = $cfg['DB_NAME'];

// Where the published CSV lands: next to the site's api.php.
$CSV_OUT = getenv('MAZA_CSV_OUT') ?: (__DIR__ . '/../public/occurrence_history.csv');

const LAT_MIN = -14.5, LAT_MAX = -9.5;
const LON_MIN = 31.0,  LON_MAX = 35.0;
const GBIF = 'https://api.gbif.org/v1';
const PAGE = 300;
const DEFAULT_DAYS = 3000;

$SPECIES = [
    'Loxodonta africana'     => 'African elephant',
    'Panthera leo'           => 'Lion',
    'Hippopotamus amphibius' => 'Hippopotamus',
    'Crocodylus niloticus'   => 'Nile crocodile',
    'Syncerus caffer'        => 'African buffalo',
];

// Enrichment reference: park boundaries + settled cells. Optional — if the
// GeoJSON/JSON files aren't beside the script, enrichment for that dimension
// is skipped rather than failing.
$PA_FILE  = __DIR__ . '/../public/protected_areas.geojson';
$POP_FILE = __DIR__ . '/../public/population.json';
$SETTLE_FILE = __DIR__ . '/../public/settlements.json';

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

function arg($n,$d=null){global $argv;foreach($argv as $a){if(strpos($a,"--$n=")===0)return substr($a,strlen("--$n="));if($a==="--$n")return true;}return $d;}

function http_get_json(string $url) {
    if (function_exists('curl_init')) {
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_USERAGENT=>'maza-etl/1.0']);
        $b=curl_exec($ch);$c=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        return ($b===false||$c!==200)?null:json_decode($b,true);
    }
    $ctx=stream_context_create(['http'=>['timeout'=>60,'user_agent'=>'maza-etl/1.0']]);
    $b=@file_get_contents($url,false,$ctx);
    return $b===false?null:json_decode($b,true);
}
function taxon_key(string $n): ?int { $j=http_get_json(GBIF.'/species/match?name='.rawurlencode($n)); return isset($j['usageKey'])?(int)$j['usageKey']:null; }
function tier_of(string $b): string { if($b==='PRESERVED_SPECIMEN'||$b==='MATERIAL_SAMPLE')return 'specimen'; if($b==='MACHINE_OBSERVATION')return 'machine'; if($b==='HUMAN_OBSERVATION')return 'observation'; return 'other'; }
function box_params(int $k): string { return 'taxonKey='.$k.'&decimalLatitude='.LAT_MIN.','.LAT_MAX.'&decimalLongitude='.LON_MIN.','.LON_MAX.'&hasCoordinate=true&hasGeospatialIssue=false'; }

function latest_event_date(int $k): ?string {
    $f=http_get_json(GBIF.'/occurrence/search?'.box_params($k).'&limit=0&facet=year&facetLimit=300');
    if(!$f||empty($f['facets']))return null;
    $years=[];foreach($f['facets'] as $ff){if(strtoupper($ff['field']??'')==='YEAR')foreach($ff['counts'] as $c)$years[]=(int)$c['name'];}
    if(!$years)return null;$my=max($years);
    $j=http_get_json(GBIF.'/occurrence/search?'.box_params($k).'&year='.$my.'&limit=300');
    if(!$j||empty($j['results']))return sprintf('%04d-12-31',$my);
    $best=null;foreach($j['results'] as $r){$d=$r['eventDate']??null;if($d){$d=substr($d,0,10);if($best===null||$d>$best)$best=$d;}}
    return $best?:sprintf('%04d-12-31',$my);
}
function fetch_range(int $k,string $from,string $to): array {
    $out=[];$off=0;
    while(true){
        $u=GBIF.'/occurrence/search?'.box_params($k).'&eventDate='.$from.','.$to.'&limit='.PAGE.'&offset='.$off;
        $j=http_get_json($u);if(!$j||empty($j['results']))break;
        foreach($j['results'] as $r)$out[]=$r;
        if(!empty($j['endOfRecords']))break;$off+=PAGE;usleep(200000);
    }
    return $out;
}

function db(): PDO { global $DB_HOST,$DB_USER,$DB_PASS,$DB_NAME;
    try {
        return new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",$DB_USER,$DB_PASS,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    } catch (PDOException $e) {
        // Report WHICH detail failed without leaking the password.
        fwrite(STDERR, "DB CONNECT FAILED as user '$DB_USER' to db '$DB_NAME' on '$DB_HOST'.\n");
        fwrite(STDERR, "  Check maza_config.php has the right credentials and the user has access.\n");
        fwrite(STDERR, "  MySQL said: " . $e->getMessage() . "\n");
        exit(1);
    }
}

const UPSERT = "
INSERT INTO occurrence_history (
  gbif_id,occurrence_id,species,common_name,scientific_name,
  individual_count,organism_quantity,organism_quantity_type,occurrence_status,
  sex,life_stage,reproductive_condition,behavior,
  lat,lon,coordinate_uncertainty_m,locality,state_province,country_code,elevation_m,depth_m,
  event_date,event_year,event_month,event_day,
  basis_of_record,trust_tier,sampling_protocol,recorded_by,identified_by,establishment_means,is_in_cluster,
  dataset_key,dataset_name,publishing_org,institution_code,collection_code,license,media_count,loaded_at
) VALUES (
  :gid,:oid,:sp,:common,:sciname,:icount,:oquant,:oqtype,:ostatus,
  :sex,:lstage,:repro,:behavior,:lat,:lon,:cuncert,:locality,:province,:ccode,:elev,:depth,
  :edate,:eyear,:emonth,:eday,:basis,:tier,:protocol,:recby,:idby,:estmeans,:cluster,
  :dkey,:dname,:porg,:icode,:ccode2,:license,:mcount,NOW()
) ON DUPLICATE KEY UPDATE
  species=VALUES(species),common_name=VALUES(common_name),scientific_name=VALUES(scientific_name),
  individual_count=VALUES(individual_count),organism_quantity=VALUES(organism_quantity),
  organism_quantity_type=VALUES(organism_quantity_type),occurrence_status=VALUES(occurrence_status),
  sex=VALUES(sex),life_stage=VALUES(life_stage),reproductive_condition=VALUES(reproductive_condition),
  behavior=VALUES(behavior),lat=VALUES(lat),lon=VALUES(lon),coordinate_uncertainty_m=VALUES(coordinate_uncertainty_m),
  locality=VALUES(locality),state_province=VALUES(state_province),country_code=VALUES(country_code),
  elevation_m=VALUES(elevation_m),depth_m=VALUES(depth_m),event_date=VALUES(event_date),
  event_year=VALUES(event_year),event_month=VALUES(event_month),event_day=VALUES(event_day),
  basis_of_record=VALUES(basis_of_record),trust_tier=VALUES(trust_tier),sampling_protocol=VALUES(sampling_protocol),
  recorded_by=VALUES(recorded_by),identified_by=VALUES(identified_by),establishment_means=VALUES(establishment_means),
  is_in_cluster=VALUES(is_in_cluster),dataset_key=VALUES(dataset_key),dataset_name=VALUES(dataset_name),
  publishing_org=VALUES(publishing_org),institution_code=VALUES(institution_code),
  collection_code=VALUES(collection_code),license=VALUES(license),media_count=VALUES(media_count),loaded_at=NOW();
";

// ---- extract + load --------------------------------------------------------
function extract_load(PDO $pdo, array $species, int $days): array {
    $ins=$pdo->prepare(UPSERT);
    $before=(int)$pdo->query("SELECT COUNT(*) FROM occurrence_history")->fetchColumn();
    $fetched=0;
    foreach($species as $name=>$common){
        $k=taxon_key($name); if(!$k){echo "  $common: no taxon key\n";continue;}
        $latest=latest_event_date($k); if(!$latest){echo "  $common: no dated records\n";continue;}
        $to=$latest; $from=date('Y-m-d',strtotime("$latest -$days days"));
        $recs=fetch_range($k,$from,$to);
        foreach($recs as $r){
            $gid=$r['gbifID']??$r['key']??null; if($gid===null)continue;
            $basis=$r['basisOfRecord']??'OCCURRENCE';
            $mcount=isset($r['media'])&&is_array($r['media'])?count($r['media']):null;
            $ins->execute([
                ':gid'=>(int)$gid,':oid'=>$r['occurrenceID']??null,':sp'=>$name,':common'=>$common,
                ':sciname'=>$r['scientificName']??null,':icount'=>isset($r['individualCount'])?(int)$r['individualCount']:null,
                ':oquant'=>isset($r['organismQuantity'])?(float)$r['organismQuantity']:null,':oqtype'=>$r['organismQuantityType']??null,
                ':ostatus'=>$r['occurrenceStatus']??null,':sex'=>$r['sex']??null,':lstage'=>$r['lifeStage']??null,
                ':repro'=>$r['reproductiveCondition']??null,':behavior'=>$r['behavior']??null,
                ':lat'=>$r['decimalLatitude']??null,':lon'=>$r['decimalLongitude']??null,
                ':cuncert'=>isset($r['coordinateUncertaintyInMeters'])?(float)$r['coordinateUncertaintyInMeters']:null,
                ':locality'=>$r['locality']??null,':province'=>$r['stateProvince']??null,':ccode'=>$r['countryCode']??null,
                ':elev'=>isset($r['elevation'])?(float)$r['elevation']:null,':depth'=>isset($r['depth'])?(float)$r['depth']:null,
                ':edate'=>isset($r['eventDate'])?substr($r['eventDate'],0,10):null,':eyear'=>$r['year']??null,
                ':emonth'=>$r['month']??null,':eday'=>$r['day']??null,':basis'=>$basis,':tier'=>tier_of($basis),
                ':protocol'=>$r['samplingProtocol']??null,':recby'=>$r['recordedBy']??null,':idby'=>$r['identifiedBy']??null,
                ':estmeans'=>$r['establishmentMeans']??null,':cluster'=>isset($r['isInCluster'])?($r['isInCluster']?1:0):null,
                ':dkey'=>$r['datasetKey']??null,':dname'=>$r['datasetName']??null,':porg'=>$r['publishingOrgKey']??null,
                ':icode'=>$r['institutionCode']??null,':ccode2'=>$r['collectionCode']??null,':license'=>$r['license']??null,':mcount'=>$mcount,
            ]);
            $fetched++;
        }
        echo "  $common: window $from..$to, ".count($recs)." fetched\n";
    }
    $after=(int)$pdo->query("SELECT COUNT(*) FROM occurrence_history")->fetchColumn();
    return ['fetched'=>$fetched,'new'=>$after-$before,'before'=>$before,'after'=>$after];
}

// ---- enrich (in MySQL, the system of record) -------------------------------
// Adds derived columns the map/analysis benefit from, computed once here rather
// than per page load. Requires the two derived columns to exist; if not, we add
// them (this is enrichment metadata, not core schema — safe to auto-add).
function enrich(PDO $pdo, ?string $paFile, ?string $popFile): int {
    // Ensure enrichment columns exist.
    foreach ([
        "in_protected_area VARCHAR(120) NULL",
        "in_settled_area TINYINT(1) NULL",
        "nearest_settlement VARCHAR(160) NULL",
    ] as $coldef) {
        [$col] = explode(' ', $coldef);
        $has=$pdo->query("SHOW COLUMNS FROM occurrence_history LIKE ".$pdo->quote($col))->fetch();
        if(!$has) $pdo->exec("ALTER TABLE occurrence_history ADD COLUMN $coldef");
    }

    $updated=0;
    // Protected-area flag via point-in-polygon (PHP-side, over the GeoJSON).
    $parks=[];
    if($paFile && is_readable($paFile)){
        $gj=json_decode(file_get_contents($paFile),true);
        foreach(($gj['features']??[]) as $f){
            $name=$f['properties']['name']??'park';
            $polys=[];
            $g=$f['geometry']??[];
            if(($g['type']??'')==='Polygon')$polys=[$g['coordinates']];
            elseif(($g['type']??'')==='MultiPolygon')$polys=$g['coordinates'];
            $parks[]=['name'=>$name,'polys'=>$polys];
        }
    }
    // Settled cells from population.json (cells with pop >= threshold).
    $settled=[]; $cell=0.1; $thr=0;
    if($popFile && is_readable($popFile)){
        $pj=json_decode(file_get_contents($popFile),true);
        $thr=$pj['settled_threshold']??0;
        foreach(($pj['cells']??[]) as $c){ if(($c['pop']??0)>=$thr){ $settled[round($c['lat'],1).','.round($c['lon'],1)]=true; } }
    }

    if(!$parks && !$settled) { echo "  enrichment: no reference files found, skipped\n"; return 0; }

    // Load named settlements (villages/towns) for nearest-village labelling.
    // Conflict happens at a village, so records get a real village name.
    global $SETTLE_FILE;
    $settlements = [];
    if (isset($SETTLE_FILE) && is_readable($SETTLE_FILE)) {
        $sj = json_decode(file_get_contents($SETTLE_FILE), true);
        foreach (($sj['places'] ?? []) as $s) {
            if (isset($s['lat'], $s['lon'], $s['name']))
                $settlements[] = [$s['lat'], $s['lon'], $s['name'], $s['place'] ?? 'settlement'];
        }
        echo "  loaded " . count($settlements) . " named settlements for labelling\n";
    } else {
        echo "  no settlements.json — nearest-village labels skipped (run build_settlements.py)\n";
    }

    $rows=$pdo->query("SELECT gbif_id,lat,lon FROM occurrence_history WHERE lat IS NOT NULL AND lon IS NOT NULL")->fetchAll();
    $up=$pdo->prepare("UPDATE occurrence_history SET in_protected_area=:pa, in_settled_area=:st, nearest_settlement=:ns WHERE gbif_id=:id");
    foreach($rows as $r){
        $lat=(float)$r['lat'];$lon=(float)$r['lon'];
        $paName=null;
        foreach($parks as $p){ foreach($p['polys'] as $poly){ if(point_in_ring($lon,$lat,$poly[0])){ $paName=$p['name']; break 2; } } }
        $inSettled = $settled ? (isset($settled[round($lat,1).','.round($lon,1)])?1:0) : null;
        $nearest = nearest_settlement_name($lat, $lon, $settlements);
        $up->execute([':pa'=>$paName,':st'=>$inSettled,':ns'=>$nearest,':id'=>$r['gbif_id']]);
        $updated++;
    }
    echo "  enrichment: $updated rows flagged (park + settled + nearest village)\n";
    return $updated;
}

/** Nearest named settlement to (lat,lon), with rough distance in km appended. */
function nearest_settlement_name(float $lat, float $lon, array $settlements): ?string {
    if (!$settlements) return null;
    $bestName = null; $bestKm = null; $bestType = null;
    foreach ($settlements as $s) {
        // equirectangular approx — fine for nearest-neighbour at this scale
        $dlat = ($s[0] - $lat) * 111.0;
        $dlon = ($s[1] - $lon) * 111.0 * cos(deg2rad($lat));
        $km = sqrt($dlat*$dlat + $dlon*$dlon);
        if ($bestKm === null || $km < $bestKm) { $bestKm = $km; $bestName = $s[2]; $bestType = $s[3]; }
    }
    if ($bestName === null) return null;
    // Label honestly: it's the NEAREST named place, with distance, not a
    // recorded locality. e.g. "Chama (village, ~2 km)".
    $kmR = $bestKm < 1 ? '<1' : (string)(int)round($bestKm);
    return "$bestName ($bestType, ~{$kmR} km)";
}
function point_in_ring(float $x,float $y,array $ring): bool {
    $in=false;$n=count($ring);
    for($i=0,$j=$n-1;$i<$n;$j=$i++){
        $xi=$ring[$i][0];$yi=$ring[$i][1];$xj=$ring[$j][0];$yj=$ring[$j][1];
        if((($yi>$y)!=($yj>$y)) && ($x<($xj-$xi)*($y-$yi)/(($yj-$yi)?:1e-12)+$xi)) $in=!$in;
    }
    return $in;
}

// ---- export CSV (the published read-copy) ----------------------------------
function export_csv(PDO $pdo, string $out): int {
    // Force the connection to speak utf8mb4 so accented names/localities come
    // back as valid UTF-8 (the character-set problem seen on the Python side).
    $pdo->exec("SET NAMES utf8mb4");

    $cols=$pdo->query("SHOW COLUMNS FROM occurrence_history")->fetchAll();
    $names=array_map(function($c){return $c["Field"];},$cols);
    $tmp=$out.'.tmp';
    $fh=fopen($tmp,'w'); if(!$fh) throw new RuntimeException("cannot write $tmp");

    // UTF-8 BOM: makes the encoding unambiguous for Excel/phpMyAdmin/whatever
    // opens the file next, so nothing re-guesses it as Windows-1252.
    fwrite($fh, "\xEF\xBB\xBF");

    fputcsv($fh,$names,';','"');
    $stmt=$pdo->query("SELECT * FROM occurrence_history");
    $n=0;
    while($row=$stmt->fetch()){
        $line=[];
        foreach($names as $c){
            $v=$row[$c];
            if ($v===null) { $line[]='NULL'; continue; }
            // Guarantee valid UTF-8 out, even if a stray byte slipped into the DB.
            // mbstring preferred; iconv fallback; if neither, pass through.
            $v=(string)$v;
            if (function_exists('mb_check_encoding')) {
                if (!mb_check_encoding($v,'UTF-8')) $v=mb_convert_encoding($v,'UTF-8','UTF-8,Windows-1252,ISO-8859-1');
            } elseif (function_exists('iconv')) {
                $conv=@iconv('UTF-8','UTF-8//IGNORE',$v); if($conv!==false)$v=$conv;
            }
            $line[]=$v;
        }
        fputcsv($fh,$line,';','"');
        $n++;
    }
    fclose($fh);
    rename($tmp,$out); // atomic swap so the map never reads a half-written file
    return $n;
}

// ---- cadence learning ------------------------------------------------------
// Reads etl_runs to work out how often updating is actually worth it: how fast
// new records arrive, and how often recent runs added nothing.
function cadence_report(PDO $pdo): void {
    $runs=$pdo->query("SELECT run_at,new_records,total_records FROM etl_runs ORDER BY run_at DESC LIMIT 30")->fetchAll();
    echo "\n".str_repeat('-',60)."\nUpdate cadence reading\n".str_repeat('-',60)."\n";
    if(count($runs)<2){ echo "  Not enough run history yet (need 2+ runs). Keep running to learn the cadence.\n"; return; }
    // new records per day between first and last of the window
    $newest=strtotime($runs[0]['run_at']); $oldest=strtotime(end($runs)['run_at']);
    $spanDays=max(1,($newest-$oldest)/86400);
    $sumNew=0; $emptyRuns=0;
    foreach($runs as $r){ $sumNew+=(int)$r['new_records']; if((int)$r['new_records']===0)$emptyRuns++; }
    $perDay=$sumNew/$spanDays;
    printf("  Runs analysed: %d over %.0f days\n",count($runs),$spanDays);
    printf("  New records added total: %d  (~%.2f per day)\n",$sumNew,$perDay);
    printf("  Runs that added nothing: %d of %d\n",$emptyRuns,count($runs));
    // recommendation
    if($perDay<=0.05){
        echo "  Reading: new data is very rare here. Monthly (or slower) is plenty;\n";
        echo "           daily/weekly runs mostly do nothing.\n";
    } elseif($perDay<1){
        $days=max(1,(int)round(1/$perDay));
        echo "  Reading: roughly one new record every ~$days days. A weekly run\n";
        echo "           comfortably keeps up without wasting effort.\n";
    } else {
        echo "  Reading: new records arrive daily or faster. A daily run is justified.\n";
    }
    if($emptyRuns > count($runs)*0.6){
        echo "  Note: most recent runs added nothing — you're likely running too often.\n";
    }
}

// ---- main ------------------------------------------------------------------
$days=(int)(arg('days',DEFAULT_DAYS)); if($days<1)$days=DEFAULT_DAYS;
try {
    $pdo=db();
    // guard tables
    foreach(['occurrence_history','load_log'] as $t){
        if(!$pdo->query("SHOW TABLES LIKE ".$pdo->quote($t))->fetch()){
            fwrite(STDERR,"Table '$t' missing. Run schema.sql first.\n"); exit(1);
        }
    }
    // run-log table (ETL-owned bookkeeping; created here as it's not core schema)
    $pdo->exec("CREATE TABLE IF NOT EXISTS etl_runs (
        id INT AUTO_INCREMENT PRIMARY KEY, run_at DATETIME NOT NULL,
        fetched INT, new_records INT, enriched INT, total_records INT, csv_rows INT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if(arg('cadence')){ cadence_report($pdo); exit; }

    echo "MAZA ETL — ".gmdate('Y-m-d H:i')." UTC\n";
    $stats=['fetched'=>0,'new'=>0];
    if(!arg('no-fetch')){
        echo "Extract + load (GBIF -> MySQL):\n";
        $stats=extract_load($pdo,$SPECIES,$days);
    } else { echo "Skipping GBIF fetch (--no-fetch).\n"; }

    echo "Enrich (in MySQL):\n";
    $enriched=enrich($pdo,$PA_FILE,$POP_FILE);

    echo "Export CSV (published read-copy):\n";
    $csvRows=export_csv($pdo,$CSV_OUT);
    echo "  wrote ".basename($CSV_OUT)." ($csvRows rows)\n";

    $total=(int)$pdo->query("SELECT COUNT(*) FROM occurrence_history")->fetchColumn();
    $pdo->prepare("INSERT INTO etl_runs (run_at,fetched,new_records,enriched,total_records,csv_rows) VALUES (NOW(),?,?,?,?,?)")
        ->execute([$stats['fetched'],$stats['new'],$enriched,$total,$csvRows]);

    echo "\nDone. fetched={$stats['fetched']} new={$stats['new']} enriched=$enriched total=$total csv=$csvRows\n";
    cadence_report($pdo);
} catch(Throwable $e){ fwrite(STDERR,"ETL error: ".$e->getMessage()."\n"); exit(1); }
