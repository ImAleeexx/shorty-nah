<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Links\LinkException;
use App\Links\LinkService;
use App\Links\SlugExhaustedException;
use App\Models\LinkImport;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Creates the links a CSV asked for, one row at a time.
 *
 * Row by row rather than in one transaction, deliberately: a batch of ten
 * thousand where row 4,000 names a slug already taken should create the other
 * 9,999, not roll back the lot. Every row goes through `LinkService`, so an
 * import cannot become the way around a refusal that applies everywhere else.
 */
final class ProcessLinkImport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function __construct(public readonly string $importId) {}

    public function handle(LinkService $links): void
    {
        $import = LinkImport::query()->where('public_id', $this->importId)->first();

        if (! $import instanceof LinkImport) {
            return;
        }

        $owner = $import->creator()->first();

        if (! $owner instanceof User) {
            $import->forceFill([
                'status' => LinkImport::STATUS_FAILED,
                'failure' => 'The account that started this import no longer exists.',
                'finished_at' => now(),
            ])->save();

            return;
        }

        $domain = $import->domain()->first();

        $import->forceFill(['status' => LinkImport::STATUS_RUNNING])->save();

        $rows = $import->rows;
        $created = 0;
        $failed = 0;

        foreach ($rows as $index => $row) {
            /** @var array<string, mixed> $row */
            $input = is_array($row['input'] ?? null) ? $row['input'] : [];

            try {
                DB::transaction(function () use ($links, $input, $owner, $domain, $import, &$rows, $index): void {
                    $link = $links->create([
                        'destination' => (string) ($input['destination'] ?? ''),
                        'domain' => $domain,
                        'slug' => $this->optional($input, 'slug'),
                        'redirect_mode' => $this->optional($input, 'redirect_mode'),
                        'expires_at' => $this->optional($input, 'expires_at'),
                        'max_clicks' => isset($input['max_clicks']) && $input['max_clicks'] !== ''
                            ? (int) $input['max_clicks']
                            : null,
                        'tags' => $this->tags($input),
                        'password' => null,
                    ], $owner);

                    // A rehearsal has to exercise the same code as the real
                    // thing — slug availability, destination resolution, the lot
                    // — or it rehearses nothing. So it runs and is rolled back
                    // rather than being skipped.
                    if ($import->dry_run) {
                        throw new DryRunRollback($link->slug);
                    }

                    $rows[$index]['slug'] = $link->slug;
                });

                $rows[$index]['outcome'] = 'created';
                $created++;
            } catch (DryRunRollback $rehearsed) {
                $rows[$index]['outcome'] = 'would be created';
                $rows[$index]['slug'] = $rehearsed->slug;
                $created++;
            } catch (LinkException|SlugExhaustedException $e) {
                $rows[$index]['outcome'] = 'refused';
                $rows[$index]['reason'] = $e->getMessage();
                $failed++;
            } catch (Throwable) {
                // Recorded against the row rather than failing the batch: one
                // unexpected row must not cost the other nine thousand.
                $rows[$index]['outcome'] = 'refused';
                $rows[$index]['reason'] = 'This row could not be processed.';
                $failed++;
            }

            $import->forceFill([
                'rows' => $rows,
                'processed_rows' => $index + 1,
                'created_rows' => $created,
                'failed_rows' => $failed,
            ])->save();
        }

        $import->forceFill([
            'status' => LinkImport::STATUS_FINISHED,
            'finished_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function optional(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function tags(array $input): array
    {
        $raw = $input['tags'] ?? '';

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        // Pipe-separated, because a comma is the column separator and a tag with
        // a comma in it is a tag nobody can export.
        return array_values(array_filter(array_map(trim(...), explode('|', $raw))));
    }
}
