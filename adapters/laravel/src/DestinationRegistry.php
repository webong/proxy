<?php

declare(strict_types=1);

namespace Webong\Proxy;

class DestinationRegistry
{
    public function __construct(
        private readonly WebProxyRegistryManager $registryManager,
    ) {
    }

    /** @param array<string, mixed> $context */
    public function deactivate(
        string $ownerId,
        string $registrationId,
        string $webhookGroup,
        array $context = [],
    ): int {
        $registrar = $this->registryManager->registry($context['registry'] ?? null);

        return $registrar->run(
            fn (): int => $registrar->provider()->deactivateDestinations(
                $ownerId,
                $registrationId,
                $webhookGroup,
            ),
        );
    }

    /** @param array<string, mixed> $context */
    public function hasActive(
        string $ownerId,
        string $registrationId,
        string $webhookGroup,
        array $context = [],
    ): bool {
        $registrar = $this->registryManager->registry($context['registry'] ?? null);

        return $registrar->run(
            fn (): bool => $registrar->provider()->hasActiveDestination(
                $ownerId,
                $registrationId,
                $webhookGroup,
            ),
        );
    }
}
