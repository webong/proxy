<?php

declare(strict_types=1);

namespace Webong\Proxy;

use Webong\Proxy\Contracts\WebhookEvent;
use Webong\Proxy\Contracts\WebhookJob;
use Webong\Proxy\Database\SubscriptionIdentity;
use Webong\Proxy\Enums\WebhookProxyTargetType;
use Webong\Proxy\Models\WebProxyDestination;
use Webong\Proxy\Models\WebProxyEndpoint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DatabaseDestinationAttacher
{
    public function __construct(
        private readonly WebProxyRegistryManager $registryManager,
        private readonly WebhookTargetRegistry $targetRegistry,
    ) {
    }

    public function handle(
        WebProxyEndpoint $endpoint,
        DestinationDefinition $definition,
    ): WebProxyDestination {
        $this->validate($endpoint, $definition);

        return DB::transaction(function () use ($endpoint, $definition): WebProxyDestination {
            WebProxyDestination::query()
                ->where('owner_id', $definition->ownerId)
                ->where('registration_id', $definition->registrationId)
                ->where('webhook_group', $definition->webhookGroup)
                ->where(function ($query) use ($endpoint, $definition): void {
                    $query
                        ->where('endpoint_id', '!=', $endpoint->getKey())
                        ->orWhere('routing_scope', '!=', $definition->routingScope)
                        ->orWhere('routing_key', '!=', $definition->routingKey);
                })
                ->update(['is_active' => false]);

            $route = WebProxyDestination::query()
                ->where('endpoint_id', $endpoint->getKey())
                ->where('routing_scope', $definition->routingScope)
                ->where('routing_key', $definition->routingKey)
                ->where('is_active', true);
            $routeSubscribers = (clone $route)->lockForUpdate()->get();

            if (
                $routeSubscribers->contains('owner_id', '!=', $definition->ownerId)
                && ! $definition->allowsMultipleSubscribers
            ) {
                throw new RuntimeException('This webhook route is already registered to another owner.');
            }

            $this->validateSubscriptionMode($routeSubscribers, $definition);

            if (! $definition->allowsMultipleSubscribers) {
                (clone $route)
                    ->where('owner_id', $definition->ownerId)
                    ->where('registration_id', '!=', $definition->registrationId)
                    ->update(['is_active' => false]);
            }

            $identity = [
                'endpoint_id' => (string) $endpoint->getKey(),
                'owner_id' => $definition->ownerId,
                'registration_id' => $definition->registrationId,
                'webhook_group' => $definition->webhookGroup,
                'routing_scope' => $definition->routingScope,
                'routing_key' => $definition->routingKey,
                'target_type' => $definition->targetType->value,
            ];

            return WebProxyDestination::query()->updateOrCreate(
                ['registration_hash' => SubscriptionIdentity::hash($identity)],
                [
                    ...$identity,
                    'target' => $definition->target,
                    'metadata' => [
                        ...$definition->metadata,
                        WebProxyDestination::EXECUTION_CONTEXT_METADATA_KEY => array_merge(
                            $definition->context,
                            ['registry' => $this->registry($endpoint)],
                        ),
                        WebProxyDestination::MULTIPLE_SUBSCRIBERS_METADATA_KEY => $definition->allowsMultipleSubscribers,
                    ],
                    'is_active' => true,
                    'last_error' => null,
                ],
            );
        });
    }

    /**
     * Resolve the owning registry for the destination's delivery context.
     *
     * Non-default registries are stamped on the endpoint record at registration
     * time; the default registrar is implied when no stamp is present.
     */
    private function registry(WebProxyEndpoint $endpoint): string
    {
        $registered = data_get(
            $endpoint->metadata,
            WebProxyEndpoint::REGISTRY_METADATA_KEY,
        );

        return is_string($registered) && $registered !== ''
            ? $registered
            : $this->registryManager->getDefaultDriver();
    }

    /** @param Collection<int, WebProxyDestination> $routeSubscribers */
    private function validateSubscriptionMode(
        Collection $routeSubscribers,
        DestinationDefinition $definition,
    ): void {
        $registeredModes = $routeSubscribers
            ->map(fn (WebProxyDestination $destination): mixed => data_get(
                $destination->metadata,
                WebProxyDestination::MULTIPLE_SUBSCRIBERS_METADATA_KEY,
            ))
            ->filter(fn (mixed $mode): bool => is_bool($mode))
            ->unique()
            ->values();

        if ($registeredModes->count() > 1) {
            throw new RuntimeException('This webhook route has conflicting subscriber modes.');
        }

        $registeredMode = $registeredModes->first();

        if (is_bool($registeredMode) && $registeredMode !== $definition->allowsMultipleSubscribers) {
            if ($registeredMode === false && $definition->allowsMultipleSubscribers) {
                foreach ($routeSubscribers as $subscriber) {
                    $metadata = is_array($subscriber->metadata) ? $subscriber->metadata : [];
                    $metadata[WebProxyDestination::MULTIPLE_SUBSCRIBERS_METADATA_KEY] = true;
                    $subscriber->update(['metadata' => $metadata]);
                }

                return;
            }

            throw new RuntimeException('This webhook route is registered with a different subscriber mode.');
        }

        if (
            $registeredMode === null
            && $routeSubscribers->count() > 1
            && ! $definition->allowsMultipleSubscribers
        ) {
            throw new RuntimeException('This webhook route already has multiple subscribers.');
        }
    }

    private function validate(
        WebProxyEndpoint $endpoint,
        DestinationDefinition $definition,
    ): void {
        if (! $endpoint->is_active) {
            throw new RuntimeException('The webhook endpoint is inactive.');
        }

        foreach ([
            'owner ID' => $definition->ownerId,
            'registration ID' => $definition->registrationId,
            'webhook group' => $definition->webhookGroup,
            'routing scope' => $definition->routingScope,
            'routing key' => $definition->routingKey,
            'target' => $definition->target,
        ] as $name => $value) {
            if ($value === '') {
                throw new RuntimeException("The webhook destination {$name} is required.");
            }
        }

        $isValidTarget = match ($definition->targetType) {
            WebhookProxyTargetType::REQUEST => filter_var($definition->target, FILTER_VALIDATE_URL) !== false,
            WebhookProxyTargetType::EVENT => is_a($definition->target, WebhookEvent::class, true),
            WebhookProxyTargetType::JOB => $this->isValidJobTarget($definition->target),
        };

        if (! $isValidTarget) {
            throw new RuntimeException("The webhook proxy {$definition->targetType->value} target is invalid.");
        }
    }

    private function isValidJobTarget(string $target): bool
    {
        $resolved = $this->targetRegistry->all()['job'][$target] ?? $target;

        return is_a($resolved, WebhookJob::class, true);
    }
}
