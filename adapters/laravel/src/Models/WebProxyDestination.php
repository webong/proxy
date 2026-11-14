<?php

declare(strict_types=1);

namespace Webong\Proxy\Models;

use Webong\Proxy\Database\SubscriptionIdentity;
use Webong\Proxy\Enums\WebhookProxyTargetType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property WebhookProxyTargetType $target_type
 * @property array<string, mixed>|null $metadata
 */
class WebProxyDestination extends Model
{
    use HasUuids;

    public const string EXECUTION_CONTEXT_METADATA_KEY = '_execution_context';

    public const string MULTIPLE_SUBSCRIBERS_METADATA_KEY = '_allows_multiple_subscribers';

    protected $hidden = ['registration_hash'];

    protected $attributes = [
        'target_type' => 'request',
    ];

    protected $fillable = [
        'registration_hash',
        'endpoint_id',
        'owner_id',
        'registration_id',
        'webhook_group',
        'routing_scope',
        'routing_key',
        'target_type',
        'target',
        'metadata',
        'is_active',
        'last_delivered_at',
        'last_failed_at',
        'last_error',
    ];

    protected function getAttributesForInsert()
    {
        $this->maintainRegistrationHash();

        return parent::getAttributesForInsert();
    }

    protected function performUpdate(\Illuminate\Database\Eloquent\Builder $query)
    {
        // Keep the locked identity read and the dirty-field update together,
        // including quiet saves and changes made by updating event listeners.
        return $this->getConnection()->transaction(fn () => parent::performUpdate($query));
    }

    protected function getDirtyForUpdate()
    {
        if ($this->isDirty([...SubscriptionIdentity::COLUMNS, 'registration_hash'])) {
            $this->maintainRegistrationHash();
        }

        return parent::getDirtyForUpdate();
    }

    private function maintainRegistrationHash(): void
    {
        foreach (SubscriptionIdentity::COLUMNS as $column) {
            if ($this->exists && ! $this->isDirty($column)) {
                continue;
            }
            $value = $this->getAttributes()[$column] ?? null;
            if (is_int($value) || is_float($value) || is_bool($value)) {
                $value = (string) (is_bool($value) ? (int) $value : $value);
                $this->setAttribute($column, $value);
            }
            if ($column === 'endpoint_id' && is_string($value)) {
                $this->setAttribute($column, SubscriptionIdentity::canonicalEndpointId($value));
            }
        }
        $attributes = $this->getAttributes();
        if ($this->exists) {
            $stored = $this->newQueryWithoutScopes()
                ->whereKey($this->getRawOriginal($this->getKeyName()))
                ->lockForUpdate()
                ->firstOrFail(SubscriptionIdentity::COLUMNS);
            // Full models can be stale too. Merge only fields this save writes.
            $attributes = array_replace($stored->getAttributes(), array_intersect_key(
                $this->getDirty(), array_flip(SubscriptionIdentity::COLUMNS),
            ));
        }
        $this->setAttribute('registration_hash', SubscriptionIdentity::hash($attributes));
    }

    /** @internal Exact subscription identity filters, independent of text collation. */
    public function scopeWhereIdentity(\Illuminate\Database\Eloquent\Builder $query, string $column, string $value, string $operator = '='): void
    {
        if (! in_array($column, SubscriptionIdentity::COLUMNS, true) || ! in_array($operator, ['=', '!='], true)) {
            throw new \InvalidArgumentException('Invalid subscription identity predicate.');
        }
        $wrapped = $query->getQuery()->getGrammar()->wrap($column);
        [$expression, $parameter] = match ($query->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => ["CAST({$wrapped} AS BINARY)", 'CAST(? AS BINARY)'],
            'pgsql' => ["CAST({$wrapped} AS text) COLLATE \"C\"", 'CAST(? AS text) COLLATE "C"'],
            'sqlite' => ["CAST({$wrapped} AS BLOB)", 'CAST(? AS BLOB)'],
            default => throw new \RuntimeException('Unsupported subscription SQL driver.'),
        };
        $query->whereRaw("{$expression} {$operator} {$parameter}", [$value]);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target_type' => WebhookProxyTargetType::class,
            'metadata' => 'array',
            'is_active' => 'boolean',
            'last_delivered_at' => 'immutable_datetime',
            'last_failed_at' => 'immutable_datetime',
        ];
    }

    public function getTable(): string
    {
        return (string) config('proxy.tables.subscriptions', 'subscriptions');
    }

    /** @return BelongsTo<WebProxyEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebProxyEndpoint::class, 'endpoint_id');
    }
}
