<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Analytics\AnalyticsReader;
use App\Analytics\Granularity;
use App\Analytics\RawEventReader;
use App\Analytics\ReportPeriod;
use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Models\Link;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AnalyticsController
{
    public function report(Request $request, string $publicId, AnalyticsReader $reader): JsonResponse
    {
        $link = $this->visibleLink($request, $publicId);

        if ($link === null) {
            return new JsonResponse(status: 404);
        }

        $period = $this->period($request, $reader->timezone());

        return new JsonResponse([
            'link' => ['id' => $link->public_id, 'slug' => $link->slug],
            'period' => [
                'from' => $period->from->toIso8601String(),
                'to' => $period->to->toIso8601String(),
                'granularity' => $period->granularity->value,
                'timezone' => $reader->timezone(),
            ],
            // Uniques here are a single merge over the range, so this is not the
            // sum of the series below and must not be presented as one.
            'totals' => $reader->totals($link->id, $period),
            'series' => $reader->series($link->id, $period),
            'countries' => $reader->byCountry($link->id, $period),
            'referrers' => $reader->byReferrer($link->id, $period),
            'clients' => $reader->byClient($link->id, $period),
        ]);
    }

    public function events(Request $request, string $publicId, RawEventReader $reader): JsonResponse
    {
        $link = $this->visibleLink($request, $publicId);

        if ($link === null) {
            return new JsonResponse(status: 404);
        }

        /** @var array{page?: int, per_page?: int} $input */
        $input = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.RawEventReader::MAX_PER_PAGE],
        ]);

        // Query-string values arrive as strings even once validated as integers.
        $page = (int) ($input['page'] ?? 1);
        $perPage = (int) ($input['per_page'] ?? 50);

        $result = $reader->page($link->id, $page, $perPage);

        return new JsonResponse([
            'events' => $result['events'],
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $result['total']],
        ]);
    }

    public function export(Request $request, string $publicId, RawEventReader $reader, AnalyticsReader $analytics, AuditLog $audit): StreamedResponse|JsonResponse
    {
        $link = $this->visibleLink($request, $publicId);

        if ($link === null) {
            return new JsonResponse(status: 404);
        }

        $period = $this->period($request, $analytics->timezone());
        $rows = $reader->forExport($link->id, $period);

        $filename = sprintf('clicks-%s-%s.csv', $link->slug, $period->from->format('Y-m-d'));

        // Recorded before the stream begins: an export that starts is an export
        // that happened, whatever the client does with the body afterwards.
        $audit->record(
            AuditAction::AnalyticsExported,
            actor: $request->user(),
            targetType: 'link',
            targetId: $link->public_id,
            context: ['rows' => count($rows), 'from' => $period->from->toIso8601String()],
            request: $request,
        );

        return new StreamedResponse(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, RawEventReader::COLUMNS);

            foreach ($rows as $row) {
                $line = [];

                foreach (RawEventReader::COLUMNS as $column) {
                    $value = $row[$column] ?? '';
                    $line[] = is_scalar($value) ? (string) $value : '';
                }

                fputcsv($handle, $line);
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Null both for a link that does not exist and one the actor may not see, so
     * analytics cannot become a way to discover other people's links.
     */
    private function visibleLink(Request $request, string $publicId): ?Link
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            return null;
        }

        $link = Link::query()->visibleTo($actor)->where('public_id', $publicId)->first();

        return $link instanceof Link ? $link : null;
    }

    private function period(Request $request, string $timezone): ReportPeriod
    {
        /** @var array{from?: string, to?: string, granularity?: string} $input */
        $input = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'granularity' => ['nullable', 'string', 'in:hour,day,month'],
        ]);

        $to = isset($input['to']) ? Carbon::parse($input['to'], $timezone) : Carbon::now($timezone);
        $from = isset($input['from'])
            ? Carbon::parse($input['from'], $timezone)
            : $to->clone()->subDays(30);

        return new ReportPeriod(
            from: $from,
            to: $to,
            granularity: Granularity::tryFrom($input['granularity'] ?? 'day') ?? Granularity::Day,
        );
    }
}
