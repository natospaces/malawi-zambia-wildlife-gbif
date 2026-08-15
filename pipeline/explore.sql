-- ============================================================================
-- explore.sql  —  exploratory queries over occurrence_history
--
-- These are diagnostic, not production. Run each and read what it reveals; the
-- answers decide what's worth putting on the map. Each is labelled with the
-- question it probes. Run individually in phpMyAdmin, or all at once:
--   mysql -u USER -p DBNAME < explore.sql
-- ============================================================================


-- Q1. How much data per species, and over what span?
--     Decides: is every species worth showing, or are some too thin?
SELECT
    common_name,
    COUNT(*)                        AS records,
    MIN(event_year)                 AS first_year,
    MAX(event_year)                 AS last_year,
    COUNT(DISTINCT event_year)      AS years_present
FROM occurrence_history
GROUP BY common_name
ORDER BY records DESC;


-- Q2. Records per species per year — the trend over time.
--     Decides: is there a time story (rising/falling record) worth animating,
--     or is it flat noise? This is the core "change over time" probe.
SELECT
    event_year,
    SUM(common_name = 'African elephant')   AS elephant,
    SUM(common_name = 'Lion')               AS lion,
    SUM(common_name = 'Hippopotamus')       AS hippo,
    SUM(common_name = 'Nile crocodile')     AS crocodile,
    SUM(common_name = 'African buffalo')    AS buffalo,
    COUNT(*)                                AS all_species
FROM occurrence_history
GROUP BY event_year
ORDER BY event_year;


-- Q3. Seasonal pattern — records by month, per species.
--     Decides: is the wet/dry seasonal signal strong enough to be a map view?
--     (Complements the movement page's month chart, but per species.)
SELECT
    event_month,
    CASE WHEN event_month IN (11,12,1,2,3,4) THEN 'wet' ELSE 'dry' END AS season,
    SUM(common_name = 'African elephant')   AS elephant,
    SUM(common_name = 'Lion')               AS lion,
    SUM(common_name = 'Hippopotamus')       AS hippo,
    SUM(common_name = 'Nile crocodile')     AS crocodile,
    SUM(common_name = 'African buffalo')    AS buffalo
FROM occurrence_history
WHERE event_month IS NOT NULL
GROUP BY event_month
ORDER BY event_month;


-- Q4. Wet vs dry split per species (the season signal, summarised).
--     Decides: which species are seasonal enough that season matters for them.
SELECT
    common_name,
    SUM(event_month IN (11,12,1,2,3,4)) AS wet_records,
    SUM(event_month IN (5,6,7,8,9,10))  AS dry_records,
    ROUND(100 * SUM(event_month IN (11,12,1,2,3,4)) / NULLIF(COUNT(*),0)) AS wet_pct
FROM occurrence_history
WHERE event_month IS NOT NULL
GROUP BY common_name
ORDER BY wet_pct DESC;


-- Q5. Record type mix per species (observation vs specimen).
--     Decides: how much of each species' data is recent field observation vs
--     older museum specimen — affects how much to trust a "recent" reading.
SELECT
    common_name,
    SUM(trust_tier = 'observation') AS observations,
    SUM(trust_tier = 'specimen')    AS specimens,
    ROUND(100 * SUM(trust_tier = 'observation') / NULLIF(COUNT(*),0)) AS obs_pct
FROM occurrence_history
GROUP BY common_name
ORDER BY obs_pct DESC;


-- Q6. Rough spatial split: how records spread across the landscape by longitude
--     band (a cheap proxy for "where", pending a real spatial join).
--     Decides: is there enough spatial spread to cluster on the map, or does it
--     all pile in one place?
SELECT
    ROUND(lon, 0)          AS lon_band,
    COUNT(*)               AS records,
    COUNT(DISTINCT common_name) AS species_here
FROM occurrence_history
WHERE lon IS NOT NULL
GROUP BY ROUND(lon, 0)
ORDER BY records DESC;


-- Q7. Recent activity: records in the last 2 years per species.
--     Decides: which species have a live, current signal vs mostly historical.
SELECT
    common_name,
    SUM(event_year >= YEAR(CURDATE()) - 1) AS last_2_years,
    COUNT(*)                                AS all_time,
    ROUND(100 * SUM(event_year >= YEAR(CURDATE()) - 1) / NULLIF(COUNT(*),0)) AS recent_pct
FROM occurrence_history
GROUP BY common_name
ORDER BY recent_pct DESC;


-- ============================================================================
-- INTERPRETIVE QUERIES  —  "what does a record actually mean?"
-- Added because a single occurrence is not necessarily one animal. These probe
-- the fields that decide meaning, and feed the explainer page.
-- ============================================================================


-- Q8. Individuals vs records: does record-count understate the animals?
--     Sums individualCount where present. A big gap between records and
--     individuals means herds are hiding inside single dots.
SELECT
    common_name,
    COUNT(*)                                   AS records,
    SUM(COALESCE(individual_count, 1))         AS min_individuals,
    MAX(individual_count)                      AS biggest_group,
    ROUND(AVG(individual_count), 1)            AS avg_when_counted,
    SUM(individual_count IS NULL)              AS records_with_no_count
FROM occurrence_history
GROUP BY common_name
ORDER BY min_individuals DESC;


-- Q9. What KIND of record is each? The basis-of-record mix.
--     Decides how much is live field sighting vs museum specimen vs machine.
SELECT
    common_name,
    SUM(basis_of_record = 'HUMAN_OBSERVATION')    AS human_obs,
    SUM(basis_of_record = 'MACHINE_OBSERVATION')  AS machine_obs,
    SUM(basis_of_record = 'PRESERVED_SPECIMEN')   AS specimen,
    SUM(basis_of_record = 'MATERIAL_SAMPLE')      AS material_sample,
    SUM(basis_of_record NOT IN
        ('HUMAN_OBSERVATION','MACHINE_OBSERVATION','PRESERVED_SPECIMEN','MATERIAL_SAMPLE')
        OR basis_of_record IS NULL)               AS other
FROM occurrence_history
GROUP BY common_name;


-- Q10. How were they recorded? Sampling protocol spread.
--      Camera trap vs aerial survey vs casual sighting changes what a dot means.
SELECT
    COALESCE(NULLIF(sampling_protocol, ''), '(unstated)') AS protocol,
    COUNT(*) AS records,
    COUNT(DISTINCT common_name) AS species
FROM occurrence_history
GROUP BY protocol
ORDER BY records DESC;


-- Q11. Who recorded these? Dataset / publisher spread.
--      Shows whether the data is a few big surveys or many small contributors.
SELECT
    COALESCE(NULLIF(dataset_name, ''), '(unnamed dataset)') AS dataset,
    COUNT(*) AS records,
    COUNT(DISTINCT common_name) AS species,
    MIN(event_year) AS from_year,
    MAX(event_year) AS to_year
FROM occurrence_history
GROUP BY dataset
ORDER BY records DESC
LIMIT 20;


-- Q12. Location precision: how trustworthy is each dot's position?
--      coordinate_uncertainty_m tells you if a point is pinpoint or vague.
SELECT
    common_name,
    ROUND(AVG(coordinate_uncertainty_m)) AS avg_uncertainty_m,
    MAX(coordinate_uncertainty_m)        AS worst_uncertainty_m,
    SUM(coordinate_uncertainty_m IS NULL) AS records_no_uncertainty
FROM occurrence_history
GROUP BY common_name;


-- Q13. Present vs absent, and life-stage/sex detail where given.
--      Absence records (occurrenceStatus='ABSENT') are meaningful for HWC:
--      "looked here, found none". Also how much demographic detail exists.
SELECT
    common_name,
    SUM(occurrence_status = 'PRESENT') AS present,
    SUM(occurrence_status = 'ABSENT')  AS absent,
    SUM(life_stage IS NOT NULL AND life_stage <> '') AS has_life_stage,
    SUM(sex IS NOT NULL AND sex <> '')               AS has_sex
FROM occurrence_history
GROUP BY common_name;


-- Q14. Records that carry media (photo/sound) — the ones a non-scientist can
--      actually SEE and verify. Useful candidates to feature on the map.
SELECT
    common_name,
    SUM(media_count > 0) AS with_media,
    COUNT(*)             AS total,
    ROUND(100 * SUM(media_count > 0) / NULLIF(COUNT(*),0)) AS media_pct
FROM occurrence_history
GROUP BY common_name
ORDER BY media_pct DESC;
