<?php

namespace TelescopeMcp;

use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;

final class TelescopeMcpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/telescope-mcp.php', 'telescope-mcp');
        $this->app->bind(\Laravel\Mcp\Console\Commands\StartCommand::class, Console\StartCommand::class);

        // All providers register before Telescope boots its watchers. Also cover
        // global Artisan options: Telescope tests argv[1], not the parsed command.
        $argv = $_SERVER['argv'] ?? [];
        if ($this->app->runningInConsole() && in_array('mcp:start', $argv, true)) {
            $ignored = $this->app['config']->get('telescope.ignore_commands', []);
            $this->app['config']->set('telescope.ignore_commands', array_unique([
                ...$ignored, 'mcp:start', $argv[1] ?? 'mcp:start',
            ]));
        }
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/telescope-mcp.php' => config_path('telescope-mcp.php'),
        ], 'telescope-mcp-config');

        if ($this->app->runningInConsole()
            && $this->app->environment('local')
            && config('telescope-mcp.enabled') === true) {
            Mcp::local('telescope', TelescopeServer::class);
        }
    }
}
