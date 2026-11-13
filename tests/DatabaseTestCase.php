<?php

declare(strict_types=1);

namespace Webong\Proxy\Tests;

use Illuminate\Foundation\Testing\RefreshDatabaseState;

/** DDL tests need isolated schemas, outside RefreshDatabase's transactions. */
abstract class DatabaseTestCase extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
        RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }
}
