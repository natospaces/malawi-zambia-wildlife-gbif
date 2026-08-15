# MAZA Occurrence Viewer

An open-data pipeline and lightweight web map for species-occurrence records
along the Malawi–Zambia transfrontier landscape. It pulls records from
[GBIF](https://www.gbif.org/), makes **MySQL** the system of record, enriches
each row against population, protected-area and settlement references, and
publishes a cached **CSV** that a small Leaflet map reads — all designed to run
on **PHP 7.3 shared hosting**.

> **Scope.** This is a data-engineering project, not a human-wildlife-conflict
> study. It assembles open data into a clean, documented, queryable layer — a
> basic input specialists could use, with its limits stated plainly. A dot on
> the map is a *record*, not an animal or a census; blank space means "no
> record", never "no animals". See [`public/about.html`](public/about.html).

---

## Architecture

![Architecture](docs/diagrams/architecture.png)

The core decision is splitting the **write path** from the **read path**:

- **`etl.php`** (cron) does the slow work — extract from GBIF, upsert into
  MySQL, enrich in-database, export a flat CSV. GBIF is queried on a schedule,
  never per visitor.
- **`api.php`** (web) only ever reads the published CSV. No database connection,
  no external dependency, no per-visitor API traffic at page load.

MySQL stays the system of record because enrichment, deduplication and history
are relational problems. The CSV is a projection of that state, regenerated each
run, never edited by hand.

See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for a stage-by-stage
walkthrough.

---

## Data model

![Data model](docs/diagrams/data-model.png)

Three tables: `occurrence_history` (the core, 40 columns grouped by what they
tell a reader about a record), plus `load_log` and `etl_runs` (bookkeeping the
ETL writes). Full column reference in [`docs/SCHEMA.md`](docs/SCHEMA.md).

A record is deliberately **not** treated as one animal: `individual_count` can
be a herd of forty, `basis_of_record` distinguishes a live sighting from a
museum specimen, and `occurrence_status` can record an absence. These columns
are what let that distinction survive into the map.

---

## Repository layout

```
.
├── README.md
├── LICENSE
├── .gitignore
├── maza_config.example.php      # copy to ../maza_config.php with real creds
│
├── pipeline/                    # the ETL and offline data builders
│   ├── schema.sql               # owns ALL table creation (drop + recreate)
│   ├── etl.php                  # extract → load → enrich → export CSV
│   ├── explore.php              # reads the data, prints mapping implications
│   ├── explore.sql              # raw exploratory queries
│   ├── load_history.php         # standalone loader (subset of etl.php)
│   ├── build_settlements.py     # OSM named places → settlements.json
│   ├── build_protected_areas.py # OSM reserves → protected_areas.geojson
│   ├── build_borders.py         # geoBoundaries → borders.geojson
│   └── build_population.py      # WorldPop raster → population.json
│
├── public/                      # everything served by the web host
│   ├── index.html               # the Leaflet map
│   ├── about.html               # plain-language scope note
│   ├── api.php                  # reads the CSV, returns JSON
│   └── assets/                  # vendored Leaflet + markercluster
│
└── docs/
    ├── ARCHITECTURE.md
    ├── SCHEMA.md
    └── diagrams/
        ├── architecture.svg
        └── data-model.svg
```

---

## Quick start

### 1. Requirements

- PHP 7.3+ with PDO MySQL (the code targets 7.3; newer works too)
- MySQL / MariaDB
- Python 3.8+ for the reference builders (`pip install requests shapely rasterio`)
- A web host that can serve `public/` and run PHP on cron

### 2. Credentials

```bash
cp maza_config.example.php ../maza_config.php   # keep it ABOVE the web root
# edit ../maza_config.php with your database credentials
```

`etl.php` reads this file and validates it, reporting the exact missing key
rather than failing deep in a connection call. The real `maza_config.php` is
git-ignored and must never be committed.

### 3. Create the database objects

```bash
mysql -u USER -p DBNAME < pipeline/schema.sql
```

`schema.sql` owns all table creation (drop-if-exists, then create). The PHP
never issues DDL — it checks the tables exist and stops with a clear message if
they don't.

### 4. Build the reference layers (offline, open network)

```bash
cd pipeline
python build_settlements.py        # → settlements.json
python build_protected_areas.py    # → protected_areas.geojson
python build_borders.py            # → borders.geojson  + provinces.geojson
python build_population.py         # → population.json
```

Upload the produced files into `public/` beside `index.html`.

### 5. Run the ETL

```bash
php pipeline/etl.php --days=3000    # extract → load → enrich → publish CSV
```

This writes `public/occurrence_history.csv` — the file the map reads.

### 6. Read what the data implies for the map

```bash
php pipeline/explore.php report.txt
```

`explore.php` turns the raw numbers into plain-text readings and mapping
implications (herds hidden in single dots, specimen vs observation mix, location
precision, present-vs-absent, and so on).

### 7. Schedule it, then let it calibrate

Add `etl.php` to cron, then after a few runs:

```bash
php pipeline/etl.php --cadence
```

The ETL logs each run's change-volume and reads that history back to recommend
how often running is actually worthwhile — instead of guessing a schedule.

---

## Data sources

All open and redistributable:

| Source | Provides | Licence |
| --- | --- | --- |
| [GBIF](https://www.gbif.org/) | species occurrence records | per-dataset (cite publishers) |
| [WorldPop](https://www.worldpop.org/) | 100 m gridded population | CC-BY 4.0 |
| [OpenStreetMap](https://www.openstreetmap.org/) | protected areas, settlements | ODbL |
| [geoBoundaries](https://www.geoboundaries.org/) | national + provincial outlines | CC-BY 4.0 |

---

## Design notes

A few decisions worth calling out, documented in full in
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md):

- **Per-species trailing window.** Each species is pulled relative to its own
  most recent sighting, not a fixed calendar range, because they don't record on
  the same schedule. The anchor dates are logged so their synchronisation is
  itself observable.
- **Nearest village, computed locally.** Rather than per-point reverse
  geocoding (disallowed as systematic querying, rate-limited to 1 req/s), the
  builder fetches named place nodes once for the bounding box and the ETL
  computes the nearest one locally, labelled with distance so it never
  overstates precision.
- **PHP 7.3 target.** No arrow functions; newer constants like
  `JSON_INVALID_UTF8_SUBSTITUTE` are guarded; encoding is normalised with
  `mbstring` when present and `iconv` as a fallback, so a missing extension
  degrades gracefully instead of returning a 500.

---

## Licence

Code released under the MIT Licence — see [`LICENSE`](LICENSE). Data retrieved
through the pipeline remains under its own source licences listed above; cite
GBIF publishers and the open-data providers when using their data.
