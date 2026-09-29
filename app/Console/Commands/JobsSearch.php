<?php

namespace App\Console\Commands;

use App\Models\Run;
use App\Services\Pipeline;
use Illuminate\Console\Command;

class JobsSearch extends Command
{
    protected $signature = 'jobs:search {--trigger=manual}';

    protected $description = 'Fetch vacancies from all enabled sources, score them against the resume and notify about matches';

    public function handle(Pipeline $pipeline): int
    {
        $this->closeStaleRuns();

        if (Run::active()) {
            $this->warn(__('A run is already active, exiting.'));

            return self::FAILURE;
        }

        $run = Run::query()->create([
            'trigger' => $this->option('trigger'),
            'status' => 'running',
            'started_at' => now(),
        ]);
        $run->appendLog(__('Run (:trigger)', ['trigger' => $this->option('trigger')]));

        try {
            $pipeline->execute($run);
            $this->info("Run #{$run->id} finished: " . json_encode($run->stats));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Run #{$run->id} failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * A run killed from outside, by the machine sleeping mid-run or by a reboot, never reaches
     * its own catch block: the row keeps status 'running' forever, the interface keeps showing
     * a live search and the start button stays disabled. Nothing can revive such a run, so the
     * next start is what closes it.
     */
    private function closeStaleRuns(): void
    {
        foreach (Run::stale() as $run) {
            $run->appendLog(__('The run was interrupted from outside (machine sleep or reboot), closed at the next start.'));
            $run->update(['status' => 'failed', 'finished_at' => now()]);
            $this->warn(__('Run #:id was stuck in running and was marked failed.', ['id' => $run->id]));
        }
    }
}
