<?php

declare(strict_types=1);

namespace Webong\Proxy;

final class FindEndpoint
{
    public function __construct(
        private readonly WebProxyRegistryManager $registryManager,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function managed(string $name, string $externalId, array $context = []): ?EndpointRecord
    {
        if ($name === '' || $externalId === '') {
            return null;
        }

        $registrar = $this->registryManager->registry($context['registry'] ?? null);

        return $registrar->run(
            fn (): ?EndpointRecord => $registrar->provider()->findManaged($name, $externalId),
        );
    }
}
