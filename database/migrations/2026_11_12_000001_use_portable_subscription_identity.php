<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webong\Proxy\Database\SubscriptionIdentity;

return new class extends Migration {
    public function up(): void
    {
        $table = (string) config('proxy.tables.subscriptions', 'subscriptions');
        if (! Schema::hasColumn($table, 'registration_hash')) {
            Schema::table($table, static function (Blueprint $blueprint): void {
                $blueprint->char('registration_hash', 64)->nullable();
            });
        }

        // Preserve IDs and every original field; bound memory on existing registries.
        DB::table($table)->orderBy('id')->chunkById(500, static function ($rows) use ($table): void {
            foreach ($rows as $row) {
                DB::table($table)->where('id', $row->id)->update([
                    'registration_hash' => SubscriptionIdentity::hash((array) $row),
                ]);
            }
        });
        Schema::table($table, static function (Blueprint $blueprint): void {
            $blueprint->char('registration_hash', 64)->nullable(false)->change();
        });
        if (! Schema::hasIndex($table, 'subscriptions_registration_hash_unique')) {
            Schema::table($table, static function (Blueprint $blueprint): void {
                $blueprint->unique('registration_hash', 'subscriptions_registration_hash_unique');
            });
        }
        // Add the replacement before removing the legacy constraint.
        if (Schema::hasIndex($table, 'subscriptions_registration_unique')) {
            Schema::table($table, static function (Blueprint $blueprint): void {
                $blueprint->dropUnique('subscriptions_registration_unique');
            });
        }
    }

    public function down(): void
    {
        // The previous key cannot be restored under utf8mb4 on MySQL/MariaDB.
        // Fail before modifying data/schema instead of silently removing uniqueness.
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('The portable subscription identity cannot be rolled back on MySQL/MariaDB; use a forward fix or restore a pre-upgrade backup.');
        }
        $table = (string) config('proxy.tables.subscriptions', 'subscriptions');
        Schema::table($table, static function (Blueprint $blueprint): void {
            $blueprint->unique(SubscriptionIdentity::COLUMNS, 'subscriptions_registration_unique');
            $blueprint->dropUnique('subscriptions_registration_hash_unique');
            $blueprint->dropColumn('registration_hash');
        });
    }
};
