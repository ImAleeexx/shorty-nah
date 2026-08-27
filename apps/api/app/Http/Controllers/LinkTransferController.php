<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\DomainService;
use App\Jobs\ProcessLinkImport;
use App\Links\LinkCsv;
use App\Links\LinkException;
use App\Models\Domain;
use App\Models\Link;
use App\Models\LinkImport;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Moving links in and out in bulk.
 *
 * Export is synchronous because it is a read the requester is already waiting
 * on. Import is not: ten thousand rows either times out or holds a request open
 * for minutes, and an operator watching a progress figure is a better experience
 * than an operator watching a spinner that may already have failed.
 */
final class LinkTransferController
{
    private const MAX_ROWS = 10000;

    public function export(Request $request): SymfonyResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            return new JsonResponse(status: 404);
        }

        $query = Link::query()->with(['domain', 'tags'])->visibleTo($actor)->orderBy('id');

        $search = $request->query('search');

        if (is_string($search) && trim($search) !== '') {
            $term = '%'.mb_strtolower(trim($search)).'%';

            $query->where(function ($builder) use ($term): void {
                $builder->whereRaw('lower(slug) like ?', [$term])
                    ->orWhereRaw('lower(destination) like ?', [$term]);
            });
        }

        $domain = $request->query('domain');

        if (is_string($domain) && $domain !== '') {
            $query->whereHas('domain', fn ($builder) => $builder->where('public_id', $domain));
        }

        /** @var list<list<string>> $rows */
        $rows = $query->get()->map(fn (Link $link): array => LinkCsv::row($link))->values()->all();

        return new Response(LinkCsv::write(LinkCsv::HEADER, $rows), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="links.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function import(Request $request, DomainService $domains): JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            return new JsonResponse(status: 404);
        }

        if (! $actor->mayWrite()) {
            return new JsonResponse(['message' => 'This account cannot create links.'], 403);
        }

        $request->validate([
            'file' => ['required', 'file', 'max:5120'],
            'domain' => ['nullable', 'string', 'size:26'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => 'No file was received.']);
        }

        $domain = $this->domain($request, $domains);

        if (! $domain instanceof Domain) {
            throw ValidationException::withMessages(['domain' => 'Choose a domain for these links.']);
        }

        if (! $domain->servesLinks()) {
            throw ValidationException::withMessages(['domain' => 'That domain is not verified, so it cannot serve links yet.']);
        }

        try {
            $parsed = LinkCsv::parse((string) file_get_contents($file->getRealPath()));
        } catch (LinkException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        if ($parsed['rows'] === []) {
            throw ValidationException::withMessages(['file' => 'The file has a header but no rows.']);
        }

        if (count($parsed['rows']) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => sprintf(
                'A single import may carry at most %s rows.',
                number_format(self::MAX_ROWS),
            )]);
        }

        $import = new LinkImport;
        $import->forceFill([
            'domain_id' => $domain->id,
            'created_by' => $actor->id,
            'status' => LinkImport::STATUS_QUEUED,
            'dry_run' => $request->boolean('dry_run'),
            'total_rows' => count($parsed['rows']),
            'rows' => array_map(
                static fn (array $row): array => ['input' => $row, 'outcome' => 'pending'],
                $parsed['rows'],
            ),
        ])->save();

        ProcessLinkImport::dispatch($import->public_id);

        return new JsonResponse(['import' => $this->present($import)], 202);
    }

    public function show(Request $request, string $publicId): JsonResponse
    {
        $import = $this->findVisible($request, $publicId);

        if ($import === null) {
            return new JsonResponse(status: 404);
        }

        return new JsonResponse(['import' => $this->present($import)]);
    }

    /**
     * The submitted rows with their outcomes beside them, as a file. An operator
     * fixing a rejected import needs their own input back, not a list of errors
     * detached from the rows that caused them.
     */
    public function result(Request $request, string $publicId): SymfonyResponse
    {
        $import = $this->findVisible($request, $publicId);

        if ($import === null) {
            return new JsonResponse(status: 404);
        }

        $header = array_merge(LinkCsv::HEADER, ['outcome', 'reason']);
        $rows = [];

        foreach ($import->rows as $row) {
            /** @var array<string, mixed> $row */
            $input = is_array($row['input'] ?? null) ? $row['input'] : [];

            $line = [];

            foreach (LinkCsv::HEADER as $column) {
                $line[] = $column === 'slug' && isset($row['slug'])
                    ? (string) $row['slug']
                    : (string) ($input[$column] ?? '');
            }

            $line[] = (string) ($row['outcome'] ?? '');
            $line[] = (string) ($row['reason'] ?? '');

            $rows[] = $line;
        }

        return new Response(LinkCsv::write($header, $rows), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="import-result.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function domain(Request $request, DomainService $domains): ?Domain
    {
        $requested = $request->input('domain');

        if (is_string($requested) && $requested !== '') {
            $domain = Domain::query()->where('public_id', $requested)->first();

            return $domain instanceof Domain ? $domain : null;
        }

        return $domains->primary();
    }

    private function findVisible(Request $request, string $publicId): ?LinkImport
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            return null;
        }

        $import = LinkImport::query()->where('public_id', $publicId)->first();

        if (! $import instanceof LinkImport) {
            return null;
        }

        // An import belongs to whoever started it. An administrator sees them
        // all; anyone else sees only their own, and is told nothing about the
        // rest.
        return $actor->administrates() || $import->created_by === $actor->id ? $import : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(LinkImport $import): array
    {
        return [
            'id' => $import->public_id,
            'status' => $import->status,
            'dry_run' => $import->dry_run,
            'total' => $import->total_rows,
            'processed' => $import->processed_rows,
            'created' => $import->created_rows,
            'failed' => $import->failed_rows,
            'failure' => $import->failure,
        ];
    }
}
