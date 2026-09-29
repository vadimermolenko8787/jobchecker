<?php

namespace App\Console\Commands;

use App\Models\Resume;
use App\Models\Vacancy;
use App\Services\DocumentGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class JobsGenerate extends Command
{
    protected $signature = 'jobs:generate {vacancy} {--doc=both : resume | cover_letter | both} {--lang= : cover letter language code} {--instructions= : extra instructions for the cover letter}';

    protected $description = 'Generate an adapted resume and/or cover letter for a single vacancy via Claude CLI';

    public function handle(DocumentGenerator $generator): int
    {
        $vacancy = Vacancy::query()->findOrFail($this->argument('vacancy'));
        $resume = Resume::active();
        if (! $resume) {
            $this->error(__('No active resume found.'));

            return self::FAILURE;
        }
        $doc = $this->option('doc');
        if (! in_array($doc, ['resume', 'cover_letter', 'both'], true)) {
            $this->error(__('Unknown document: :doc', ['doc' => $doc]));

            return self::FAILURE;
        }

        Cache::put("vacancy-generating:{$vacancy->id}", $doc, now()->addMinutes(15));
        try {
            $generator->generate($resume, $vacancy, $doc, $this->option('lang') ?: null, $this->option('instructions') ?: null);
            $this->info("Vacancy #{$vacancy->id}: {$doc} generated.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Cache::put("vacancy-generating-error:{$vacancy->id}", $e->getMessage(), now()->addMinutes(30));
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            Cache::forget("vacancy-generating:{$vacancy->id}");
        }
    }
}
