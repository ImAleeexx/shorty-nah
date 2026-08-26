-- Rollups maintained on insert.
--
-- Materialized views rather than a scheduled aggregation job: a job leaves a
-- window in which reports are stale, and needs its own backfill and failure
-- handling. These update as part of the insert that produced the event.
--
-- Buckets are hourly and stored in UTC. Day and month boundaries are applied at
-- query time with the instance timezone, so changing that timezone reshapes
-- existing reports instead of requiring the rollups to be rebuilt.
--
-- No TTL here. Raw events expire under retention; these totals outlive them, so
-- a report for last year still has numbers after the events behind it are gone.

CREATE TABLE IF NOT EXISTS click_hourly
(
    link_id      UInt64,
    bucket       DateTime,
    clicks       SimpleAggregateFunction(sum, UInt64),
    counted      SimpleAggregateFunction(sum, UInt64),
    automated    SimpleAggregateFunction(sum, UInt64),
    duplicates   SimpleAggregateFunction(sum, UInt64),

    -- A merge state, not a number: unique visitors cannot be summed across
    -- buckets, and storing a per-bucket count would invite exactly that.
    visitors     AggregateFunction(uniq, FixedString(64))
)
ENGINE = AggregatingMergeTree
ORDER BY (link_id, bucket);

CREATE MATERIALIZED VIEW IF NOT EXISTS click_hourly_mv TO click_hourly AS
SELECT
    link_id,
    toStartOfHour(occurred_at) AS bucket,
    count() AS clicks,
    countIf(is_automated = 0 AND is_duplicate = 0) AS counted,
    countIf(is_automated = 1) AS automated,
    countIf(is_duplicate = 1) AS duplicates,
    uniqState(visitor_hash) AS visitors
FROM click_events
GROUP BY link_id, bucket;

CREATE TABLE IF NOT EXISTS click_by_country
(
    link_id      UInt64,
    bucket       DateTime,
    country_code LowCardinality(String),
    counted      SimpleAggregateFunction(sum, UInt64),
    visitors     AggregateFunction(uniq, FixedString(64))
)
ENGINE = AggregatingMergeTree
ORDER BY (link_id, bucket, country_code);

CREATE MATERIALIZED VIEW IF NOT EXISTS click_by_country_mv TO click_by_country AS
SELECT
    link_id,
    toStartOfHour(occurred_at) AS bucket,
    country_code,
    count() AS counted,
    uniqState(visitor_hash) AS visitors
FROM click_events
WHERE is_automated = 0 AND is_duplicate = 0
GROUP BY link_id, bucket, country_code;

CREATE TABLE IF NOT EXISTS click_by_referrer
(
    link_id       UInt64,
    bucket        DateTime,
    referrer_host String,
    counted       SimpleAggregateFunction(sum, UInt64)
)
ENGINE = AggregatingMergeTree
ORDER BY (link_id, bucket, referrer_host);

CREATE MATERIALIZED VIEW IF NOT EXISTS click_by_referrer_mv TO click_by_referrer AS
SELECT
    link_id,
    toStartOfHour(occurred_at) AS bucket,
    referrer_host,
    count() AS counted
FROM click_events
WHERE is_automated = 0 AND is_duplicate = 0
GROUP BY link_id, bucket, referrer_host;

CREATE TABLE IF NOT EXISTS click_by_client
(
    link_id          UInt64,
    bucket           DateTime,
    device_type      LowCardinality(String),
    operating_system LowCardinality(String),
    browser          LowCardinality(String),
    counted          SimpleAggregateFunction(sum, UInt64)
)
ENGINE = AggregatingMergeTree
ORDER BY (link_id, bucket, device_type, operating_system, browser);

CREATE MATERIALIZED VIEW IF NOT EXISTS click_by_client_mv TO click_by_client AS
SELECT
    link_id,
    toStartOfHour(occurred_at) AS bucket,
    device_type,
    operating_system,
    browser,
    count() AS counted
FROM click_events
WHERE is_automated = 0 AND is_duplicate = 0
GROUP BY link_id, bucket, device_type, operating_system, browser;
