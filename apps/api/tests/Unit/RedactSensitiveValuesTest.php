<?php

declare(strict_types=1);

use App\Logging\RedactSensitiveValues;
use Monolog\Level;
use Monolog\LogRecord;

function record(array $context): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'testing',
        level: Level::Error,
        message: 'probe',
        context: $context,
    );
}

it('redacts secrets by key', function (string $key): void {
    $result = (new RedactSensitiveValues)(record([$key => 'the-actual-value']));

    expect($result->context[$key])->toBe(RedactSensitiveValues::REDACTED);
})->with([
    'password',
    'current_password',
    'api_key',
    'api-key',
    'authorization',
    'Cookie',
    'access_token',
    'MAXMIND_LICENSE_KEY',
    'session_id',
    'recovery_code',
    'remember_token',
]);

it('redacts network addresses', function (string $key): void {
    $result = (new RedactSensitiveValues)(record([$key => '203.0.113.7']));

    expect($result->context[$key])->toBe(RedactSensitiveValues::REDACTED);
})->with(['ip', 'ip_address', 'client_ip', 'remote_addr', 'X-Forwarded-For']);

it('redacts nested values', function (): void {
    $result = (new RedactSensitiveValues)(record([
        'request' => [
            'headers' => ['authorization' => 'Bearer abc123'],
            'path' => '/api/links',
        ],
    ]));

    expect($result->context['request']['headers']['authorization'])->toBe(RedactSensitiveValues::REDACTED)
        ->and($result->context['request']['path'])->toBe('/api/links');
});

it('leaves ordinary context intact', function (): void {
    $result = (new RedactSensitiveValues)(record(['slug' => 'abc1234', 'count' => 12]));

    expect($result->context['slug'])->toBe('abc1234')
        ->and($result->context['count'])->toBe(12);
});
