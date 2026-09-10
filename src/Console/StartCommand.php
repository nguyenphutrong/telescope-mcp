<?php

namespace TelescopeMcp\Console;

use Laravel\Mcp\Console\Commands\StartCommand as SdkStartCommand;
use Laravel\Mcp\Server\Registrar;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'mcp:start', description: 'Start the MCP Server for a given handle')]
final class StartCommand extends SdkStartCommand
{
    public function handle(Registrar $registrar): int
    {
        // The SDK prints unknown-handle errors on stdout. Keep this handle's
        // refusal out of the protocol stream without replacing its transport.
        if ($this->argument('handle') === 'telescope' && $registrar->getLocalServer('telescope') === null) {
            fwrite(STDERR, "Telescope MCP is unavailable. Enable TELESCOPE_MCP_ENABLED=true in the local environment.\n");

            return self::FAILURE;
        }

        return parent::handle($registrar);
    }
}
