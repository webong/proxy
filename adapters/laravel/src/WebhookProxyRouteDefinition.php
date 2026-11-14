<?php

declare(strict_types=1);

namespace Webong\Proxy;

interface WebhookProxyRouteDefinition
{
    public const string CONTEXT_KEY = 'webhook_route_context';

    public function uri(): string;
}
