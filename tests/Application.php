<?php

namespace TelescopeMcp\Tests;

use TelescopeMcp\TelescopeMcpServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Telescope\TelescopeServiceProvider;
use Orchestra\Testbench\TestCase;

abstract class Application extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [TelescopeServiceProvider::class, McpServiceProvider::class, TelescopeMcpServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->detectEnvironment(fn () => getenv('TMCP_TEST_ENV') ?: 'local');
        $app['config']->set('app.debug', true);
        if (getenv('TMCP_TEST_DEFAULT') !== '1') {
            $app['config']->set('telescope-mcp.enabled', getenv('TMCP_TEST_DISABLED') !== '1');
        }
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', getenv('TMCP_TEST_DB') ?: ':memory:');
        $app['config']->set('telescope.storage.database.connection', 'sqlite');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('logging.default', 'stderr');
    }
}
