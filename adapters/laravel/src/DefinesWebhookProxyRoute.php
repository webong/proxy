<?php

declare(strict_types=1);

namespace Webong\Proxy;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/**
 * Resolves the URI of the webhook-client route configured for a proxy channel.
 */
final class DefinesWebhookProxyRoute implements WebhookProxyRouteDefinition
{
    public function uri(): string
    {
        $channel = app(WebProxyChannelManager::class)->config();
        $routeName = 'webhook-client-'.(string) ($channel['name'] ?? 'default');
        $route = Route::getRoutes()->getByName($routeName)
            ?? collect(Route::getRoutes()->getRoutes())->first(
                static fn (RoutingRoute $candidate): bool => str_starts_with((string) $candidate->getName(), $routeName.'.'),
            );

        if (! is_object($route)) {
            throw new RuntimeException("Webhook proxy route [{$routeName}] is not registered.");
        }

        return '/'.mb_trim((string) $route->uri(), '/');
    }
}
