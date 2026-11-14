<?php

declare(strict_types=1);

namespace Webong\Proxy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Maps an endpoint's public routing key to the registry that owns its endpoint
 * record.
 */
class WebProxyEndpointRegistration extends Model
{
    use HasUuids;

    protected $fillable = [
        'owner_id',
        'endpoint_id',
        'endpoint_key',
        'client',
        'registry',
        'registry_id',
        'callback_url',
        'metadata',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function getTable(): string
    {
        return (string) config('proxy.tables.endpoint_registrations', 'endpoint_registrations');
    }
}
