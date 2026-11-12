<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('renames legacy prefixed registry tables during an upgrade', function (): void {
    Schema::rename('endpoints', 'web_proxy_endpoints');
    Schema::rename('subscriptions', 'web_proxy_destinations');
    Schema::rename('endpoint_registrations', 'web_proxy_endpoint_registrations');

    (require __DIR__.'/../../database/legacy-migrations/2026_07_31_135456_adopt_legacy_registry_migrations.php')->up();

    expect(Schema::hasTable('endpoints'))->toBeTrue()
        ->and(Schema::hasTable('subscriptions'))->toBeTrue()
        ->and(Schema::hasTable('endpoint_registrations'))->toBeTrue()
        ->and(Schema::hasTable('web_proxy_endpoints'))->toBeFalse()
        ->and(Schema::hasTable('web_proxy_destinations'))->toBeFalse()
        ->and(Schema::hasTable('web_proxy_endpoint_registrations'))->toBeFalse();
});

it('adopts legacy migration history before renamed migrations run', function (): void {
    $legacy = '2026_07_31_135457_create_web_proxy_endpoints_table';
    $renamed = '2026_07_31_135457_create_endpoints_table';

    DB::table('migrations')->where('migration', $renamed)->delete();
    DB::table('migrations')->insert([
        'migration' => $legacy,
        'batch' => 1,
    ]);

    (require __DIR__.'/../../database/legacy-migrations/2026_07_31_135456_adopt_legacy_registry_migrations.php')->up();

    expect(DB::table('migrations')->where('migration', $legacy)->exists())->toBeFalse()
        ->and(DB::table('migrations')->where('migration', $renamed)->exists())->toBeTrue();
});


it('backfills existing registrations without changing their values and can resume the upgrade', function (): void {
    $table = 'tenant_subscriptions';
    Schema::rename('subscriptions', $table);
    config()->set('proxy.tables.subscriptions', $table);
    Schema::table($table, function ($blueprint): void {
        $blueprint->dropUnique('subscriptions_registration_hash_unique');
        $blueprint->dropColumn('registration_hash');
    });
    // PostgreSQL/SQLite had the legacy key; MySQL failed before creating it.
    if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
        Schema::table($table, fn ($blueprint) => $blueprint->unique(
            \Webong\Proxy\Database\SubscriptionIdentity::COLUMNS,
            'subscriptions_registration_unique',
        ));
    }
    $values = [
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'endpoint_id' => (string) \Illuminate\Support\Str::uuid(),
        'owner_id' => str_repeat('é', 255),
        'registration_id' => str_repeat('r', 255),
        'webhook_group' => 'events', 'routing_scope' => 'account', 'routing_key' => 'key',
        'target_type' => 'request', 'target' => 'https://example.test/hook',
        'metadata' => '{"preserved":true}', 'is_active' => true,
    ];
    DB::table($table)->insert($values);
    $before = (array) DB::table($table)->first();
    $migration = require __DIR__.'/../../database/migrations/2026_11_12_000001_use_portable_subscription_identity.php';
    $migration->up();
    $migration->up();
    $after = (array) DB::table($table)->first();
    $hash = $after['registration_hash'];
    unset($after['registration_hash']);
    expect($after)->toBe($before)
        ->and($hash)->toBe(\Webong\Proxy\Database\SubscriptionIdentity::hash($values))
        ->and(Schema::hasIndex($table, 'subscriptions_registration_hash_unique'))->toBeTrue()
        ->and(Schema::hasIndex($table, 'subscriptions_registration_unique'))->toBeFalse();
});

it('enforces exact registration identity for long Unicode values and updates its key on model saves', function (): void {
    $attributes = [
        'endpoint_id' => (string) \Illuminate\Support\Str::uuid(),
        'owner_id' => str_repeat('🙂', 255), 'registration_id' => str_repeat('r', 255),
        'webhook_group' => 'events', 'routing_scope' => 'account', 'routing_key' => 'key',
        'target_type' => 'request', 'target' => 'https://example.test/hook',
    ];
    $first = \Webong\Proxy\Models\WebProxyDestination::create($attributes);
    expect(strlen($first->registration_hash))->toBe(64)
        ->and($first->toArray())->not->toHaveKey('registration_hash');
    $first->update(['routing_key' => 'new-key']);
    expect($first->registration_hash)->toBe(\Webong\Proxy\Database\SubscriptionIdentity::hash([
        ...$attributes, 'routing_key' => 'new-key',
    ]));
    \Webong\Proxy\Models\WebProxyDestination::create($attributes);
    \Webong\Proxy\Models\WebProxyDestination::create([...$attributes, 'routing_key' => 'KEY']);
    expect(\Webong\Proxy\Models\WebProxyDestination::count())->toBe(3);
    expect(fn () => \Webong\Proxy\Models\WebProxyDestination::create($attributes))
        ->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});

if (in_array(getenv('PROXY_TEST_DB'), ['mysql', 'mariadb'], true)) {
    it('recovers an empty MySQL table left by the original failed migration', function (): void {
        Schema::table('subscriptions', function ($table): void {
            $table->dropUnique('subscriptions_registration_hash_unique');
            $table->dropColumn('registration_hash');
            $table->dropIndex('subscriptions_routing_index');
        });
        (require __DIR__.'/../../database/migrations/2026_07_31_135458_create_subscriptions_table.php')->up();
        (require __DIR__.'/../../database/migrations/2026_11_12_000001_use_portable_subscription_identity.php')->up();
        expect(Schema::hasIndex('subscriptions', 'subscriptions_routing_index'))->toBeTrue()
            ->and(Schema::hasIndex('subscriptions', 'subscriptions_registration_hash_unique'))->toBeTrue();
    });
}

it('keeps uniqueness protected when rolling back the portable key', function (): void {
    $migration = require __DIR__.'/../../database/migrations/2026_11_12_000001_use_portable_subscription_identity.php';
    if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
        expect(fn () => $migration->down())->toThrow(RuntimeException::class)
            ->and(Schema::hasIndex('subscriptions', 'subscriptions_registration_hash_unique'))->toBeTrue();
    } else {
        $migration->down();
        expect(Schema::hasIndex('subscriptions', 'subscriptions_registration_unique'))->toBeTrue()
            ->and(Schema::hasColumn('subscriptions', 'registration_hash'))->toBeFalse();
        $migration->up();
        expect(Schema::hasIndex('subscriptions', 'subscriptions_registration_hash_unique'))->toBeTrue();
    }
});

if (in_array(getenv('PROXY_TEST_DB'), ['mysql', 'mariadb'], true)) {
    it('does not adopt an unrecorded MySQL table containing registry data', function (): void {
        \Webong\Proxy\Models\WebProxyDestination::create([
            'endpoint_id' => (string) \Illuminate\Support\Str::uuid(),
            'owner_id' => 'owner', 'registration_id' => 'registration',
            'webhook_group' => 'events', 'routing_scope' => 'account', 'routing_key' => 'key',
            'target_type' => 'request', 'target' => 'https://example.test/hook',
        ]);
        $migration = require __DIR__.'/../../database/migrations/2026_07_31_135458_create_subscriptions_table.php';
        expect(fn () => $migration->up())->toThrow(RuntimeException::class)
            ->and(DB::table('subscriptions')->count())->toBe(1);
    });
}
