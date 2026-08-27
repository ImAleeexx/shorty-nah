-- Where a click came from, when that is something other than someone following
-- a link.
--
-- Empty for an ordinary click and 'qr' for a scan. A separate column rather than
-- a referrer convention because a QR scan has no referrer at all — the camera is
-- not a page — and inventing one would put a fiction in a column operators read.
ALTER TABLE click_events
    ADD COLUMN IF NOT EXISTS source LowCardinality(String) DEFAULT '';

-- Scans counted beside the total rather than instead of it. A scan is a real
-- visit and belongs in `counted`; `scans` says how many of those arrived through
-- a code, so the two can be reported together without either inflating the
-- other.
ALTER TABLE click_hourly
    ADD COLUMN IF NOT EXISTS scans SimpleAggregateFunction(sum, UInt64) DEFAULT 0;

-- A materialized view's SELECT is fixed when it is created, so adding a column
-- to its target means replacing the view. Dropping it loses nothing: the target
-- table keeps every row already aggregated, and the replacement resumes from the
-- next insert.
DROP VIEW IF EXISTS click_hourly_mv;

CREATE MATERIALIZED VIEW IF NOT EXISTS click_hourly_mv TO click_hourly AS
SELECT
    link_id,
    toStartOfHour(occurred_at) AS bucket,
    count() AS clicks,
    countIf(is_automated = 0 AND is_duplicate = 0) AS counted,
    countIf(is_automated = 1) AS automated,
    countIf(is_duplicate = 1) AS duplicates,
    countIf(source = 'qr' AND is_automated = 0 AND is_duplicate = 0) AS scans,
    uniqState(visitor_hash) AS visitors
FROM click_events
GROUP BY link_id, bucket;
