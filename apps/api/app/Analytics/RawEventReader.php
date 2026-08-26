<?php

declare(strict_types=1);

namespace App\Analytics;

use App\ClickHouse\Connection;
use App\Clicks\ClickWriter;

/**
 * Individual events, for drilling into a link and for export.
 *
 * Separate from the rollup reader because this is the only path that touches raw
 * events, and it is always bounded by a page or a period. A dashboard never
 * arrives here.
 */
final class RawEventReader
{
    public const MAX_PER_PAGE = 200;

    public const MAX_EXPORT_ROWS = 100000;

    /**
     * Columns a caller may see. There is no address column to omit — the schema
     * has none — but naming the set keeps a future column from leaking by
     * default.
     *
     * @var list<string>
     */
    public const COLUMNS = [
        'click_id',
        'occurred_at',
        'is_automated',
        'automated_reason',
        'is_duplicate',
        'country_code',
        'region',
        'city',
        'asn',
        'as_organisation',
        'device_type',
        'operating_system',
        'browser',
        'referrer_host',
        'redirect_mode',
        'timezone',
        'language',
        'color_scheme',
        'connection_type',
        'dwell_ms',
    ];

    public function __construct(private readonly Connection $connection) {}

    /**
     * @return array{events: list<array<string, mixed>>, total: int}
     */
    public function page(int $linkId, int $page, int $perPage): array
    {
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));
        $offset = max(0, ($page - 1) * $perPage);

        $events = $this->connection->select(
            'SELECT '.implode(', ', self::COLUMNS).' FROM '.ClickWriter::TABLE
            .' WHERE link_id = {link:UInt64} ORDER BY occurred_at DESC, click_id DESC '
            .'LIMIT {limit:UInt32} OFFSET {offset:UInt64}',
            ['link' => $linkId, 'limit' => $perPage, 'offset' => $offset],
        );

        $total = $this->connection->select(
            'SELECT count() AS total FROM '.ClickWriter::TABLE.' WHERE link_id = {link:UInt64}',
            ['link' => $linkId],
        );

        return [
            'events' => $events,
            'total' => (int) ($total[0]['total'] ?? 0),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forExport(int $linkId, ReportPeriod $period): array
    {
        return $this->connection->select(
            'SELECT '.implode(', ', self::COLUMNS).' FROM '.ClickWriter::TABLE
            .' WHERE link_id = {link:UInt64} AND occurred_at >= {from:DateTime} AND occurred_at < {to:DateTime} '
            .'ORDER BY occurred_at LIMIT {limit:UInt32}',
            [
                'link' => $linkId,
                'from' => $period->fromUtc(),
                'to' => $period->toUtc(),
                'limit' => self::MAX_EXPORT_ROWS,
            ],
        );
    }
}
