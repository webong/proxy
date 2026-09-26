<?php

declare(strict_types=1);

namespace Webong\WebProxy;

use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use RuntimeException;
use Webong\WebProxy\Console\Commands\DiscoverWebProxyCommand;

class WebProxyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/proxy.php', 'proxy');

        if (! $this->app->bound(WebhookProxyRouteDefinition::class)) {
            $this->app->bind(WebhookProxyRouteDefinition::class, DefinesWebhookProxyRoute::class);
        }

        $this->app->scoped(EndpointUrlTemplateHooks::class, function (): EndpointUrlTemplateHooks {
            $hooks = new EndpointUrlTemplateHooks();
            $hooks->register(static fn (string $template, string $type): string => $template);

            return $hooks;
        });
        $this->registerWebhookClientConfiguration();

        $this->app->singleton(
            WebProxy::class,
            fn (Container $container): WebProxy => new WebProxy($container),
        );

        $this->app->singleton(WebProxyChannelManager::class);
        $this->app->singleton(WebProxyRegistryManager::class);
        $this->app->singleton(GuzzleClientFactory::class);

        $this->app->singleton(WebhookPathTemplates::class);

        $this->app->scoped(WebhookContext::class);
    }

    public function boot(): void
    {
        $this->commands([DiscoverWebProxyCommand::class]);
        $this->app->booted(fn (): mixed => $this->discoverOnBoot());
        $this->registerConfiguredRouters();

        $this->publishes([
            __DIR__.'/../config/proxy.php' => config_path('proxy.php'),
        ], 'proxy-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'proxy-migrations');

        $this->publishes([
            __DIR__.'/../database/legacy-migrations/2026_07_31_135456_adopt_legacy_registry_migrations.php'
                => database_path('migrations/2026_07_31_135456_adopt_legacy_registry_migrations.php'),
        ], 'proxy-legacy-migrations');

        $this->registerProxyRouteMacro();
    }

    private function discoverOnBoot(): void
    {
        if (! (bool) config('proxy.discovery.scan_on_boot', false)
            && ! $this->app->environment(['local', 'testing'])) {
            return;
        }

        $compiledPath = base_path('bootstrap/cache/proxy.php');

        if (is_file($compiledPath)) {
            return;
        }

        app(WebhookDiscovery::class)->discover();
    }

    private function registerConfiguredRouters(): void
    {
        $routers = config('proxy.routers', []);

        if (! is_array($routers)) {
            throw new RuntimeException('The web proxy routers configuration is invalid.');
        }

        $compiledPath = base_path('bootstrap/cache/proxy.php');

        if (is_file($compiledPath)) {
            $compiled = require $compiledPath;
            $compiledRouters = is_array($compiled) ? ($compiled['routers'] ?? []) : [];

            if (is_array($compiledRouters)) {
                $routers = array_merge($routers, $compiledRouters);
            }
        }

        $webhookProxy = $this->app->make(WebProxy::class);

        foreach ($routers as $client => $router) {
            if (! is_string($client) || ! is_string($router)) {
                throw new RuntimeException('The web proxy routers configuration must map client names to router classes.');
            }

            $webhookProxy->register($client, $router);
        }
    }

    /**
     * Registers the Route::webhooks(...)->proxy(...) helper, which attaches a
     * route to a named channel so it registers with the correct webhook-client
     * and makes it respond to the channel's HTTP methods (GET + POST by default).
     *
     * The route URI is owned by the caller (via Route::webhooks(...)). When the
     * URI contains a "{template}" segment, that is where the channel's defined
     * path prefix (plus the endpoint key) is injected. Otherwise the prefix is
     * prepended to the caller-provided URI.
     */
    private function registerProxyRouteMacro(): void
    {
        RoutingRoute::macro('proxy', function (?string $channel = null) {
            $channelConfig = app(WebProxyChannelManager::class)->config($channel);

            $configName = (string) ($channelConfig['name'] ?? 'default');

            // The spatie webhooks macro already names the route
            // "webhook-client-{name}" (plus a unique token when enabled).
            // Carry the caller-provided name through as the trailing segment.
            $existingName = (string) $this->getName();
            $suffix = str_starts_with($existingName, 'webhook-client-')
                ? mb_substr($existingName, mb_strlen('webhook-client-'))
                : '';

            $name = $suffix;
            $token = '';

            if (config('webhook-client.add_unique_token_to_route_name', false) && str_contains($suffix, '.')) {
                $name = Str::beforeLast($suffix, '.');
                $token = Str::afterLast($suffix, '.');
            }

            $routeName = "webhook-client-{$configName}";

            if ($token !== '') {
                $routeName .= ".{$token}";
            }

            if ($name !== '' && $name !== $configName) {
                $routeName .= ".{$name}";
            }

            $this->action['as'] = $routeName;

            $uri = mb_trim((string) $this->uri(), '/');
            $usesTemplate = str_contains($uri, '{template}');
            $channelPath = mb_trim((string) ($channelConfig['path'] ?? '/'), '/');

            // A root proxy route is shorthand for the canonical
            // webhook proxy path. Template routes remain fully controlled by
            // their caller and do not receive this fallback.
            if (! $usesTemplate && $channelPath === '') {
                $channelPath = 'webhooks/proxy';
            }

            $prefix = implode('/', array_filter([
                $channelPath,
                '{endpointKey}',
            ], static fn (string $segment): bool => $segment !== ''));

            $proxyPath = $usesTemplate
                ? str_replace('{template}', $prefix, $uri)
                : mb_trim($prefix.'/'.$uri, '/');

            $this->setUri($proxyPath);

            $methods = $channelConfig['methods'] ?? ['GET', 'POST'];
            $methods = is_array($methods) ? $methods : ['GET', 'POST'];
            $methods = array_values(array_unique(array_map('mb_strtoupper', $methods)));

            if (in_array('GET', $methods, true) && ! in_array('HEAD', $methods, true)) {
                $methods[] = 'HEAD';
            }

            $this->methods = $methods;

            // The spatie webhooks macro registers the route up front (POST-only),
            // so by the time this macro runs the route is already in the router's
            // collection keyed under its original method and URI. Rebuilding the
            // collection re-adds this route with its final methods and URI, and
            // moves it to the end so the proxy catch-alls don't shadow more
            // specific routes registered earlier.
            $router = app('router');

            $collection = $router->getRoutes();

            $rebuilt = new \Illuminate\Routing\RouteCollection;

            foreach ($collection->getRoutes() as $registered) {
                if ($registered !== $this) {
                    $rebuilt->add($registered);
                }
            }

            $rebuilt->add($this);

            $router->setRoutes($rebuilt);

            return $this;
        });
    }

    private function registerWebhookClientConfiguration(): void
    {
        $channels = config('proxy.channels', []);

        if (! is_array($channels)) {
            throw new RuntimeException('The web proxy channels configuration is invalid.');
        }

        $webhookClientConfigs = config('webhook-client.configs', []);

        if (! is_array($webhookClientConfigs)) {
            throw new RuntimeException('The webhook client configuration is invalid.');
        }

        foreach ($channels as $channel) {
            if (! is_array($channel) || ($channel['driver'] ?? null) !== 'webhook') {
                continue;
            }

            $client = $this->buildWebhookClientConfig($channel);

            $isRegistered = collect($webhookClientConfigs)->contains(
                fn (mixed $config): bool => is_array($config)
                    && ($config['name'] ?? null) === ($client['name'] ?? null),
            );

            if (! $isRegistered) {
                $webhookClientConfigs = [$client, ...$webhookClientConfigs];
            }
        }

        config()->set('webhook-client.configs', $webhookClientConfigs);
    }

    /**
     * Build the spatie webhook-client config a webhook channel wires in.
     *
     * @param  array<string, mixed>  $channel
     * @return array<string, mixed>
     */
    private function buildWebhookClientConfig(array $channel): array
    {
        $clientName = (string) ($channel['name'] ?? 'default');

        $clientConfig = is_array($channel['client'] ?? null) ? $channel['client'] : [];

        $defaults = [
            'name' => $clientName,
            'signing_secret' => (string) config('proxy.secret', null),
            'signature_header_name' => '',
            'signature_validator' => WebhookProxySignatureValidator::class,
            'webhook_profile' => WebhookProxyProfile::class,
            'webhook_response' => WebhookProxyResponse::class,
            'webhook_model' => Models\WebProxyCall::class,
            'store_headers' => '*',
            'store_attachments' => false,
            'process_webhook_job' => Jobs\ProcessWebhookJob::class,
        ];

        $config = array_merge($defaults, $clientConfig);

        // The spatie client config name always matches the channel name, so it
        // cannot be overridden via the channel's client configuration.
        $config['name'] = $clientName;

        return $config;
    }
}
