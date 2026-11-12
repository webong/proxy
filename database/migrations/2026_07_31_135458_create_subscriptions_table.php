<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $name = (string) config('proxy.tables.subscriptions', 'subscriptions');
        // MySQL DDL is not transactional: an earlier 1071 failure can leave
        // the complete, empty table behind without recording this migration.
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)
            && Schema::hasTable($name)) {
            $columns = ['id', 'endpoint_id', 'owner_id', 'registration_id', 'webhook_group',
                'routing_scope', 'routing_key', 'target_type', 'target', 'metadata', 'is_active',
                'last_delivered_at', 'last_failed_at', 'last_error', 'created_at', 'updated_at'];
            if (! Schema::hasColumns($name, $columns)
                || ! Schema::hasIndex($name, ['id'], 'primary')
                || \Illuminate\Support\Facades\DB::table($name)->exists()) {
                throw new RuntimeException('Existing subscriptions table is not an empty failed migration; inspect it before retrying.');
            }
            if (! Schema::hasIndex($name, 'subscriptions_routing_index')) {
                Schema::table($name, static function (Blueprint $table): void {
                    $table->index(['endpoint_id', 'routing_scope', 'routing_key', 'is_active'], 'subscriptions_routing_index');
                });
            }

            return;
        }
        Schema::create($name, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('endpoint_id');
            $table->string('owner_id');
            $table->string('registration_id');
            $table->string('webhook_group');
            $table->string('routing_scope');
            $table->string('routing_key');
            $table->string('target_type')->default('request');
            $table->text('target');
            $table->jsonb('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            // MySQL/MariaDB cannot index this utf8mb4 tuple. The additive
            // registration-hash migration supplies portable uniqueness instead.
            if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $table->unique(
                    ['endpoint_id', 'owner_id', 'registration_id', 'webhook_group', 'routing_scope', 'routing_key', 'target_type'],
                    'subscriptions_registration_unique',
                );
            }
            $table->index(
                ['endpoint_id', 'routing_scope', 'routing_key', 'is_active'],
                'subscriptions_routing_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('proxy.tables.subscriptions', 'subscriptions'));
    }
};
