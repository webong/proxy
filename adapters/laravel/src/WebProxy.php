<?php

declare(strict_types=1);

namespace Webong\Proxy;

use Webong\Proxy\Contracts\Router;
use Webong\Proxy\Contracts\EndpointProvider;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use LogicException;

class WebProxy
{
    /** @var array<string, class-string<Router>> */
    private array $routers = [];

    public function __construct(
        private readonly Container $container,
    ) {
    }

    public function register(string $client, string $router): void
    {
        if ($client === '' || ! is_a($router, Router::class, true)) {
            throw new InvalidArgumentException('WebProxy routers require a client name and Router class.');
        }

        if (($this->routers[$client] ?? null) === $router) {
            return;
        }

        if (isset($this->routers[$client])) {
            throw new LogicException("WebProxy router [{$client}] is already registered.");
        }

        $this->routers[$client] = $router;
    }

    public function resolve(string $client): Router
    {
        $router = $this->routers[$client] ?? null;

        if ($router === null) {
            throw new InvalidArgumentException("WebProxy router [{$client}] is not registered.");
        }

        return $this->container->make($router);
    }

    /**
     * @param  class-string<Router>  $router
     * @return list<string>
     */
    public function clients(string $router): array
    {
        $clients = array_keys(array_filter(
            $this->routers,
            fn (string $registeredRouter): bool => $registeredRouter === $router,
        ));

        if ($clients === []) {
            throw new InvalidArgumentException("WebProxy router [{$router}] is not registered.");
        }

        return $clients;
    }

    public function has(string $client): bool
    {
        return isset($this->routers[$client]);
    }

    public function forward(
        WebhookProxyDelivery $delivery,
        DestinationRecord $destination,
    ): mixed {
        return $this->container->make(WebhookForwarder::class)->forward($delivery, $destination);
    }

    public function route(
        EndpointRecord $endpoint,
        WebhookRoute $route,
        string $sourceUrl,
        ?EndpointProvider $provider = null,
    ): int {
        $provider ??= $this->container->make(WebProxyRegistryManager::class)
            ->registry()
            ->provider();

        return $this->container->make(DispatchWebhookRoute::class)->handle(
            $provider,
            $endpoint,
            $route,
            $sourceUrl,
        );
    }
}
