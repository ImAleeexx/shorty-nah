<?php

declare(strict_types=1);

namespace App\ClickHouse;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use JsonException;
use Throwable;

/**
 * A thin client over ClickHouse's HTTP interface.
 *
 * Deliberately not a package: batch inserts and parameterised selects are a few
 * hundred lines, and an abandoned dependency in the click path would be a
 * liability we could not fix.
 *
 * Reads and writes use separate credentials, so a reporting query cannot mutate
 * the event store.
 */
final class Connection
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $password,
        private readonly string $database,
        private readonly int $timeout = 10,
    ) {}

    public function database(): string
    {
        return $this->database;
    }

    public function ping(): bool
    {
        try {
            return $this->request()->get('/ping')->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Run a statement that returns no rows.
     */
    public function statement(string $sql): void
    {
        $this->send($sql);
    }

    /**
     * Run a SELECT and return its rows.
     *
     * Bindings are sent as ClickHouse query parameters and referenced in SQL as
     * {name:Type}. Values are never interpolated into the statement.
     *
     * @param  array<string, scalar>  $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        $body = $this->send($sql.' FORMAT JSON', $bindings);

        if (trim($body) === '') {
            return [];
        }

        try {
            /** @var array{data?: list<array<string, mixed>>} $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ClickHouseException('ClickHouse returned a malformed response.', previous: $e);
        }

        return $decoded['data'] ?? [];
    }

    /**
     * Insert rows in a single request using JSONEachRow.
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @return int Rows submitted.
     */
    public function insert(string $table, iterable $rows): int
    {
        $lines = [];

        foreach ($rows as $row) {
            try {
                $lines[] = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $e) {
                throw new ClickHouseException('A click event could not be encoded for insert.', previous: $e);
            }
        }

        if ($lines === []) {
            return 0;
        }

        $this->send(
            sprintf('INSERT INTO %s FORMAT JSONEachRow', $this->qualify($table)),
            body: implode("\n", $lines),
        );

        return count($lines);
    }

    /**
     * @param  array<string, scalar>  $bindings
     */
    private function send(string $sql, array $bindings = [], ?string $body = null): string
    {
        $query = ['database' => $this->database, 'query' => $sql];

        foreach ($bindings as $name => $value) {
            $query['param_'.$name] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }

        try {
            $response = $this->request()
                ->withQueryParameters($query)
                ->withBody($body ?? '', 'text/plain')
                ->post('/');
        } catch (ConnectionException $e) {
            // A refused connection or a timeout must present as the same failure
            // type as a rejected statement. Callers already handle
            // ClickHouseException; letting a transport error escape would crash
            // the drain instead of degrading it.
            throw new ClickHouseException('ClickHouse is unreachable.', previous: $e);
        }

        if ($response->failed()) {
            // ClickHouse puts the reason in the body; the statement itself is not
            // echoed back, so this cannot leak a bound value.
            throw new ClickHouseException(sprintf(
                'ClickHouse rejected a statement with status %d: %s',
                $response->status(),
                trim($response->body()),
            ));
        }

        return $response->body();
    }

    private function request(): PendingRequest
    {
        // Failures are inspected explicitly below rather than thrown by the
        // client, so the message can be shaped without leaking the statement.
        return $this->http
            ->baseUrl($this->baseUrl)
            ->withBasicAuth($this->username, $this->password)
            ->timeout($this->timeout);
    }

    /**
     * Table names come from our own migrations, never from request input, but
     * they are still validated so a mistake fails loudly rather than becoming an
     * injection point later.
     */
    private function qualify(string $table): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new ClickHouseException("Invalid ClickHouse table name [{$table}].");
        }

        return sprintf('`%s`.`%s`', $this->database, $table);
    }
}
