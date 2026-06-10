<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupStuckJobs extends Command
{
    protected $signature   = 'jobs:cleanup';
    protected $description = 'Reset validation jobs stuck in processing for more than 120 minutes';

    public function handle(): void
    {
        $affected = DB::table('validation_jobs')
            ->where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes(120))
            ->update([
                'status' => 'failed',
                'error'  => 'Job timed out — reset by cleanup',
            ]);

        $this->info("Cleaned up {$affected} stuck job(s).");
    }
}
