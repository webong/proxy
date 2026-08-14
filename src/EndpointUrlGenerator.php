<?php

declare(strict_types=1);

namespace Webong\WebProxy;

use BackedEnum;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use RuntimeException;

class EndpointUrlGenerator
{
    public function __construct(
        private readonly EndpointUrlTemplateHooks $templateHooks,
        private readonly WebhookBaseUrlResolver $baseUrlResolver,
        private readonly WebhookProxyRouteDefinition $routeDefinition,
    ) {
    }

    /**
     * Generate the callback URL for a webhook proxy endpoint.
     */
    public function for(EndpointRecord $endpoint): string
    {
        $configuredCallback = data_get($endpoint->metadata, 'callback_url');

        if (is_string($configuredCallback) && $configuredCallback !== '') {
            return $configuredCallback;
        }

        $baseUrl = (string) Arr::get(
            $endpoint->metadata,
            'base_url',
            config('web-proxy.base_url'),
        );
        $payload = Context::getHidden(WebhookContextKeys::EXECUTION_PAYLOAD->value);
        $routeParameters = $payload instanceof WebhookExecutionContextPayload
            ? $payload->routeParameters
            : [];

        return $this->forEndpointKey(
            $endpoint->endpoint_key,
            $baseUrl,
            $routeParameters,
        );
    }

    /**
     * Build the configured callback URL for an endpoint before it is registered.
     *
     * @param array<string, string> $routeParameters
     */
    public function forEndpointKey(
        string $endpointKey,
        ?string $baseUrl = null,
        array $routeParameters = [],
    ): string {
        $baseUrl = $this->baseUrlResolver->resolve($baseUrl);
        $path = mb_trim($this->routeDefinition->uri(), '/');
        $path = str_replace('{endpointKey}', $endpointKey, $path);

        foreach ($routeParameters as $name => $value) {
            $path = str_replace(['{'.$name.'}', '{'.$name.'?}'], rawurlencode($value), $path);
        }

        $path = (string) preg_replace_callback(
            '/\/\{([a-zA-Z_][a-zA-Z0-9_]*)\?\}/',
            static fn (array $matches): string => array_key_exists($matches[1], $routeParameters) ? $matches[0] : '',
            $path,
        );

        if ($baseUrl === '' || $path === '') {
            throw new RuntimeException('The webhook proxy public URL is not configured.');
        }

        return "{$baseUrl}/{$path}";
    }

    /**
     * Build a full webhook callback URL for the given webhook receiver.
     *
     * Resolves the path template from the provided (or hidden) context first,
     * falling back to the registered receiver route URI with optional route
     * parameters stripped. The base URL is resolved from the explicit argument,
     * the context, or the configured proxy URL.
     *
     * @param  string|BackedEnum  $type
     * @param  array<string, mixed>  $context
     */
    public function path(
        string|BackedEnum $type,
        array $context = [],
        ?string $baseUrl = null,
    ): string {
        $type = is_string($type) ? $type : (string) $type->value;
        $context = array_replace([
            WebhookContextKeys::BASE_URL->value => Context::getHidden(
                WebhookContextKeys::BASE_URL->value,
                '',
            ),
            WebhookContextKeys::PATH_TEMPLATES->value => Context::getHidden(
                WebhookContextKeys::PATH_TEMPLATES->value,
                [],
            ),
        ], $context);

        $baseUrl = $this->baseUrlResolver->resolve(
            $baseUrl,
            $context[WebhookContextKeys::BASE_URL->value] ?? null,
        );

        if ($baseUrl === '') {
            throw new RuntimeException('Unable to resolve webhook base URL.');
        }

        $pathTemplate = data_get(
            $context,
            WebhookContextKeys::PATH_TEMPLATES->value.'.'.$type,
        );

        if (! is_string($pathTemplate) || $pathTemplate === '') {
            $pathTemplate = $this->webhookPathTemplate($type);
            $pathTemplate = (string) preg_replace('/\/\{[^}]+\?\}/', '', $pathTemplate);
        }

        $path = '/'.mb_trim($pathTemplate, '/');

        return mb_rtrim($baseUrl, '/').$path;
    }

    public function webhookPathTemplate(string $type): string
    {
        $config = collect(config('webhook-client.configs', []))
            ->first(fn (mixed $client): bool => is_array($client)
                && ($client['name'] ?? null) === $type);

        if (is_array($config) && is_string($config['path_template'] ?? null) && $config['path_template'] !== '') {
            return $this->templateHooks->resolve($config['path_template'], $type);
        }

        $routeUri = $this->resolveWebhookRouteUri($type);
        if (! is_null($routeUri)) {
            return $this->templateHooks->resolve($routeUri, $type);
        }

        throw new RuntimeException('Webhook callback path template is not configured.');
    }

    public function webhookRouteName(string $type): ?string
    {
        $namePrefix = "webhook-client-{$type}";
        $routeCollection = Route::getRoutes()->getRoutes();

        $route = collect($routeCollection)->first(function (RoutingRoute $route) use ($namePrefix): bool {
            $name = (string) $route->getName();

            return $name === $namePrefix || str_starts_with($name, "{$namePrefix}.");
        });

        if ($route === null) {
            return null;
        }

        return (string) $route->getName();
    }

    private function resolveWebhookRouteUri(string $type): ?string
    {
        $routeName = $this->webhookRouteName($type);
        if ($routeName === null) {
            return null;
        }

        $route = Route::getRoutes()->getByName($routeName);

        return $route instanceof RoutingRoute ? (string) $route->uri() : null;
    }
}
