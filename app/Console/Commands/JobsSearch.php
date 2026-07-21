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
        if (Run::query()->where('status', 'running')->where('started_at', '>', now()->subHours(2))->exists()) {
            $this->warn('Активный запуск уже выполняется, выходим.');

            return self::FAILURE;
        }

        $run = Run::query()->create([
            'trigger' => $this->option('trigger'),
            'status' => 'running',
            'started_at' => now(),
        ]);
        $run->appendLog('Запуск (' . $this->option('trigger') . ')');

        try {
            $pipeline->execute($run);
            $this->info("Run #{$run->id} finished: " . json_encode($run->stats));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Run #{$run->id} failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
