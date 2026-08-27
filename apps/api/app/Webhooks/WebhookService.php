<?php

declare(strict_types=1);

namespace App\Webhooks;

use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhook;
use App\Links\DestinationValidator;
use App\Links\LinkException;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Str;

/**
 * Registering endpoints and queueing what they asked for.
 */
final class WebhookService
{
    public function __construct(private readonly DestinationValidator $destinations) {}

    /**
     * @param  list<string>  $events
     * @return array{endpoint: WebhookEndpoint, secret: string}
     */
    public function register(string $name, string $url, array $events, User $actor): array
    {
        $url = $this->validateUrl($url);
        $events = $this->validateEvents($events);

        $secret = Str::random(48);

        $endpoint = new WebhookEndpoint;
        $endpoint->forceFill([
            'name' => trim($name),
            'url' => $url,
            'events' => $events,
            'secret' => $secret,
            'created_by' => $actor->id,
        ])->save();

        return ['endpoint' => $endpoint, 'secret' => $secret];
    }

    public function rotate(WebhookEndpoint $endpoint): string
    {
        $secret = Str::random(48);

        $endpoint->forceFill(['secret' => $secret])->save();

        return $secret;
    }

    /**
     * Queues one delivery per subscribed endpoint.
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(WebhookEvent $event, array $payload): void
    {
        $endpoints = WebhookEndpoint::query()->whereNull('disabled_at')->get();

        foreach ($endpoints as $endpoint) {
            if (! $endpoint->wants($event->value)) {
                continue;
            }

            $delivery = new WebhookDelivery;
            $delivery->forceFill([
                'webhook_endpoint_id' => $endpoint->id,
                'event' => $event->value,
                'payload' => $payload,
                'status' => WebhookDelivery::STATUS_PENDING,
            ])->save();

            DeliverWebhook::dispatch($delivery->public_id);
        }
    }

    public function replay(WebhookDelivery $delivery): WebhookDelivery
    {
        // A new record rather than resetting the old one: an operator replaying
        // a delivery is asking what happens now, and overwriting the original
        // would destroy the evidence they were looking at.
        $replay = new WebhookDelivery;
        $replay->forceFill([
            'webhook_endpoint_id' => $delivery->webhook_endpoint_id,
            'event' => $delivery->event,
            'payload' => $delivery->payload,
            'status' => WebhookDelivery::STATUS_PENDING,
        ])->save();

        DeliverWebhook::dispatch($replay->public_id);

        return $replay;
    }

    /**
     * @throws LinkException
     */
    private function validateUrl(string $url): string
    {
        $url = trim($url);

        if (! str_starts_with(mb_strtolower($url), 'https://')) {
            throw new LinkException('A webhook endpoint must be an https URL.');
        }

        // The same resolution-time checks a link destination gets: loopback,
        // private, link-local, CGNAT, multicast, reserved and cloud-metadata
        // addresses are all refused after DNS resolution rather than on the
        // literal string. An endpoint is a URL this instance will fetch on a
        // schedule nobody watches, which is the definition of an SSRF target.
        return $this->destinations->validate($url);
    }

    /**
     * @param  list<string>  $events
     * @return list<string>
     *
     * @throws LinkException
     */
    private function validateEvents(array $events): array
    {
        $valid = WebhookEvent::values();
        $chosen = [];

        foreach ($events as $event) {
            if (! in_array($event, $valid, true)) {
                throw new LinkException('Choose events from: '.implode(', ', $valid).'.');
            }

            $chosen[] = $event;
        }

        $chosen = array_values(array_unique($chosen));

        if ($chosen === []) {
            throw new LinkException('An endpoint needs at least one event.');
        }

        return $chosen;
    }
}
