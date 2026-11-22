<?php

declare(strict_types=1);

namespace Webong\Proxy;

use Webong\Proxy\Models\WebProxyEndpoint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class DatabaseEndpointStore
{
    public function __construct(
        private readonly WebProxy $webhookProxy,
    ) {
    }

    public function handle(EndpointDefinition $definition): WebProxyEndpoint
    {
        $this->validate($definition);

        $record = DB::transaction(function () use ($definition): WebProxyEndpoint {
            $endpointKey = $this->resolveEndpointKey($definition);

            $endpoint = WebProxyEndpoint::query()->firstOrCreate(
                [
                    'client' => $definition->client,
                    'external_id' => $definition->externalId,
                ],
                [
                    'endpoint_key' => $endpointKey ?? Str::random(48),
                    'signing_secret' => $definition->signingSecret,
                    'verification_token' => $definition->verificationToken,
                    'credential_owner_id' => $definition->credentialOwnerId,
                    'metadata' => $definition->metadata,
                    'is_managed' => $definition->managed,
                    'is_active' => true,
                ],
            );

            if ($endpoint->wasRecentlyCreated) {
                return $endpoint;
            }

            if (
                $endpointKey !== null
                && $endpoint->endpoint_key !== $endpointKey
            ) {
                $this->updateEndpointKey($endpoint, $endpointKey);
            }

            if (! $this->hasSameCredentialContext($endpoint, $definition)) {
                $this->guardMatchingSigningSecret($endpoint, $definition);

                if ($definition->managed) {
                    $endpoint->update([
                        'signing_secret' => $definition->signingSecret,
                        'verification_token' => $definition->verificationToken,
                        'credential_owner_id' => null,
                        'metadata' => [...($endpoint->metadata ?? []), ...$definition->metadata],
                        'is_managed' => true,
                        'is_active' => true,
                    ]);
                }

                return $endpoint;
            }

            $endpoint->update([
                'signing_secret' => $definition->signingSecret ?? $endpoint->signing_secret,
                'verification_token' => $definition->verificationToken,
                'credential_owner_id' => $definition->managed
                    ? null
                    : $definition->credentialOwnerId,
                'metadata' => [...($endpoint->metadata ?? []), ...$definition->metadata],
                'is_managed' => $definition->managed,
                'is_active' => true,
            ]);

            return $endpoint;
        });

        return $record;
    }

    private function resolveEndpointKey(EndpointDefinition $definition): ?string
    {
        if ($definition->endpointKey === null || $definition->endpointKey === '') {
            return null;
        }

        $endpointKey = mb_trim($definition->endpointKey, " \t\n\r\0\x0B/\\\\");

        if ($endpointKey === '') {
            return null;
        }

        if (! preg_match('/^[A-Za-z0-9_.-]+$/', $endpointKey)) {
            throw new RuntimeException('Webhook endpoint key contains invalid characters.');
        }

        if (mb_strlen($endpointKey) > 64) {
            throw new RuntimeException('Webhook endpoint key is longer than supported.');
        }

        return $endpointKey;
    }

    private function updateEndpointKey(
        WebProxyEndpoint $endpoint,
        string $endpointKey,
    ): void {
        $conflictingEndpoint = WebProxyEndpoint::query()
            ->where('endpoint_key', $endpointKey)
            ->whereKeyNot($endpoint->getKey())
            ->exists();

        if ($conflictingEndpoint) {
            throw new RuntimeException('Webhook endpoint key is already in use.');
        }

        $endpoint->update(['endpoint_key' => $endpointKey]);
        $endpoint->refresh();
    }

    private function validate(EndpointDefinition $definition): void
    {
        if ($definition->client === '' || ! $this->webhookProxy->has($definition->client)) {
            throw new RuntimeException("Web proxy client [{$definition->client}] is not registered.");
        }

        if ($definition->externalId === '') {
            throw new RuntimeException('The webhook endpoint external ID is required.');
        }

        if (! $definition->managed && ($definition->credentialOwnerId === null || $definition->credentialOwnerId === '')) {
            throw new RuntimeException('An unmanaged webhook endpoint requires a credential owner.');
        }
    }

    private function hasSameCredentialContext(
        WebProxyEndpoint $endpoint,
        EndpointDefinition $definition,
    ): bool {
        $sameManagedContext = $endpoint->is_managed && $definition->managed;
        $sameCredentialOwner = ! $endpoint->is_managed
            && ! $definition->managed
            && hash_equals(
                (string) $endpoint->credential_owner_id,
                (string) $definition->credentialOwnerId,
            );

        return $sameManagedContext || $sameCredentialOwner;
    }

    private function guardMatchingSigningSecret(
        WebProxyEndpoint $endpoint,
        EndpointDefinition $definition,
    ): void {
        if (
            ! is_string($endpoint->signing_secret)
            || $endpoint->signing_secret === ''
            || ! is_string($definition->signingSecret)
            || $definition->signingSecret === ''
            || ! hash_equals($endpoint->signing_secret, $definition->signingSecret)
        ) {
            throw new RuntimeException('This webhook endpoint is registered with different credentials.');
        }
    }
}
