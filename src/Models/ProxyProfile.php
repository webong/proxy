<?php

declare(strict_types=1);

namespace Webong\WebProxy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProxyProfile extends Model
{
    use HasUuids;

    protected $fillable = [
        'endpoint_id',
        'name',
        'driver',
        'configuration',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'configuration' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }

    public function getTable(): string
    {
        return (string) config('proxy.tables.profiles', 'profiles');
    }

    /** @return BelongsTo<WebProxyEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebProxyEndpoint::class, 'endpoint_id');
    }
}
