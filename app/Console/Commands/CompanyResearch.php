<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Vacancy;
use App\Services\CompanyResearcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CompanyResearch extends Command
{
    protected $signature = 'company:research {vacancy? : vacancy ID to take the company from} {--name= : research a company by name directly} {--force : ignore freshness TTL}';
    protected $description = 'Research a company as an employer (reviews, turnover, WLB, CEO, compensation) via Claude CLI with web search';

    public function handle(CompanyResearcher $researcher): int
    {
        $context = null;

        if ($name = $this->option('name')) {
            $company = Company::firstOrCreateForName($name);
        } else {
            $vacancyId = $this->argument('vacancy');
            if (! $vacancyId) {
                $this->error('Укажите ID вакансии или --name=<компания>.');

                return self::FAILURE;
            }
            $context = Vacancy::query()->findOrFail($vacancyId);
            if (! $context->company) {
                $this->error("У вакансии #{$context->id} не указана компания.");

                return self::FAILURE;
            }
            $company = Company::firstOrCreateForName($context->company);
        }

        if (! $this->option('force') && $researcher->isFresh($company)) {
            $this->info("Исследование «{$company->name}» ещё свежее ({$company->researched_at->format('d.m.Y H:i')}), используйте --force для обновления.");

            return self::SUCCESS;
        }

        Cache::put("company-researching:{$company->id}", true, now()->addMinutes(20));
        try {
            $research = $researcher->research($company, $context);
            $this->line(json_encode($research, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $company->update(['last_error' => $e->getMessage()]);
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            Cache::forget("company-researching:{$company->id}");
        }
    }
}
