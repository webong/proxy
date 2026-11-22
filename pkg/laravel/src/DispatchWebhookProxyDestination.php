<?php

declare(strict_types=1);

namespace Webong\Proxy;

use Webong\Proxy\Contracts\WebhookEvent;
use Webong\Proxy\Contracts\WebhookJob;
use Webong\Proxy\Contracts\EndpointProvider;
use Webong\Proxy\Enums\DestinationTargetType;
use Webong\Proxy\Jobs\ForwardWebhookJob;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use RuntimeException;
use Throwable;

class DispatchWebhookProxyDestination
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly WebhookTargetRegistry $targets,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function handle(
        EndpointProvider $provider,
        DestinationRecord $destination,
        string $sourceUrl,
        array $payload,
        array $headers = [],
        ?string $deliveryId = null,
    ): bool {
        $delivery = WebhookProxyDelivery::fromDestination(
            $destination,
            $sourceUrl,
            $payload,
            $headers,
            $deliveryId,
        );

        $cacheKey = 'web-proxy:delivery:'.$delivery->id;
        $ttl = max(1, (int) config('proxy.idempotency_ttl', 86400));

        if (! $this->cache->add($cacheKey, true, $ttl)) {
            return false;
        }

        try {
            $provider->run(
                function () use ($destination, $delivery): void {
                    $this->dispatch($destination, $delivery);
                },
                $delivery->context,
            );
        } catch (Throwable $exception) {
            $this->cache->forget($cacheKey);

            $provider->updateDestination($destination, [
                'last_failed_at' => now(),
                'last_error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        if ($destination->target_type !== DestinationTargetType::REQUEST->value) {
            $provider->updateDestination($destination, [
                'last_delivered_at' => now(),
                'last_error' => null,
            ]);
        }

        return true;
    }

    private function dispatch(
        DestinationRecord $destination,
        WebhookProxyDelivery $delivery,
    ): void {
        match ($destination->target_type) {
            DestinationTargetType::REQUEST->value => $this->dispatchRequest($delivery),
            DestinationTargetType::EVENT->value => $this->dispatchEvent($destination->target, $delivery),
            DestinationTargetType::JOB->value => $this->dispatchJob($destination->target, $delivery),
        };
    }

    private function dispatchRequest(WebhookProxyDelivery $delivery): void
    {
        ForwardWebhookJob::dispatch(
            destinationId: $delivery->destinationId,
            sourceUrl: $delivery->sourceUrl,
            payload: $delivery->payload,
            headers: $delivery->headers,
            deliveryId: $delivery->id,
            context: $delivery->context,
        );
    }

    private function dispatchEvent(string $target, WebhookProxyDelivery $delivery): void
    {
        $resolved = $this->targets->resolve(DestinationTargetType::EVENT, $target);

        if (! is_a($resolved, WebhookEvent::class, true)) {
            throw new RuntimeException("Web proxy event [{$target}] is invalid.");
        }

        event($resolved::fromWebhookProxy($delivery));
    }

    private function dispatchJob(string $target, WebhookProxyDelivery $delivery): void
    {
        $resolved = $this->targets->resolve(DestinationTargetType::JOB, $target);

        if (! is_a($resolved, WebhookJob::class, true)) {
            throw new RuntimeException("Web proxy job target [{$target}] is invalid.");
        }

        dispatch($resolved::fromWebhookProxy($delivery));
    }

}
