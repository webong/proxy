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

    protected static function booted(): void
    {
        static::saving(function (self $destination): void {
            $destination->setAttribute('registration_hash', SubscriptionIdentity::hash($destination->getAttributes()));
        });
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
