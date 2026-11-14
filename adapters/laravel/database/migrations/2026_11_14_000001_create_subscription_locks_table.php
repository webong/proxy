<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create((string) config('proxy.tables.subscription_locks', 'subscription_locks'), function (Blueprint $table): void {
            $table->char('id', 64)->primary();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('proxy.tables.subscription_locks', 'subscription_locks'));
    }
};
