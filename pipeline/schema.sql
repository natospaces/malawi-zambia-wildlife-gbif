-- ============================================================================
-- schema.sql  —  MAZA occurrence history database objects
--
-- Owns ALL table creation. The PHP loader creates nothing; it reads/writes only.
-- Each object is dropped if it exists, then recreated. Re-running gives a clean
-- known structure. WARNING: dropping deletes data.
--
--   mysql -u USER -p DBNAME < schema.sql
-- ============================================================================


-- ---- occurrence_history ----------------------------------------------------
-- One row per GBIF record, keyed on gbif_id (loader upsert is idempotent).
--
-- Columns are grouped by what they tell a reader about the record. A single
-- record is NOT necessarily one animal: individual_count / organism_quantity
-- say how many, occurrence_status says present-or-absent, basis_of_record says
-- seen-alive vs specimen, sampling_protocol says how it was recorded. These
-- fields are what let a non-scientist understand what a dot on the map means.

DROP TABLE IF EXISTS occurrence_history;

CREATE TABLE occurrence_history (
    -- identity
    gbif_id                 BIGINT       NOT NULL,
    occurrence_id           VARCHAR(255),          -- publisher's own record id
    species                 VARCHAR(120) NOT NULL,
    common_name             VARCHAR(120),
    scientific_name         VARCHAR(255),          -- as recorded (may differ)

    -- how many / what state  (the "what does this record mean" fields)
    individual_count        INT,                   -- e.g. 12 = a herd of 12
    organism_quantity       DOUBLE,                -- general quantity value
    organism_quantity_type  VARCHAR(60),           -- what that number means
    occurrence_status       VARCHAR(20),           -- PRESENT / ABSENT
    sex                     VARCHAR(30),
    life_stage              VARCHAR(60),
    reproductive_condition  VARCHAR(120),
    behavior                VARCHAR(255),

    -- where
    lat                     DOUBLE,
    lon                     DOUBLE,
    coordinate_uncertainty_m DOUBLE,               -- how precise the location is
    locality                VARCHAR(255),
    state_province          VARCHAR(120),
    country_code            VARCHAR(8),
    elevation_m             DOUBLE,
    depth_m                 DOUBLE,

    -- when
    event_date              DATE,
    event_year              INT,
    event_month             INT,
    event_day               INT,

    -- how it was recorded / how much to trust it
    basis_of_record         VARCHAR(40),           -- HUMAN_OBSERVATION, PRESERVED_SPECIMEN, ...
    trust_tier              VARCHAR(20),            -- observation / specimen / machine / other
    sampling_protocol       VARCHAR(255),          -- camera trap, transect, casual, ...
    recorded_by             VARCHAR(255),          -- who observed it
    identified_by           VARCHAR(255),          -- who identified the species
    establishment_means     VARCHAR(60),           -- native / introduced, if stated
    is_in_cluster           TINYINT(1),            -- GBIF flagged as clustered/duplicate-ish

    -- provenance
    dataset_key             VARCHAR(80),
    dataset_name            VARCHAR(255),
    publishing_org          VARCHAR(255),
    institution_code        VARCHAR(120),
    collection_code         VARCHAR(120),
    license                 VARCHAR(80),
    media_count             INT,                   -- how many photos/sounds attached

    loaded_at               DATETIME     NOT NULL,

    PRIMARY KEY (gbif_id),
    KEY idx_species (species),
    KEY idx_event_date (event_date),
    KEY idx_status (occurrence_status),
    KEY idx_basis (basis_of_record),
    KEY idx_protocol (sampling_protocol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---- load_log --------------------------------------------------------------
-- One row per species per run: that species' latest sighting (anchor) and the
-- trailing window loaded. Compare rows to see how synchronised recording is.

DROP TABLE IF EXISTS load_log;

CREATE TABLE load_log (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    species       VARCHAR(120),
    common_name   VARCHAR(120),
    latest_date   DATE,
    window_from   DATE,
    window_to     DATE,
    record_count  INT,
    loaded_at     DATETIME NOT NULL,
    KEY idx_species (species),
    KEY idx_latest (latest_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
