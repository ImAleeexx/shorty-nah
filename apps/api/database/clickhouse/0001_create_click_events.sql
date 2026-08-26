-- Raw click events.
--
-- Ordered by (link_id, occurred_at) because every report is scoped to a link over
-- a time range; that one ordering key serves them all.
--
-- Carries no network address. Geography and network are resolved during
-- enrichment and the address is discarded, so a dump of this table cannot be
-- turned back into a list of who visited.
CREATE TABLE IF NOT EXISTS click_events
(
    click_id            String,
    link_id             UInt64,
    domain_id           UInt64,
    occurred_at         DateTime,

    -- Derived from address, user agent and a rotating salt. Not reversible, and
    -- not comparable across salt rotations.
    visitor_hash        FixedString(64),

    -- Classification rather than deletion: automated traffic is excluded from
    -- reported counts but stays queryable, so a misclassification can be revised
    -- instead of having silently destroyed the data.
    is_automated        UInt8 DEFAULT 0,
    automated_reason    LowCardinality(String) DEFAULT '',
    is_duplicate        UInt8 DEFAULT 0,

    country_code        LowCardinality(String) DEFAULT '',
    region              String DEFAULT '',
    city                String DEFAULT '',
    asn                 UInt32 DEFAULT 0,
    as_organisation     String DEFAULT '',

    device_type         LowCardinality(String) DEFAULT '',
    operating_system    LowCardinality(String) DEFAULT '',
    browser             LowCardinality(String) DEFAULT '',

    referrer_host       String DEFAULT '',
    redirect_mode       LowCardinality(String) DEFAULT '',

    -- Reported by the interstitial beacon; absent for direct redirects.
    viewport_width      UInt16 DEFAULT 0,
    viewport_height     UInt16 DEFAULT 0,
    screen_width        UInt16 DEFAULT 0,
    screen_height       UInt16 DEFAULT 0,
    device_pixel_ratio  Float32 DEFAULT 0,
    timezone            LowCardinality(String) DEFAULT '',
    language            LowCardinality(String) DEFAULT '',
    color_scheme        LowCardinality(String) DEFAULT '',
    connection_type     LowCardinality(String) DEFAULT '',
    dwell_ms            UInt32 DEFAULT 0
)
ENGINE = MergeTree
PARTITION BY toYYYYMM(occurred_at)
ORDER BY (link_id, occurred_at)
SETTINGS index_granularity = 8192
