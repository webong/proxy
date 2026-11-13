<?php

declare(strict_types=1);

namespace Webong\Proxy\Database;

/** @internal Storage key for an exact registration tuple, independent of SQL collation. */
final class SubscriptionIdentity
{
    public const array COLUMNS = [
        'endpoint_id', 'owner_id', 'registration_id', 'webhook_group',
        'routing_scope', 'routing_key', 'target_type',
    ];

    public static function canonicalEndpointId(string $value): string
    {
        return preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/iD', $value) === 1
            ? strtolower($value) : $value;
    }

    /** @param array<string, mixed> $attributes */
    public static function hash(array $attributes): string
    {
        $encoded = '';
        foreach (self::COLUMNS as $column) {
            $value = $attributes[$column] ?? null;
            if (! is_string($value)) {
                throw new \InvalidArgumentException("Subscription identity requires string {$column}.");
            }
            if ($column === 'endpoint_id') {
                $value = self::canonicalEndpointId($value);
            }
            // Length prefixes prevent ambiguous tuples, including embedded separators.
            $encoded .= pack('N', strlen($value)).$value;
        }

        return hash('sha256', $encoded);
    }
}
