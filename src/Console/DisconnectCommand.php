<?php

namespace McpHub\Laravel\Console;

use Illuminate\Console\Command;
use McpHub\Laravel\Pairing;
use Throwable;

final class DisconnectCommand extends Command
{
    protected $signature = 'mcp-hub:disconnect';
    protected $description = 'Close local integration and revoke the paired Hub credentials';

    public function handle(Pairing $pairing): int
    {
        try {
            $status = $pairing->disconnect();
            if ($status['status'] !== 'revoked') {
                $this->warn('Local access is closed. Hub revocation is pending; retry this command when online or revoke in the Hub console.');
                return 1;
            }
            $this->info('Local access is closed and Hub credentials are revoked.');
            return 0;
        } catch (Throwable $error) {
            $this->error('Disconnect could not complete. Check local storage and revoke credentials in the Hub console.');
            return 1;
        }
    }
}
