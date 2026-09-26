<?php

declare(strict_types=1);

namespace Webong\WebProxy;

use Illuminate\Support\Manager;
use InvalidArgumentException;

class WebProxyChannelManager extends Manager
{
    /**
     * Get the default channel (protocol / transport engine) name.
     */
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('proxy.defaults.channel', 'default');
    }

    /**
     * Resolve the configuration for a given channel.
     *
     * @return array<string, mixed>
     */
    public function config(?string $name = null): array
    {
        return $this->resolve($name ?? $this->getDefaultDriver());
    }

    /** @return list<string> */
    public function registries(?string $name = null): array
    {
        $registries = $this->config($name)['registries'] ?? null;

        if ($registries === null) {
            return [(string) $this->config->get('proxy.defaults.registry', 'local')];
        }

        if (! is_array($registries)) {
            throw new InvalidArgumentException('WebProxy channel registries must be an array.');
        }

        $registries = array_values(array_unique(array_filter(
            $registries,
            static fn (mixed $registry): bool => is_string($registry) && $registry !== '',
        )));

        if ($registries === []) {
            throw new InvalidArgumentException('WebProxy channels require at least one registry.');
        }

        return $registries;
    }

    /**
     * Resolve a channel configuration by name from the registered list.
     *
     * @return array<string, mixed>
     */
    private function resolve(string $name): array
    {
        foreach ($this->all() as $channel) {
            if (($channel['name'] ?? null) === $name) {
                return $channel;
            }
        }

        throw new InvalidArgumentException("WebProxy channel [{$name}] is not configured.");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function all(): array
    {
        $channels = $this->config->get('proxy.channels', []);

        if (! is_array($channels)) {
            throw new InvalidArgumentException('The web proxy channels configuration is invalid.');
        }

        return array_values(array_filter(
            $channels,
            static fn (mixed $channel): bool => is_array($channel),
        ));
    }
}
