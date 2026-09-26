<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** @var array<string, string> */
    private const array LEGACY_TABLES = [
        'endpoints' => 'web_proxy_endpoints',
        'subscriptions' => 'web_proxy_destinations',
        'endpoint_registrations' => 'web_proxy_endpoint_registrations',
    ];

    /** @var array<string, string> */
    private const array MIGRATIONS = [
        '2026_07_31_135457_create_web_proxy_endpoints_table' => '2026_07_31_135457_create_endpoints_table',
        '2026_07_31_135458_create_web_proxy_destinations_table' => '2026_07_31_135458_create_subscriptions_table',
        '2026_08_10_000001_create_web_proxy_endpoint_registrations_table' => '2026_08_10_000001_create_endpoint_registrations_table',
    ];

    public function up(): void
    {
        foreach (self::LEGACY_TABLES as $configuration => $legacyTable) {
            $table = (string) config("proxy.tables.{$configuration}", $configuration);

            if ($table !== $legacyTable && Schema::hasTable($legacyTable) && ! Schema::hasTable($table)) {
                Schema::rename($legacyTable, $table);
            }
        }

        if (! Schema::hasTable('migrations')) {
            return;
        }

        foreach (self::MIGRATIONS as $legacyMigration => $migration) {
            if (
                DB::table('migrations')->where('migration', $legacyMigration)->exists()
                && ! DB::table('migrations')->where('migration', $migration)->exists()
            ) {
                DB::table('migrations')
                    ->where('migration', $legacyMigration)
                    ->update(['migration' => $migration]);
            }
        }
    }

    public function down(): void
    {
        // Migration history is intentionally not rewritten during rollback.
    }
};
