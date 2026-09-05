<?php

namespace App\Console\Commands;

use App\Services\TripStatusSynchronizer;
use Illuminate\Console\Command;

class SyncTripStatuses extends Command
{
    protected $signature = 'trips:sync-statuses';

    protected $description = 'Advance trip statuses using Asia/Manila (UTC+8) departure and arrival times';

    public function handle(TripStatusSynchronizer $synchronizer): int
    {
        $result = $synchronizer->sync();

        $this->info(sprintf(
            '[%s Asia/Manila] Departed: %d, Arrived: %d',
            $result['now'],
            $result['departed'],
            $result['arrived']
        ));

        return self::SUCCESS;
    }
}
