<?php

namespace App\Console\Commands;

use App\Services\RecurringScheduleGenerator;
use Illuminate\Console\Command;

class GenerateRecurringSchedules extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'schedules:generate-recurring {--days=90 : Lookahead horizon in days (default: 90 days / 3 months)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically generate bookable trip schedule instances from active recurring schedule templates (minimum 3 months)';

    /**
     * Execute the console command.
     */
    public function handle(RecurringScheduleGenerator $generator): int
    {
        $days = (int) $this->option('days');
        if ($days < 1) {
            $days = RecurringScheduleGenerator::LOOKAHEAD_DAYS;
        }

        $this->info("Scanning active recurring schedule templates for the next {$days} day(s)...");

        $result = $generator->generateForDays($days);

        $this->info("Completed: {$result['generated_count']} trip(s) generated, {$result['skipped_count']} existing instance(s) skipped.");

        if (!empty($result['trips'])) {
            $this->table(
                ['Trip ID', 'Route', 'Vessel', 'Departure', 'Template ID'],
                $result['trips']
            );
        }

        return self::SUCCESS;
    }
}
