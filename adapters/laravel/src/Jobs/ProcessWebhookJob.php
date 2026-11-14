<?php

declare(strict_types=1);

namespace Webong\Proxy\Jobs;

use Webong\Proxy\Headers;
use Webong\Proxy\Models\WebProxyCall;
use Webong\Proxy\WebProxy;
use Webong\Proxy\WebProxyRegistryManager;
use Webong\Proxy\WebProxyChannelManager;
use RuntimeException;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob as BaseProcessWebhookJob;
use Spatie\WebhookClient\Models\WebhookCall;

class ProcessWebhookJob extends BaseProcessWebhookJob
{
    public function __construct(WebhookCall $webhookCall)
    {
        parent::__construct($webhookCall);
        $this->onQueue(config('queue.priorities.messaging'));
    }

    public function handle(
        WebProxy $webhookProxy,
        WebProxyRegistryManager $registryManager,
        ?WebProxyChannelManager $channelManager = null,
    ): void {
        $channelManager ??= app(WebProxyChannelManager::class);

        if (! $this->webhookCall instanceof WebProxyCall) {
            throw new RuntimeException('The webhook call is not a WebProxy call.');
        }

        $endpointId = $this->webhookCall->headerBag()->get(Headers::ENDPOINT);

        $resolved = $registryManager->resolveById(
            $endpointId,
            $channelManager->registries($this->webhookCall->name),
        );

        if ($resolved === null) {
            throw new RuntimeException('Webhook endpoint not found.');
        }

        $webhookCall = $this->webhookCall->setEndpointRecord($resolved->endpoint->record);
        $router = $webhookProxy->resolve($webhookCall->endpointRecord()->client);

        foreach ($router->routes($webhookCall) as $route) {
            $webhookProxy->route(
                provider: $resolved->registrar->provider(),
                endpoint: $webhookCall->endpointRecord(),
                route: $route,
                sourceUrl: $webhookCall->url,
            );
        }
    }
}
