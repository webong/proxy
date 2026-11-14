<?php

declare(strict_types=1);

namespace Webong\Proxy;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class WebProxyEndpointResolver
{
    public function __construct(
        private readonly WebProxyRegistryManager $registryManager,
        private readonly WebProxyChannelManager $channelManager,
    ) {
    }

    public function resolve(Request $request, ?string $channel = null): EndpointRecord
    {
        $resolved = $request->attributes->get('webhook_proxy_endpoint');

        if ($resolved instanceof EndpointRecord) {
            return $resolved;
        }

        $endpointKey = $request->route('endpointKey');

        if (! is_string($endpointKey) || $endpointKey === '') {
            throw new NotFoundHttpException('Web proxy endpoint not found.');
        }

        $endpoint = $this->registryManager->resolveByKey(
            $endpointKey,
            $this->channelManager->registries($channel),
        )?->endpoint->record
            ?? throw new NotFoundHttpException('Web proxy endpoint not found.');

        $request->attributes->set('webhook_proxy_endpoint', $endpoint);

        return $endpoint;
    }
}
