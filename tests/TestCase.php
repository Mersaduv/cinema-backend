<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Hard stop before RefreshDatabase / migrate:fresh can touch production.
     */
    protected function beforeRefreshingDatabase(): void
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $forbidden = ['cinema', 'production', 'cinema_production'];

        if (in_array($database, $forbidden, true) || ! str_contains($database, 'test')) {
            throw new \RuntimeException(
                "Refusing to refresh database [{$database}] on connection [{$connection}]. ".
                'PHPUnit must use cinema_testing (or another *test* database), never production.'
            );
        }
    }
}
