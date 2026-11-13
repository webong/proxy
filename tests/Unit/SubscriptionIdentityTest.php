<?php

declare(strict_types=1);

use Webong\Proxy\Database\SubscriptionIdentity;

it('frames registration tuple components without separator collisions', function (): void {
    $tuple = array_fill_keys(SubscriptionIdentity::COLUMNS, 'value');
    expect(SubscriptionIdentity::hash([...$tuple, 'owner_id' => 'a|b', 'registration_id' => 'c']))
        ->not->toBe(SubscriptionIdentity::hash([...$tuple, 'owner_id' => 'a', 'registration_id' => 'b|c']))
        ->and(SubscriptionIdentity::hash([...$tuple, 'owner_id' => 'Owner']))
        ->not->toBe(SubscriptionIdentity::hash([...$tuple, 'owner_id' => 'owner']));
});
