<?php

declare(strict_types=1);

namespace Webong\WebProxy;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;

final class GuzzleClientFactory
{
    public function make(Endpoint $endpoint, string $profile): ClientInterface
    {
        return new Client($this->options($endpoint, $profile));
    }

    /** @return array<string, mixed> */
    public function options(Endpoint $endpoint, string $profile): array
    {
        $record = $endpoint->profile($profile);

        if ($record === null) {
            throw new InvalidArgumentException("Proxy profile [{$profile}] is not registered for endpoint [{$endpoint->record->endpoint_key}].");
        }

        if ($record->driver !== 'guzzle') {
            throw new InvalidArgumentException("Proxy profile [{$profile}] does not use the Guzzle driver.");
        }

        $configuration = $record->configuration;

        $options = $configuration['options'] ?? [];

        if (! is_array($options)) {
            throw new InvalidArgumentException("Proxy outbound profile [{$profile}] options must be an array.");
        }

        if (array_key_exists('proxy', $configuration) && $configuration['proxy'] !== null) {
            $this->validateProxy($configuration['proxy'], $profile);
            $options['proxy'] = $configuration['proxy'];
        }

        return $options;
    }

    private function validateProxy(mixed $proxy, string $profile): void
    {
        if (is_string($proxy) && $proxy !== '') {
            return;
        }

        if (! is_array($proxy) || $proxy === []) {
            throw new InvalidArgumentException("Proxy outbound profile [{$profile}] proxy must be a non-empty string or map.");
        }

        foreach ($proxy as $scheme => $value) {
            if (! is_string($scheme) || $scheme === '') {
                throw new InvalidArgumentException("Proxy outbound profile [{$profile}] proxy map keys must be strings.");
            }

            if ($scheme === 'no') {
                if (! is_array($value)) {
                    throw new InvalidArgumentException("Proxy outbound profile [{$profile}] no-proxy hosts must be non-empty strings.");
                }

                foreach ($value as $host) {
                    if (! is_string($host) || $host === '') {
                        throw new InvalidArgumentException("Proxy outbound profile [{$profile}] no-proxy hosts must be non-empty strings.");
                    }
                }

                continue;
            }

            if (! is_string($value) || $value === '') {
                throw new InvalidArgumentException("Proxy outbound profile [{$profile}] proxy map values must be non-empty strings.");
            }
        }
    }
}
