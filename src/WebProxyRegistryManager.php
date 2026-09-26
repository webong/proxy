<?php

declare(strict_types=1);

namespace Webong\WebProxy;

use Webong\WebProxy\Contracts\EndpointRegistrar;
use Webong\WebProxy\Contracts\EndpointDriver;
use Webong\WebProxy\Contracts\EndpointProvider;
use Illuminate\Support\Manager;
use InvalidArgumentException;

class WebProxyRegistryManager extends Manager
{
    /**
     * Get the default registry (storage) name.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->get('proxy.defaults.registry', 'local');
    }

    /**
     * Return configured registry names that are available for provisioning.
     *
     * @return list<string>
     */
    public function names(bool $enabledOnly = true): array
    {
        $registries = $this->config->get('proxy.registries', []);

        if (! is_array($registries)) {
            return [];
        }

        return array_values(array_filter(
            array_keys($registries),
            static fn (int|string $name): bool => ! $enabledOnly
                || ! is_array($registries[$name] ?? null)
                || ($registries[$name]['enabled'] ?? true) !== false,
        ));
    }

    /**
     * Resolve the endpoint registrar for the given (or default) registry.
     */
    public function registry(?string $name = null): EndpointRegistrar
    {
        return $this->driver($name);
    }

    /** @param list<string> $registries */
    public function resolveByKey(string $endpointKey, array $registries): ?ResolvedEndpoint
    {
        return $this->resolveFromRegistries(
            $registries,
            fn (EndpointRegistrar $registrar): ?string => $registrar->registryForKey($endpointKey),
            fn (EndpointRegistrar $registrar): ?Endpoint => $registrar->resolveByKey($endpointKey),
        );
    }

    /** @param list<string> $registries */
    public function resolveById(string $endpointId, array $registries): ?ResolvedEndpoint
    {
        return $this->resolveFromRegistries(
            $registries,
            fn (EndpointRegistrar $registrar): ?string => $registrar->registryForId($endpointId),
            fn (EndpointRegistrar $registrar): ?Endpoint => $registrar->resolveById($endpointId),
        );
    }

    /**
     * Register a registry programmatically.
     *
     * This composes an entry under "proxy.registries" so a custom registry can
     * be declared at runtime (e.g. from a service provider), including a
     * custom driver registrar class that owns its own context handling.
     *
     * @param  array<string, mixed>  $registry
     */
    public function register(string $name, array $registry): self
    {
        $this->config->set("proxy.registries.{$name}", $registry);

        return $this;
    }

    /** @param array<string, mixed> $provider */
    public function registerProvider(string $name, array $provider): self
    {
        $this->config->set("proxy.providers.{$name}", $provider);

        return $this;
    }

    /**
     * Resolve the effective transport configuration for a registry.
     *
     * The global top-level transport defaults are merged with the given
     * registry's own options, so a registry may override any default.
     *
     * @return array<string, mixed>
     */
    public function config(?string $name = null): array
    {
        $name = $name ?? $this->getDefaultDriver();
        $defaults = $this->config->get('proxy', []);

        if (! is_array($defaults)) {
            $defaults = [];
        }

        $registry = $this->config->get("proxy.registries.{$name}", []);

        if (! is_array($registry) || $registry === []) {
            throw new InvalidArgumentException("WebProxy registry [{$name}] is not configured.");
        }

        $registry = $this->driverOptions($registry);

        return array_replace($defaults, $registry);
    }

    /** Build a registry by composing its ingress driver and storage provider. */
    protected function createDriver(mixed $name): EndpointRegistrar
    {
        $name = (string) $name;
        $registry = $this->config->get("proxy.registries.{$name}", []);

        if (! is_array($registry) || $registry === []) {
            throw new InvalidArgumentException("WebProxy registry [{$name}] is not configured.");
        }

        return new ConfiguredEndpointRegistrar(
            $this->resolveEndpointDriver($registry, $name),
            $this->resolveEndpointProvider($registry, $name),
            $this->container->make(EndpointKeyResolver::class),
        );
    }

    /** @param array<string, mixed> $registry */
    private function resolveEndpointDriver(array $registry, string $name): EndpointDriver
    {
        $driver = $registry['driver'] ?? $name;

        if ($driver === 'database') {
            return $this->container->make(DatabaseEndpointDriver::class);
        }

        if (is_string($driver) && is_a($driver, EndpointDriver::class, true)) {
            return $this->createConfigured($driver, $registry);
        }

        $driver = is_scalar($driver) ? (string) $driver : $name;

        if (isset($this->customCreators[$driver])) {
            $resolved = $this->callCustomCreator($driver);

            if ($resolved instanceof EndpointDriver) {
                return $resolved;
            }
        }

        throw new InvalidArgumentException("WebProxy registry [{$name}] uses unsupported driver [{$driver}].");
    }

    /** @param array<string, mixed> $registry */
    private function resolveEndpointProvider(array $registry, string $name): EndpointProvider
    {
        $providerName = $registry['provider'] ?? $name;

        if (! is_string($providerName) || $providerName === '') {
            throw new InvalidArgumentException("WebProxy registry [{$name}] requires a storage provider.");
        }

        $provider = $this->config->get("proxy.providers.{$providerName}", []);

        if (! is_array($provider) || $provider === []) {
            throw new InvalidArgumentException("WebProxy provider [{$providerName}] is not configured.");
        }

        $driver = $provider['driver'] ?? $providerName;

        if ($driver === 'database') {
            return $this->container->make(DatabaseEndpointProvider::class);
        }

        if (is_string($driver) && is_a($driver, EndpointProvider::class, true)) {
            return $this->createConfigured($driver, $provider);
        }

        throw new InvalidArgumentException(
            "WebProxy provider [{$providerName}] uses unsupported driver ["
                .(is_scalar($driver) ? (string) $driver : get_debug_type($driver))
                .'].',
        );
    }

    /**
     * @template TContract of object
     * @param  class-string<TContract>  $class
     * @param  array<string, mixed>  $configuration
     * @return TContract
     */
    private function createConfigured(string $class, array $configuration): object
    {
        return $this->container->make($class, [
            'options' => $this->driverOptions($configuration),
        ]);
    }

    /**
     * @param  array<string, mixed>  $registry
     * @return array<string, mixed>
     */
    private function driverOptions(array $registry): array
    {
        $options = $registry['options'] ?? $registry;
        unset($options['driver'], $options['class'], $options['provider']);

        return is_array($options) ? $options : [];
    }

    /**
     * @param  list<string>  $registries
     * @param  callable(EndpointRegistrar): ?string  $indexedRegistry
     * @param  callable(EndpointRegistrar): ?Endpoint  $resolve
     */
    private function resolveFromRegistries(
        array $registries,
        callable $indexedRegistry,
        callable $resolve,
    ): ?ResolvedEndpoint {
        $registries = array_values(array_filter(
            $registries,
            fn (string $registry): bool => $this->isEnabled($registry),
        ));

        foreach ($registries as $registry) {
            $owner = $indexedRegistry($this->registry($registry));

            if ($owner === null) {
                continue;
            }

            if (! in_array($owner, $registries, true)) {
                return null;
            }

            $registrar = $this->registry($owner);
            $endpoint = $resolve($registrar);

            return $endpoint === null
                ? null
                : new ResolvedEndpoint($owner, $registrar, $endpoint);
        }

        foreach ($registries as $registry) {
            $registrar = $this->registry($registry);
            $endpoint = $resolve($registrar);

            if ($endpoint !== null) {
                return new ResolvedEndpoint($registry, $registrar, $endpoint);
            }
        }

        return null;
    }

    private function isEnabled(string $registry): bool
    {
        $configuration = $this->config->get("proxy.registries.{$registry}");

        return is_array($configuration)
            && $configuration !== []
            && ($configuration['enabled'] ?? true) !== false;
    }
}
