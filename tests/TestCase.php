<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use R0bdiabl0\EmailTracker\EmailTrackerServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            EmailTrackerServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'EmailTracker' => \R0bdiabl0\EmailTracker\Facades\EmailTracker::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('mail.default', 'array');

        $app['config']->set('email-tracker.table_prefix', '');
        $app['config']->set('email-tracker.default_provider', 'ses');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
