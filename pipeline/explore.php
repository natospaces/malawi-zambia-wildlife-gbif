<?php
/**
 * explore.php  —  reads occurrence_history and returns a PLAIN-TEXT reading of
 * what the data means for the project and for mapping decisions.
 *
 * This is not a dashboard. It runs the exploratory queries, then for each one
 * prints the numbers AND an interpretation: what the result implies about the
 * data's relevance to the human-wildlife-coexistence question, and what it
 * suggests the map should (or shouldn't) do. Thresholds turn raw figures into
 * plain statements a non-specialist can act on.
 *
 * Run on Texo:
 *   - CLI:  php explore.php
 *   - web:  place ABOVE or behind auth if the DB detail is sensitive; it only
 *           reads. Outputs text/plain either way.
 *
 * Credentials: env MAZA_DB_HOST/_USER/_PASS/_NAME or edit $DB_* below.
 * Requires the tables built by schema.sql and filled by load_history.php.
 */

$IS_CLI = (php_sapi_name() === 'cli');

// On the web, stream as plain text. On CLI, don't send HTTP headers.
if (!$IS_CLI) {
    header('Content-Type: text/plain; charset=utf-8');
}

// CLI: optional output file as first argument, e.g. `php explore.php report.txt`.
// If given, tee output to that file as well as the terminal.
$OUT_FILE = null;
if ($IS_CLI && isset($argv[1]) && $argv[1] !== '') {
    $OUT_FILE = $argv[1];
}
$__fh = $OUT_FILE ? fopen($OUT_FILE, 'w') : null;

// Central emit: everything goes through this so it reaches terminal + file.
function emit(string $s): void {
    global $__fh;
    echo $s;
    if ($__fh) fwrite($__fh, $s);
}

$DB_HOST = getenv('MAZA_DB_HOST') ?: 'localhost';
$DB_USER = getenv('MAZA_DB_USER') ?: 'maza';
$DB_PASS = getenv('MAZA_DB_PASS') ?: '';
$DB_NAME = getenv('MAZA_DB_NAME') ?: 'maza';

function db(): PDO {
    global $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME;
    return new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER, $DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
}

function rule(string $title): void {
    echo "\n" . str_repeat('=', 70) . "\n$title\n" . str_repeat('=', 70) . "\n";
}

function note(string $s): void {
    // interpretation lines, wrapped, prefixed so they read as commentary
    foreach (explode("\n", wordwrap($s, 68)) as $ln) echo "  > $ln\n";
}

function pct(int $part, int $whole): int {
    return $whole > 0 ? (int)round(100 * $part / $whole) : 0;
}

try {
    $db = db();
} catch (Throwable $e) {
    echo "Cannot connect to the database. Check credentials / that schema.sql ran.\n";
    echo $e->getMessage() . "\n";
    exit(1);
}

// Guard: tables present and non-empty.
$total = (int)$db->query("SELECT COUNT(*) FROM occurrence_history")->fetchColumn();
echo "MAZA occurrence_history — data reading for mapping\n";
echo "Generated: " . gmdate('Y-m-d H:i') . " UTC\n";
echo "Total records: $total\n";
if ($total === 0) {
    echo "\nNo records yet. Run load_history.php before reading this.\n";
    exit;
}

// ---------------------------------------------------------------------------
rule("1. Coverage per species — is each one worth mapping?");
$rows = $db->query("
    SELECT common_name, COUNT(*) records,
           MIN(event_year) first_year, MAX(event_year) last_year,
           COUNT(DISTINCT event_year) years_present
    FROM occurrence_history GROUP BY common_name ORDER BY records DESC
")->fetchAll();
foreach ($rows as $r) {
    printf("  %-18s %4d records  %s–%s  (%d years)\n",
        $r['common_name'], $r['records'], $r['first_year'], $r['last_year'], $r['years_present']);
}
$thin = array_filter($rows, function($r){ return $r['records'] < 30; });
if ($thin) {
    note("Some species are thin (<30 records): "
        . implode(', ', array_map(function($r){ return $r['common_name']; }, $thin))
        . ". On the map these should be shown but not over-read — too few points "
        . "to claim a pattern. Consider a faded or 'sparse data' treatment.");
} else {
    note("All species have enough records (30+) to appear as their own map "
        . "layer without being dismissed as noise.");
}

// ---------------------------------------------------------------------------
rule("2. Records vs individuals — are herds hidden inside single dots?");
$rows = $db->query("
    SELECT common_name, COUNT(*) records,
           SUM(COALESCE(individual_count,1)) min_individuals,
           MAX(individual_count) biggest_group,
           SUM(individual_count IS NULL) no_count
    FROM occurrence_history GROUP BY common_name ORDER BY min_individuals DESC
")->fetchAll();
$anyHerd = false;
foreach ($rows as $r) {
    $ratio = $r['records'] > 0 ? round($r['min_individuals'] / $r['records'], 1) : 1;
    printf("  %-18s %4d records  ->  %5d+ individuals  (x%.1f)  biggest group: %s\n",
        $r['common_name'], $r['records'], $r['min_individuals'], $ratio,
        $r['biggest_group'] ?: 'n/a');
    if ($r['biggest_group'] > 5) $anyHerd = true;
}
$totalNoCount = array_sum(array_map(function($r){ return (int)$r['no_count']; }, $rows));
if ($anyHerd) {
    note("At least one species records real groups (herds). A dot is therefore "
        . "NOT one animal. IMPLICATION FOR MAP: sizing a marker by individual_count "
        . "(where present) conveys more than a uniform dot — a 40-strong herd and a "
        . "lone animal should not look identical.");
}
note("$totalNoCount records have no count at all. For those the map can only "
    . "honestly say 'present', not 'how many'. The explainer must state that a "
    . "dot usually means 'seen here', not a headcount.");

// ---------------------------------------------------------------------------
rule("3. What KIND of record — live sighting, specimen, or machine?");
$rows = $db->query("
    SELECT
      SUM(basis_of_record='HUMAN_OBSERVATION')   human_obs,
      SUM(basis_of_record='MACHINE_OBSERVATION') machine_obs,
      SUM(basis_of_record='PRESERVED_SPECIMEN')  specimen,
      SUM(basis_of_record='MATERIAL_SAMPLE')     material,
      COUNT(*) total
    FROM occurrence_history
")->fetch();
printf("  human observation : %d (%d%%)\n", $rows['human_obs'], pct($rows['human_obs'],$rows['total']));
printf("  machine (camera)  : %d (%d%%)\n", $rows['machine_obs'], pct($rows['machine_obs'],$rows['total']));
printf("  preserved specimen: %d (%d%%)\n", $rows['specimen'], pct($rows['specimen'],$rows['total']));
printf("  material sample   : %d (%d%%)\n", $rows['material'], pct($rows['material'],$rows['total']));
$specPct = pct((int)$rows['specimen'] + (int)$rows['material'], (int)$rows['total']);
if ($specPct > 30) {
    note("A large share ($specPct%) are specimens/samples — physical, often older, "
        . "clustered around historic collecting. These say 'this species was here "
        . "once', not 'is here now'. MAP: keep the specimen/observation split visible "
        . "(the hollow-square vs dot distinction already does this) and lean on live "
        . "observations for any 'current presence' reading.");
} else {
    note("Most records are live observations, so the data leans toward current "
        . "presence rather than historic specimens — reasonable for a 'where are "
        . "they now' map, with the specimen minority still flagged distinctly.");
}

// ---------------------------------------------------------------------------
rule("4. How were they recorded — what produced the dot?");
$rows = $db->query("
    SELECT COALESCE(NULLIF(sampling_protocol,''),'(unstated)') protocol,
           COUNT(*) records
    FROM occurrence_history GROUP BY protocol ORDER BY records DESC LIMIT 12
")->fetchAll();
foreach ($rows as $r) printf("  %-28s %d\n", $r['protocol'], $r['records']);
$unstated = 0;
foreach ($rows as $r) if ($r['protocol'] === '(unstated)') $unstated = (int)$r['records'];
if ($unstated > $total * 0.6) {
    note("Most records don't state a method. That limits how much the map can say "
        . "about survey effort. It's an honest gap to name on the explainer, not to "
        . "paper over.");
} else {
    note("Enough records state a method (camera trap, survey, casual sighting) that "
        . "the map could, if wanted, distinguish structured surveys from opportunistic "
        . "sightings — the two mean different things for conflict interpretation.");
}

// ---------------------------------------------------------------------------
rule("5. Where the data comes from — few big surveys or many hands?");
$rows = $db->query("
    SELECT COALESCE(NULLIF(dataset_name,''),'(unnamed)') dataset, COUNT(*) records
    FROM occurrence_history GROUP BY dataset ORDER BY records DESC LIMIT 8
")->fetchAll();
foreach ($rows as $r) printf("  %-40s %d\n", substr($r['dataset'],0,40), $r['records']);
$topShare = pct((int)$rows[0]['records'], $total);
if ($topShare > 50) {
    note("Over half the records come from a single dataset ($topShare%). The picture "
        . "is shaped by one source's effort and coverage — where THEY surveyed, not "
        . "necessarily where animals are. MAP: don't present coverage as completeness.");
} else {
    note("Records are spread across several datasets, which reduces reliance on any "
        . "one survey's footprint — a somewhat more balanced basis for a presence map.");
}

// ---------------------------------------------------------------------------
rule("6. Location precision — how trustworthy is each dot's position?");
$rows = $db->query("
    SELECT ROUND(AVG(coordinate_uncertainty_m)) avg_m,
           MAX(coordinate_uncertainty_m) worst_m,
           SUM(coordinate_uncertainty_m IS NULL) no_uncert,
           COUNT(*) total
    FROM occurrence_history
")->fetch();
printf("  average stated uncertainty : %s m\n", $rows['avg_m'] ?? 'n/a');
printf("  worst stated uncertainty   : %s m\n", $rows['worst_m'] ?? 'n/a');
printf("  records with no uncertainty: %d of %d\n", $rows['no_uncert'], $rows['total']);
if (($rows['worst_m'] ?? 0) > 10000) {
    note("Some points are uncertain to >10 km. At the landscape scale that's fine for "
        . "'this area', but a dot implies more precision than exists. MAP: at high zoom, "
        . "or for the inside-park-vs-community-land call, treat very-uncertain points "
        . "cautiously — a 20 km-uncertain point can't reliably be assigned to a side.");
} else {
    note("Stated location precision is reasonable for landscape-scale mapping. Fine "
        . "distinctions (which side of a boundary) still need the uncertainty checked "
        . "per point, but there's no systemic precision problem.");
}

// ---------------------------------------------------------------------------
rule("7. Present vs absent, and demographic detail");
$rows = $db->query("
    SELECT SUM(occurrence_status='PRESENT') present,
           SUM(occurrence_status='ABSENT')  absent,
           SUM(life_stage IS NOT NULL AND life_stage<>'') has_stage,
           SUM(sex IS NOT NULL AND sex<>'') has_sex,
           COUNT(*) total
    FROM occurrence_history
")->fetch();
printf("  present: %d   absent: %d\n", $rows['present'], $rows['absent']);
printf("  with life-stage: %d   with sex: %d\n", $rows['has_stage'], $rows['has_sex']);
if ((int)$rows['absent'] > 0) {
    note("There are ABSENCE records ('looked here, found none'). These are valuable "
        . "for coexistence work — they say something the presence dots can't — and "
        . "could be a distinct, meaningful map layer.");
} else {
    note("No absence records: the data is presence-only. So the map can show where "
        . "animals WERE recorded, never where they are confirmed NOT to be. The "
        . "explainer should say blank space means 'no record', not 'no animals'.");
}

// ---------------------------------------------------------------------------
rule("8. Records with media — which dots can a non-scientist SEE?");
$rows = $db->query("
    SELECT common_name, SUM(media_count>0) with_media, COUNT(*) total
    FROM occurrence_history GROUP BY common_name ORDER BY with_media DESC
")->fetchAll();
$totalMedia = 0;
foreach ($rows as $r) {
    $totalMedia += (int)$r['with_media'];
    printf("  %-18s %d of %d have a photo/sound\n", $r['common_name'], $r['with_media'], $r['total']);
}
if ($totalMedia > 0) {
    note("$totalMedia records carry media. These are the ones a non-specialist can "
        . "actually verify with their own eyes. MAP: featuring media-backed records "
        . "(a thumbnail in the popup) makes the data tangible and trustworthy to a "
        . "general audience — directly useful for the HWC framing.");
} else {
    note("No records carry media. The map can't show photos, so trust rests on the "
        . "record's provenance instead. Worth stating plainly on the explainer.");
}

// ---------------------------------------------------------------------------
rule("Overall reading");
note("Each dot on the map is a RECORD, not an animal and not a census. What it "
    . "means depends on the fields above: how many (often unstated), what kind "
    . "(sighting vs specimen), how recorded, by whom, and how precisely located. "
    . "The map's job is to show these honestly — size by count where known, keep "
    . "the specimen/observation split, respect location uncertainty, and never let "
    . "blank space read as confirmed absence. This reading is what the public "
    . "explainer page should translate into plain language for a non-scientist "
    . "audience looking at human-wildlife coexistence.");
echo "\n";
