<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Analytics\Granularity;
use App\Analytics\OverviewReader;
use App\Analytics\ReportPeriod;
use App\Models\Link;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * What the overview screen shows.
 *
 * It exists because the screen previously showed nothing: the figures were
 * literal zeroes in the markup, so an instance with thousands of clicks reported
 * none of them. A dashboard that reports a number it did not measure is worse
 * than a dashboard with no number on it.
 */
final class OverviewController
{
    private const DAYS = 30;

    private const RECENT_LINKS = 5;

    public function __invoke(Request $request, OverviewReader $reader): JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            return new JsonResponse(status: 404);
        }

        // Scoped exactly as the link list is. An account that may read only its
        // own links must not learn the instance total by reading the dashboard.
        $visible = Link::query()->visibleTo($actor);

        /** @var list<int> $linkIds */
        $linkIds = (clone $visible)->pluck('id')->map(intval(...))->values()->all();

        $timezone = $reader->timezone();
        $to = Carbon::now($timezone)->startOfHour()->addHour();
        $period = new ReportPeriod($to->clone()->subDays(self::DAYS), $to, Granularity::Day);

        $recent = (clone $visible)
            ->with('domain')
            ->latest('id')
            ->limit(self::RECENT_LINKS)
            ->get()
            ->map(fn (Link $link): array => [
                'id' => $link->public_id,
                'slug' => $link->slug,
                'destination' => $link->destination,
                'domain' => $link->domain?->host,
                'clicks' => (int) $link->click_count,
            ])
            ->values()
            ->all();

        return new JsonResponse([
            'overview' => [
                'days' => self::DAYS,
                'links_total' => count($linkIds),
                'totals' => $reader->totals($linkIds, $period),
                'daily' => $reader->daily($linkIds, $period),
                'countries' => $reader->byCountry($linkIds, $period),
                'recent_links' => $recent,
            ],
        ]);
    }
}
