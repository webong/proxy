<?php

declare(strict_types=1);

namespace Webong\Proxy;

use Webong\Proxy\Contracts\EndpointProvider;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class DispatchWebhookRoute
{
    public function __construct(
        private readonly DispatchWebhookProxyDestination $destinationDispatcher,
    ) {
    }

    public function handle(
        EndpointProvider $provider,
        EndpointRecord $endpoint,
        WebhookRoute $route,
        string $sourceUrl,
    ): int {
        $this->validate($route);

        $destinations = $provider->destinationsFor($endpoint, $route);

        $destinations = $this->matchingMetadata($destinations, $route->destinationMetadata);

        $delivered = 0;

        foreach ($destinations as $destination) {
            $dispatched = $this->destinationDispatcher->handle(
                provider: $provider,
                destination: $destination,
                sourceUrl: $sourceUrl,
                payload: $route->payload,
                headers: $route->headers,
                deliveryId: $this->deliveryId($endpoint, $destination, $route),
            );

            $delivered += (int) $dispatched;
        }

        return $delivered;
    }

    /**
     * @param  Collection<int, DestinationRecord>  $destinations
     * @param  array<string, mixed>  $criteria
     * @return Collection<int, DestinationRecord>
     */
    private function matchingMetadata(Collection $destinations, array $criteria): Collection
    {
        if ($criteria === []) {
            return $destinations;
        }

        return $destinations
            ->filter(function (DestinationRecord $destination) use ($criteria): bool {
                foreach ($criteria as $key => $expected) {
                    if (data_get($destination->metadata, $key) !== $expected) {
                        return false;
                    }
                }

                return true;
            })
            ->values();
    }

    private function validate(WebhookRoute $route): void
    {
        if ($route->scope === '') {
            throw new InvalidArgumentException('Webhook routes require a routing scope.');
        }

        if ($route->key === '') {
            throw new InvalidArgumentException('Webhook routes require a routing key.');
        }
    }

    private function deliveryId(
        EndpointRecord $endpoint,
        DestinationRecord $destination,
        WebhookRoute $route,
    ): string {
        return hash('sha256', json_encode([
            'endpoint_id' => $endpoint->id,
            'destination_id' => $destination->id,
            'scope' => $route->scope,
            'key' => $route->key,
            'payload' => $this->canonicalize($route->payload),
        ], JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(
            fn (mixed $item): mixed => $this->canonicalize($item),
            $value,
        );
    }
}
