<?php

declare(strict_types=1);

namespace Webong\WebProxy;

use Webong\WebProxy\Contracts\WebhookEvent;
use Webong\WebProxy\Contracts\WebhookJob;
use Webong\WebProxy\Contracts\EndpointProvider;
use Webong\WebProxy\Enums\WebhookProxyTargetType;
use Webong\WebProxy\Jobs\ForwardWebhookJob;
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

        if ($destination->target_type !== WebhookProxyTargetType::REQUEST) {
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
            WebhookProxyTargetType::REQUEST => $this->dispatchRequest($delivery),
            WebhookProxyTargetType::EVENT => $this->dispatchEvent($destination->target, $delivery),
            WebhookProxyTargetType::JOB => $this->dispatchJob($destination->target, $delivery),
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
        $resolved = $this->targets->resolve(WebhookProxyTargetType::EVENT, $target);

        if (! is_a($resolved, WebhookEvent::class, true)) {
            throw new RuntimeException("Web proxy event [{$target}] is invalid.");
        }

        event($resolved::fromWebhookProxy($delivery));
    }

    private function dispatchJob(string $target, WebhookProxyDelivery $delivery): void
    {
        $resolved = $this->targets->resolve(WebhookProxyTargetType::JOB, $target);

        if (! is_a($resolved, WebhookJob::class, true)) {
            throw new RuntimeException("Web proxy job target [{$target}] is invalid.");
        }

        dispatch($resolved::fromWebhookProxy($delivery));
    }

}
