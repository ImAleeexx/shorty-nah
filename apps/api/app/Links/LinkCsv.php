<?php

declare(strict_types=1);

namespace App\Links;

use App\Models\Link;

/**
 * The interchange format for links.
 *
 * One documented header and nothing else — no dialect detection, no importing a
 * named competitor's export. A format that guesses is a format that guesses
 * wrong on someone's corpus and creates ten thousand links pointing at the wrong
 * column.
 *
 * An export is importable: the columns are exactly what the import reads, so
 * moving an instance is an export and an import rather than a database dump.
 */
final class LinkCsv
{
    /** @var list<string> */
    public const HEADER = [
        'slug',
        'destination',
        'redirect_mode',
        'expires_at',
        'max_clicks',
        'tags',
        'protected',
    ];

    /**
     * `destination` is the only column an import requires. Everything else has a
     * defined default, and a slug left empty is generated the way it is for a
     * link created through the interface.
     *
     * @var list<string>
     */
    public const REQUIRED = ['destination'];

    /**
     * @return list<string>
     */
    public static function row(Link $link): array
    {
        return [
            (string) $link->slug,
            (string) $link->destination,
            $link->redirect_mode instanceof \App\Enums\RedirectMode ? $link->redirect_mode->value : '',
            $link->expires_at?->toIso8601String() ?? '',
            $link->max_clicks === null ? '' : (string) $link->max_clicks,
            $link->relationLoaded('tags')
                ? $link->tags->pluck('name')->implode('|')
                : '',
            // Recorded so an operator can see which links are protected, and
            // deliberately not the password or its hash: an export is a file
            // that gets emailed around.
            $link->password_hash === null ? '' : 'yes',
        ];
    }

    /**
     * Parses an uploaded file into rows keyed by header name.
     *
     * @return array{header: list<string>, rows: list<array<string, string>>}
     *
     * @throws LinkException when the file cannot be read as the documented format
     */
    public static function parse(string $contents): array
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new LinkException('The file could not be read.');
        }

        // The byte-order mark a spreadsheet writes would otherwise become part of
        // the first header name, and the first column would silently go missing.
        fwrite($handle, preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents);
        rewind($handle);

        $header = fgetcsv($handle, escape: '');

        // fgetcsv returns [null] for a blank line, which is neither an error nor
        // a header.
        if (! is_array($header) || ($header[0] ?? null) === null) {
            fclose($handle);

            throw new LinkException('The file is empty.');
        }

        $names = array_map(
            static fn ($value): string => mb_strtolower(trim((string) $value)),
            $header,
        );

        foreach (self::REQUIRED as $required) {
            if (! in_array($required, $names, true)) {
                fclose($handle);

                throw new LinkException(sprintf(
                    'The file needs a header row naming at least %s. Recognised columns are %s.',
                    implode(' and ', self::REQUIRED),
                    implode(', ', self::HEADER),
                ));
            }
        }

        $rows = [];

        while (($line = fgetcsv($handle, escape: '')) !== false) {
            if (($line[0] ?? null) === null) {
                continue;
            }

            $row = [];

            foreach ($names as $index => $name) {
                $row[$name] = trim((string) ($line[$index] ?? ''));
            }

            // A trailing blank line in a hand-edited file is not an error.
            if (implode('', $row) === '') {
                continue;
            }

            $rows[] = $row;
        }

        fclose($handle);

        return ['header' => $names, 'rows' => $rows];
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<string>>  $rows
     */
    public static function write(array $header, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, $header, escape: '');

        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: '');
        }

        rewind($handle);
        $body = (string) stream_get_contents($handle);
        fclose($handle);

        return $body;
    }
}
