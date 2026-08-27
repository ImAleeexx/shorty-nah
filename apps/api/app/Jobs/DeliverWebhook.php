<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\WebhookSignature;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Posts one delivery, on its own queue.
 *
 * Its own queue matters: a slow or dead endpoint would otherwise sit in front of
 * mail and of everything else on `default`, and an operator's misconfigured
 * receiver would become this instance's problem.
 */
final class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 30;

    /**
     * Bounded and increasing. An endpoint that is down stays down for a while,
     * and retrying it every second helps nobody.
     *
     * @var list<int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly string $deliveryId)
    {
        // Set here rather than as a typed property: the Queueable trait already
        // declares $queue as nullable string, and redeclaring it with a
        // narrower type is a fatal composition error.
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        $delivery = WebhookDelivery::query()->where('public_id', $this->deliveryId)->first();

        if (! $delivery instanceof WebhookDelivery || $delivery->status === WebhookDelivery::STATUS_DELIVERED) {
            return;
        }

        $endpoint = $delivery->endpoint()->first();

        if (! $endpoint instanceof WebhookEndpoint || $endpoint->isDisabled()) {
            $delivery->forceFill([
                'status' => WebhookDelivery::STATUS_FAILED,
                'last_error' => 'The endpoint is disabled or no longer exists.',
            ])->save();

            return;
        }

        $secret = $this->secretFor($endpoint);

        $body = (string) json_encode([
            'event' => $delivery->event,
            'delivery' => $delivery->public_id,
            'occurred_at' => $delivery->created_at?->toIso8601String(),
            'data' => $delivery->payload,
        ]);

        $timestamp = now()->getTimestamp();

        $delivery->forceFill(['attempts' => $delivery->attempts + 1])->save();

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                WebhookSignature::TIMESTAMP_HEADER => (string) $timestamp,
                WebhookSignature::HEADER => WebhookSignature::compute($secret, $timestamp, $body),
            ])->timeout(15)->withBody($body, 'application/json')->post($endpoint->url);
        } catch (Throwable $e) {
            $this->recordFailure($delivery, null, $e->getMessage());

            throw $e;
        }

        if ($response->successful()) {
            $delivery->forceFill([
                'status' => WebhookDelivery::STATUS_DELIVERED,
                'last_status_code' => $response->status(),
                'last_error' => null,
                'delivered_at' => now(),
            ])->save();

            return;
        }

        $this->recordFailure($delivery, $response->status(), 'The endpoint answered '.$response->status().'.');

        // Thrown so the queue retries with the backoff above. Without this a
        // non-2xx would be recorded once and never tried again.
        throw new WebhookDeliveryFailed('The endpoint answered '.$response->status().'.');
    }

    public function failed(Throwable $exception): void
    {
        $delivery = WebhookDelivery::query()->where('public_id', $this->deliveryId)->first();

        if ($delivery instanceof WebhookDelivery && $delivery->status !== WebhookDelivery::STATUS_DELIVERED) {
            $delivery->forceFill([
                'status' => WebhookDelivery::STATUS_FAILED,
                'last_error' => $exception->getMessage(),
            ])->save();
        }
    }

    private function recordFailure(WebhookDelivery $delivery, ?int $status, string $error): void
    {
        $delivery->forceFill([
            'last_status_code' => $status,
            'last_error' => $error,
        ])->save();
    }

    /**
     * Decrypted here and nowhere else. It never reaches a response, a log line
     * or a payload — the interface shows it once, at creation, from the value
     * the service returned rather than from the stored column.
     */
    private function secretFor(WebhookEndpoint $endpoint): string
    {
        return $endpoint->secret;
    }
}
