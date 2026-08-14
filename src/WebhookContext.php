<?php

declare(strict_types=1);

namespace Webong\WebProxy;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Context;
use Webong\WebProxy\Contracts\WebhookContextProvider;

/**
 * Resolves the webhook proxy context from the owner key supplied by the host
 * execution bootstrapper, scoping callbacks with the resolved hidden context.
 */
final class WebhookContext
{
    public function __construct(
        private readonly Container $container,
        private readonly WebhookBaseUrlResolver $baseUrlResolver,
        private readonly WebhookPathTemplates $pathTemplates,
        private readonly WebhookProxyRouteDefinition $routeDefinition,
    ) {
    }

    /**
     * Resolve the webhook context payload for the active execution scope.
     */
    public function payload(): WebhookExecutionContextPayload
    {
        $hidden = Context::getHidden(WebhookContextKeys::EXECUTION_PAYLOAD->value);

        if ($hidden instanceof WebhookExecutionContextPayload) {
            return $hidden;
        }

        return $this->payloadForOwner($this->ownerKey());
    }

    /**
     * Capture the hidden context array for the active execution scope.
     *
     * @return array<string, mixed>
     */
    public function capture(): array
    {
        return $this->captureFrom($this->payload());
    }

    /**
     * Persist the resolved context into Laravel's hidden context for the
     * current request/execution scope.
     *
     * Re-resolves the context from the current owner and route configuration
     * so repeated bootstraps do not reuse a stale payload.
     */
    /** @param array<string, string>|null $pathTemplates */
    public function hydrate(?string $ownerKey = null, ?array $pathTemplates = null): void
    {
        Context::addHidden($this->captureFrom($this->payloadForOwner(
            $ownerKey ?? $this->ownerKey(),
            $pathTemplates,
        )));
    }

    /**
     * Build the hidden context array from a resolved payload.
     *
     * @return array<string, mixed>
     */
    private function captureFrom(WebhookExecutionContextPayload $payload): array
    {
        return [
            WebhookContextKeys::BASE_URL->value => $payload->baseUrl,
            WebhookContextKeys::PATH_TEMPLATES->value => $payload->pathTemplates,
            WebhookProxyRouteDefinition::CONTEXT_KEY => $payload->routeContext,
            WebhookContextKeys::EXECUTION_PAYLOAD->value => $payload,
            WebhookDestinationOwner::CONTEXT_KEY => $payload->ownerId,
        ];
    }

    /** @param array<string, string>|null $pathTemplates */
    private function payloadForOwner(?string $ownerKey, ?array $pathTemplates = null): WebhookExecutionContextPayload
    {
        if ($pathTemplates === null) {
            $provider = $this->contextProvider();

            if ($provider !== null) {
                return $provider->resolve();
            }
        }

        return new WebhookExecutionContextPayload(
            ownerId: $ownerKey,
            baseUrl: $this->baseUrlResolver->resolve(),
            pathTemplates: $pathTemplates ?? $this->pathTemplates->all(),
            routeParameters: $this->routeParameters($ownerKey),
            routeContext: $ownerKey === null ? null : rawurlencode($ownerKey),
        );
    }

    private function contextProvider(): ?WebhookContextProvider
    {
        $provider = config('web-proxy.context_provider');

        if (! is_string($provider) || ! is_a($provider, WebhookContextProvider::class, true)) {
            return null;
        }

        return $this->container->make($provider);
    }

    private function ownerKey(): ?string
    {
        $ownerKey = Context::getHidden(WebhookDestinationOwner::CONTEXT_KEY);

        return is_string($ownerKey) && $ownerKey !== '' ? $ownerKey : null;
    }

    /** @return array<string, string> */
    private function routeParameters(?string $ownerKey): array
    {
        if ($ownerKey === null) {
            return [];
        }

        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\??\}/', $this->routeDefinition->uri(), $matches);
        $parameter = collect($matches[1] ?? [])->first(
            static fn (string $name): bool => $name !== 'endpointKey',
        );

        return is_string($parameter) ? [$parameter => $ownerKey] : [];
    }

    /**
     * Remove the resolved context keys from Laravel's hidden context.
     */
    public function forget(): void
    {
        $capture = $this->capture();

        foreach (array_keys($capture) as $key) {
            Context::forgetHidden($key);
        }
    }

    /**
     * Run a callback within the resolved hidden context.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function scope(Closure $callback): mixed
    {
        return Context::scope(
            callback: $callback,
            hidden: $this->capture(),
        );
    }
}
