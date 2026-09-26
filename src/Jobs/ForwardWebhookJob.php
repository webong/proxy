<?php

declare(strict_types=1);

namespace Webong\WebProxy\Jobs;

use Webong\WebProxy\Events\WebhookDeliveryFailed;
use Webong\WebProxy\Events\WebhookDeliverySucceeded;
use Webong\WebProxy\Contracts\EndpointProvider;
use Webong\WebProxy\WebhookProxyDelivery;
use Webong\WebProxy\WebProxy;
use Webong\WebProxy\WebProxyRegistryManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ForwardWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries;

    /** @var list<int> */
    public array $backoff;

    public int $timeout;

    public readonly string $deliveryId;

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $destinationId,
        public readonly string $sourceUrl,
        public readonly array $payload,
        public readonly array $headers = [],
        ?string $deliveryId = null,
        public readonly array $context = [],
    ) {
        $this->deliveryId = $deliveryId ?? (string) Str::uuid();
        $this->tries = (int) config('proxy.tries', 3);
        $this->timeout = (int) config('proxy.job_timeout', 60);
        $backoff = config('proxy.backoff', [5, 30, 120]);
        $this->backoff = is_array($backoff)
            ? array_values(array_map('intval', $backoff))
            : ((string) $backoff === '' ? [] : [(int) $backoff]);

        $queue = config('proxy.queue_name');
        $this->onQueue(is_string($queue) && $queue !== '' ? $queue : config('queue.priorities.messaging'));
    }

    public function handle(WebProxy $webhookProxy, WebProxyRegistryManager $registryManager): void
    {
        $registrar = $registryManager->registry($this->registry($registryManager));

        $registrar->run(
            fn () => $this->deliver($webhookProxy, $registrar->provider()),
        );
    }

    private function registry(WebProxyRegistryManager $registryManager): string
    {
        $registry = $this->context['registry'] ?? null;

        return is_string($registry) && $registry !== ''
            ? $registry
            : $registryManager->getDefaultDriver();
    }

    private function deliver(WebProxy $webhookProxy, EndpointProvider $provider): void
    {
        $destination = $provider->destinationById($this->destinationId);

        if ($destination === null || $provider->resolveById($destination->endpoint_id) === null) {
            return;
        }

        $delivery = WebhookProxyDelivery::fromDestination(
            destination: $destination,
            sourceUrl: $this->sourceUrl,
            payload: $this->payload,
            headers: $this->headers,
            deliveryId: $this->deliveryId,
        );
        $webhookProxy->forward($delivery, $destination);

        $provider->updateDestination($destination, [
            'last_delivered_at' => now(),
            'last_error' => null,
        ]);

        event(new WebhookDeliverySucceeded(
            delivery: $delivery,
            target: $destination->target,
            responseStatus: 202,
        ));
    }

    public function failed(?Throwable $exception): void
    {
        $registryManager = app(WebProxyRegistryManager::class);

        $registrar = $registryManager->registry($this->registry($registryManager));

        $registrar->run(
            fn () => $this->recordFailure($registrar->provider(), $exception),
        );
    }

    private function recordFailure(EndpointProvider $provider, ?Throwable $exception): void
    {
        $destination = $provider->destinationById($this->destinationId);

        if (! $destination || ! $exception) {
            return;
        }

        $provider->updateDestination($destination, [
            'last_failed_at' => now(),
            'last_error' => $exception->getMessage(),
        ]);

        $delivery = WebhookProxyDelivery::fromDestination(
            destination: $destination,
            sourceUrl: $this->sourceUrl,
            payload: $this->payload,
            headers: $this->headers,
            deliveryId: $this->deliveryId,
        );

        if ((bool) config('proxy.log_failures', true)) {
            Log::error('Web proxy delivery failed', [
                'delivery_id' => $delivery->id,
                'endpoint_id' => $delivery->endpointId,
                'destination_id' => $delivery->destinationId,
                'target' => $destination->target,
                'error' => $exception->getMessage(),
            ]);
        }

        event(new WebhookDeliveryFailed(
            delivery: $delivery,
            target: $destination->target,
            exceptionClass: $exception::class,
            error: $exception->getMessage(),
        ));
    }
}
