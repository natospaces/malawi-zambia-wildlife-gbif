# Architecture

![Architecture](diagrams/architecture.png)

The pipeline has five stages. The database is the system of record; the CSV is a
derived, published read-copy. The map never calls GBIF and never touches MySQL
at page load.

---

## Why write and read paths are split

GBIF is rate-limited and occasionally slow. A map that called it on every page
load would be fragile and discourteous to a shared public API. So the ETL does
the slow work on a schedule and leaves behind a flat CSV the map serves in a
single file read — no database connection, no external dependency, no
per-visitor API traffic.

MySQL stays in the loop rather than being skipped because enrichment,
deduplication and history are relational problems. The CSV is a projection of
that state, regenerated each run, never edited by hand.

---

## 1. Extract — a self-adjusting window

`etl.php` pulls each species relative to its **own** most recent sighting, not a
fixed calendar range, because the species don't record on the same schedule.

For each taxon it finds the latest `eventDate` in the MAZA bounding box (via
GBIF's year facet, then the newest record inside that year), then requests
everything in a trailing window back from that date (`--days`, default 3000).

The anchor date per species is written to `load_log`, so the five can be
compared afterward — which is itself a finding: whether the species' recording
lines up or scatters. In practice their latest sightings cluster within weeks of
each other, so recording turns out well synchronised.

## 2. Load — idempotent upsert

Records are upserted into `occurrence_history` keyed on `gbif_id`, so re-running
a window updates rows rather than duplicating them. All 40 columns are mapped
from the GBIF record in one prepared statement.

## 3. Enrich — computed once, in the database

Everything the map would otherwise recompute per page load is resolved once here
and written back to MySQL:

- **`in_protected_area`** — each coordinate is tested against OSM reserve
  boundaries by ray casting (point-in-polygon), tagging the reserve it falls in
  or flagging community land.
- **`in_settled_area`** — WorldPop population aggregated to a coarse grid; a cell
  at/above the settled threshold marks proximity to people.
- **`nearest_settlement`** — named OSM place nodes are fetched once for the box
  (`build_settlements.py`); each record is labelled with its closest settlement
  and the distance, so it never overstates precision (`Chama (town, ~2 km)`).

Enrichment columns are added by `etl.php` if absent — they are enrichment
metadata, not core schema, so this is safe to auto-add.

## 4. Export — the published read-copy

The enriched table is written to `occurrence_history.csv.tmp` then renamed over
the live CSV, so the map never reads a half-written file. `SET NAMES utf8mb4`
plus a UTF-8 BOM keep accented names intact across the round trip; each value is
re-checked and, if a stray byte slipped in, cleaned with `mbstring` or `iconv`.

## 5. Serve — read-only web tier

`api.php` reads the CSV and returns the JSON the map's JavaScript consumes
(`?q=meta`, `?q=counts`, `?q=points`). It never opens a database connection and
never calls GBIF. It normalises the file's encoding defensively (BOM strip,
`mbstring`-or-`iconv`) and routes all output through a `json_encode` wrapper that
substitutes bad bytes rather than returning an empty 500.

---

## Reference builders

Four Python scripts produce the open-data reference layers that feed **both**
the ETL enrichment and the map's visual layers:

| Script | Output | Used for |
| --- | --- | --- |
| `build_settlements.py` | `settlements.json` | nearest-village enrichment; settlement huts on the map |
| `build_protected_areas.py` | `protected_areas.geojson` | in-park enrichment; reserve outlines on the map |
| `build_borders.py` | `borders.geojson`, `provinces.geojson` | national border + province lines on the map |
| `build_population.py` | `population.json` | settled-area enrichment; population grid on the map |

They run offline (open network) and their outputs are uploaded into `public/`.
If a reference file is missing, the ETL skips that enrichment dimension
gracefully rather than failing.

---

## Self-calibrating cadence

`etl.php` logs each run to `etl_runs` (fetched, new rows, enriched, totals). The
`--cadence` report reads that history, computes the rate of genuinely new
records, and recommends a frequency — monthly, weekly, or daily — flagging when
recent runs have added nothing (a sign of running too often). The schedule is
learned from the data instead of guessed up front.

---

## Failure modes handled

- **Missing/broken config** — `etl.php` validates the config file and reports
  the exact missing key, rather than silently falling back to defaults and
  failing deep in a connection call.
- **DB connection failure** — reported with the user/db/host context (never the
  password).
- **Missing PHP extension** — encoding falls back from `mbstring` to `iconv` to
  pass-through, so a host without `mbstring` doesn't 500.
- **Older PHP** — no arrow functions; newer constants are guarded with
  `defined()`. Targets PHP 7.3.
- **Half-written CSV** — atomic temp-then-rename export.
