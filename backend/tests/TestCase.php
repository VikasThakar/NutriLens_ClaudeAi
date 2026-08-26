<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * A hard guard against the test suite ever pointing at a real database.
     *
     * Most tests use RefreshDatabase, which drops and re-migrates whatever
     * connection is configured. phpunit.xml sets that to in-memory SQLite, but
     * that is only a default: a cached config (`php artisan config:cache` while
     * .env still says mysql), a stray environment variable, or an edited
     * phpunit.xml would all silently redirect the suite at `nutrilens_db` — and
     * the first RefreshDatabase test would wipe it.
     *
     * Failing loudly here costs nothing and makes that class of accident
     * impossible.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(sprintf(
                'Refusing to run tests against "%s" (%s). The suite must use in-memory SQLite. '
                .'Check phpunit.xml, then run `php artisan config:clear`.',
                $connection,
                var_export($database, true),
            ));
        }
    }
}
