<?php

namespace McpHub\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PruneCommand extends Command
{
    protected $signature = 'mcp-hub:prune {--days=30 : Retain terminal command receipts for this many days}';
    protected $description = 'Prune expired package metadata without reading application records';

    public function handle(): int
    {
        $days = $this->option('days');
        if (! is_string($days) || ! preg_match('/^[0-9]{1,3}$/D', $days) || (int) $days < 1 || (int) $days > 365) {
            $this->error('Receipt retention must be 1 to 365 days.');
            return 1;
        }
        try {
            DB::table('mcp_hub_nonces')->where('expires_at', '<', time())->delete();
            DB::table('mcp_hub_commands')->where('status', 'pending')->where('expires_at', '<=', time())
                ->update(['status' => 'expired', 'payload' => '']);
            DB::table('mcp_hub_commands')->whereIn('status', ['expired', 'cancelled', 'committed'])
                ->where('expires_at', '<', time() - (int) $days * 86400)->delete();
            if (\Illuminate\Support\Facades\Schema::hasTable('mcp_hub_exports')) {
                app(\McpHub\Laravel\Exports\Exports::class)->prune();
            }
        } catch (Throwable $error) {
            $this->error('Metadata cleanup failed. Check database access and package migrations.');
            return 1;
        }
        $this->info('Expired package metadata pruned.');
        return 0;
    }
}
