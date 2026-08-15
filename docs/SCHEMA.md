# Schema reference

All tables are created by [`pipeline/schema.sql`](../pipeline/schema.sql), which
drops and recreates them (a clean, known structure on every run). The PHP never
issues DDL — it verifies the tables exist and stops with a clear message if they
don't.

Character set is `utf8mb4` throughout; the CSV export writes UTF-8 with a BOM so
accented names survive the round trip.

---

## `occurrence_history`

One row per GBIF record, keyed on GBIF's own `gbif_id` so the ETL upsert is
idempotent (re-loading a window updates rather than duplicates). Columns are
grouped below by what they tell a reader about the record.

**A record is not necessarily one animal.** `individual_count` may be a herd;
`basis_of_record` may be a museum specimen; `occurrence_status` may record an
absence. Reading these fields is the difference between a map that informs and
one that misleads.

### Identity

| Column | Type | Notes |
| --- | --- | --- |
| `gbif_id` | BIGINT | **Primary key.** GBIF's stable record id. |
| `occurrence_id` | VARCHAR(255) | Publisher's own record id (e.g. an iNaturalist URL). |
| `species` | VARCHAR(120) | Scientific name (canonical). |
| `common_name` | VARCHAR(120) | Human-readable name. |
| `scientific_name` | VARCHAR(255) | As recorded, may include authorship. |

### How many / what state

| Column | Type | Notes |
| --- | --- | --- |
| `individual_count` | INT | Count of individuals. `12` = a herd of twelve, not one dot. Often NULL. |
| `organism_quantity` | DOUBLE | General quantity when the count isn't simple individuals. |
| `organism_quantity_type` | VARCHAR(60) | What that number means (e.g. `individuals`, a cover scale). |
| `occurrence_status` | VARCHAR(20) | `PRESENT` / `ABSENT`. An absence is "looked here, found none". |
| `sex` | VARCHAR(30) | Where recorded. |
| `life_stage` | VARCHAR(60) | e.g. `Adult`, `Juvenile`. |
| `reproductive_condition` | VARCHAR(120) | Where recorded. |
| `behavior` | VARCHAR(255) | Where recorded. |

### Where

| Column | Type | Notes |
| --- | --- | --- |
| `lat` | DOUBLE | Decimal latitude (WGS84). |
| `lon` | DOUBLE | Decimal longitude (WGS84). |
| `coordinate_uncertainty_m` | DOUBLE | Stated location uncertainty. Some records are vague to tens of km — checked before assigning a point to one side of a boundary. |
| `locality` | VARCHAR(255) | Free-text locality, often NULL in citizen-science data. |
| `state_province` | VARCHAR(120) | Admin region — usually present but coarse. |
| `country_code` | VARCHAR(8) | ISO country code. |
| `elevation_m` | DOUBLE | Where recorded. |
| `depth_m` | DOUBLE | Where recorded. |

### When

| Column | Type | Notes |
| --- | --- | --- |
| `event_date` | DATE | Date of observation/collection. |
| `event_year` | INT | Convenience column for filtering. |
| `event_month` | INT | Used by the map's wet/dry season view. |
| `event_day` | INT | Where recorded. |

### How recorded / how much to trust it

| Column | Type | Notes |
| --- | --- | --- |
| `basis_of_record` | VARCHAR(40) | `HUMAN_OBSERVATION`, `MACHINE_OBSERVATION` (camera trap), `PRESERVED_SPECIMEN`, `MATERIAL_SAMPLE`. |
| `trust_tier` | VARCHAR(20) | Derived tier (`observation` / `machine` / `specimen` / `other`) used by the map's specimen/observation split. |
| `sampling_protocol` | VARCHAR(255) | Method — aerial survey, transect, casual sighting. |
| `recorded_by` | VARCHAR(255) | Who observed it. |
| `identified_by` | VARCHAR(255) | Who identified the species. |
| `establishment_means` | VARCHAR(60) | native / introduced, where stated. |
| `is_in_cluster` | TINYINT(1) | GBIF's own duplicate-cluster flag; surfaced as "flagged" in the map counts. |

### Provenance

| Column | Type | Notes |
| --- | --- | --- |
| `dataset_key` | VARCHAR(80) | GBIF dataset UUID. |
| `dataset_name` | VARCHAR(255) | Human-readable dataset name. |
| `publishing_org` | VARCHAR(255) | Publishing organisation key. |
| `institution_code` | VARCHAR(120) | e.g. `iNaturalist`. |
| `collection_code` | VARCHAR(120) | Where applicable. |
| `license` | VARCHAR(80) | The record's own licence. |
| `media_count` | INT | Number of photos/sounds attached — the records a viewer can verify by eye. |

### Derived in enrichment (written by `etl.php`, not from GBIF)

| Column | Type | Notes |
| --- | --- | --- |
| `in_protected_area` | VARCHAR(120) | Name of the reserve the point falls in (point-in-polygon over OSM boundaries), else NULL for community land. |
| `in_settled_area` | TINYINT(1) | 1 if the WorldPop cell is at/above the settled threshold. |
| `nearest_settlement` | VARCHAR(160) | Nearest named village + type + distance, e.g. `Chama (town, ~2 km)`. Labelled as *nearest*, not the recorded locality. |

| Housekeeping | Type | Notes |
| --- | --- | --- |
| `loaded_at` | DATETIME | Set on each upsert. |

**Indexes:** `PRIMARY (gbif_id)`, `idx_species`, `idx_event_date`,
`idx_status`, `idx_basis`, `idx_protocol`.

---

## `load_log`

One row per species per run, recording that species' latest sighting (the
**anchor**) and the trailing window loaded. Comparing rows shows how
synchronised the species' recording is.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | INT | Auto-increment primary key. |
| `species` | VARCHAR(120) | Scientific name. |
| `common_name` | VARCHAR(120) | Human-readable name. |
| `latest_date` | DATE | The anchor — this species' most recent sighting in the box. |
| `window_from` | DATE | Start of the trailing window loaded. |
| `window_to` | DATE | End of the window (= `latest_date`). |
| `record_count` | INT | Records loaded for this species this run. |
| `loaded_at` | DATETIME | Run timestamp. |

**Indexes:** `PRIMARY (id)`, `idx_species`, `idx_latest`.

---

## `etl_runs`

One row per ETL run. Read back by the cadence report (`etl.php --cadence`) to
learn how often updating is worthwhile, and doubles as an audit trail. Created
by `etl.php` on first run (it is ETL-owned bookkeeping, not core schema).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | INT | Auto-increment primary key. |
| `run_at` | DATETIME | Run timestamp. |
| `fetched` | INT | Records fetched from GBIF this run. |
| `new_records` | INT | Rows that were new (not updates). |
| `enriched` | INT | Rows re-enriched this run. |
| `total_records` | INT | Total in `occurrence_history` after the run. |
| `csv_rows` | INT | Rows written to the published CSV. |

---

## Published CSV

`occurrence_history` is exported verbatim to
`public/occurrence_history.csv` — semicolon-delimited, quoted, with literal
`NULL` for empty values, UTF-8 with BOM. This is the only data file the map
reads; `api.php` parses it and returns JSON. The export is atomic (written to a
temp file then renamed) so the map never reads a half-written file.
