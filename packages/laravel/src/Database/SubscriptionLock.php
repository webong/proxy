<?php

declare(strict_types=1);

namespace Webong\Proxy\Database;

use Illuminate\Support\Facades\DB;
use Webong\Proxy\DestinationDefinition;

/** @internal Transaction mutex for a registration that may move between endpoints. */
final class SubscriptionLock
{
    public static function acquire(DestinationDefinition $definition): void
    {
        $encoded = '';
        foreach ([$definition->ownerId, $definition->registrationId, $definition->webhookGroup] as $value) {
            $encoded .= pack('N', strlen($value)).$value;
        }
        $id = hash('sha256', $encoded);
        $table = DB::table((string) config('proxy.tables.subscription_locks', 'subscription_locks'));
        $table->insertOrIgnore(['id' => $id]);
        $table->where('id', $id)->lockForUpdate()->firstOrFail();
    }
}
