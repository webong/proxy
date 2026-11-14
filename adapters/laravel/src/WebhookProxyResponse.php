<?php

declare(strict_types=1);

namespace Webong\Proxy;

use Illuminate\Http\Request;
use Spatie\WebhookClient\WebhookConfig;
use Spatie\WebhookClient\WebhookResponse\RespondsToWebhook;
use Symfony\Component\HttpFoundation\Response;

class WebhookProxyResponse implements RespondsToWebhook
{
    public function __construct(
        private readonly WebProxyEndpointResolver $endpointResolver,
        private readonly EndpointWebhookConfigResolver $configResolver,
    ) {
    }

    public function respondToValidWebhook(Request $request, WebhookConfig $config): Response
    {
        $endpointConfig = $request->attributes->get(EndpointWebhookConfig::REQUEST_ATTRIBUTE);

        if (! $endpointConfig instanceof EndpointWebhookConfig) {
            $endpoint = $this->endpointResolver->resolve($request, $config->name);
            $endpointConfig = $this->configResolver->resolve($endpoint);
        }

        if ($endpointConfig->webhookResponse instanceof self) {
            throw new \LogicException('A WebProxy client cannot use the proxy webhook response.');
        }

        return $endpointConfig->webhookResponse->respondToValidWebhook($request, $endpointConfig);
    }
}
