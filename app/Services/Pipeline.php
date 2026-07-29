<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Resume;
use App\Models\Run;
use App\Models\Setting;
use App\Models\Vacancy;
use App\Services\Sources\DjinniSource;
use App\Services\Sources\DouSource;
use App\Services\Sources\IndeedSource;
use App\Services\Sources\JobSourceInterface;
use App\Services\Sources\JustJoinSource;
use App\Services\Sources\LinkedInSource;
use App\Services\Sources\SourceHttp;
use App\Services\Sources\VacancyData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class Pipeline
{
    /** Descriptions are sent in full, so batches stay small to keep the prompt readable for the model. */
    private const SCORE_BATCH_SIZE = 1;
    private const RESEARCH_PER_RUN_LIMIT = 5;

    public function __construct(
        private TelegramNotifier $telegram,
        private CompanyResearcher $researcher,
        private VacancyScorer $scorer,
    ) {
    }

    public function execute(Run $run): void
    {
        $settings = Setting::all_settings();
        $resume = Resume::active();
        $stats = [];

        try {
            $fetched = $this->fetchSources($run, $settings, $stats);
            $newCount = $this->storeNew($run, $fetched, $settings, $stats);
            $run->appendLog("Всего получено: " . count($fetched) . ", новых после фильтров: {$newCount}");

            if (! $resume) {
                $run->appendLog('Резюме не загружено — scoring пропущен.');
            } else {
                $matched = $this->scoreNew($run, $resume, $settings, $stats);
                $this->notifyMatched($run, $matched, $settings, $stats);
                $this->researchCompanies($run, $matched, $settings, $stats);
            }

            $run->update(['status' => 'ok', 'stats' => $stats, 'finished_at' => now()]);
            $run->appendLog('Готово.');
        } catch (\Throwable $e) {
            $run->appendLog('ОШИБКА: ' . $e->getMessage());
            $run->update(['status' => 'failed', 'stats' => $stats, 'finished_at' => now()]);
            throw $e;
        }
    }

    /** @return VacancyData[] */
    private function fetchSources(Run $run, array $settings, array &$stats): array
    {
        /** @var JobSourceInterface[] $sources */
        $sources = [new DouSource, new DjinniSource, new JustJoinSource, new LinkedInSource, new IndeedSource];
        $enabled = $settings['sources'] ?? [];
        $all = [];

        foreach ($sources as $source) {
            $key = $source->key();
            if (empty($enabled[$key])) {
                $run->appendLog("[{$key}] выключен, пропускаю");
                continue;
            }
            try {
                $items = $source->fetch($settings, new SourceHttp($run, $key));
                $stats['fetched'][$key] = count($items);
                $run->appendLog("[{$key}] получено вакансий: " . count($items));
                $all = array_merge($all, $items);
            } catch (\Throwable $e) {
                $stats['errors'][$key] = $e->getMessage();
                $run->appendLog("[{$key}] ошибка: {$e->getMessage()}");
            }
        }

        return $all;
    }

    /** @param VacancyData[] $fetched */
    private function storeNew(Run $run, array $fetched, array $settings, array &$stats): int
    {
        $include = array_filter(array_map('mb_strtolower', $settings['include_keywords'] ?? []));
        $exclude = array_filter(array_map('mb_strtolower', $settings['exclude_keywords'] ?? []));
        $new = 0;

        foreach ($fetched as $item) {
            $haystack = mb_strtolower($item->title . ' ' . strip_tags((string) $item->description));

            if ($include !== [] && ! $this->containsAny($haystack, $include)) {
                continue;
            }
            if ($exclude !== [] && $this->containsAny($haystack, $exclude)) {
                continue;
            }

            $vacancy = Vacancy::query()->firstOrCreate(
                ['source' => $item->source, 'external_id' => $item->externalId],
                [
                    'title' => mb_substr($item->title, 0, 500),
                    'company' => $item->company ? mb_substr($item->company, 0, 300) : null,
                    'location' => $item->location ? mb_substr($item->location, 0, 300) : null,
                    'url' => mb_substr($item->url, 0, 1000),
                    'description' => $item->description,
                    'salary' => $item->salary ? mb_substr($item->salary, 0, 255) : null,
                    'published_at' => $item->publishedAt,
                    'raw' => $item->raw,
                    'status' => 'new',
                    'run_id' => $run->id,
                ],
            );
            if ($vacancy->wasRecentlyCreated) {
                $new++;
            }
        }

        $stats['new'] = $new;

        return $new;
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, Vacancy> вакансии, получившие статус matched в этом проходе */
    private function scoreNew(Run $run, Resume $resume, array $settings, array &$stats): Collection
    {
        $matched = collect();
        $pending = Vacancy::query()->where('status', 'new')->orderBy('id')->get();
        if ($pending->isEmpty()) {
            return $matched;
        }
        $run->appendLog('Scoring через Claude: ' . $pending->count() . ' вакансий...');
        $minScore = (int) ($settings['min_score'] ?? 70);
        $knownLanguages = array_values((array) ($settings['known_languages'] ?? ['English', 'Russian', 'Ukrainian']));
        $weights = VacancyScorer::weights($settings);
        $scored = 0;
        /** @var array<int, array{vacancy: Vacancy, score: int, result: array}> $toRecheck */
        $toRecheck = [];

        foreach ($pending->chunk(self::SCORE_BATCH_SIZE) as $batch) {
            try {
                $results = $this->scorer->scoreBatch($resume, $batch, $knownLanguages);
            } catch (\Throwable $e) {
                $run->appendLog('Scoring батча не удался: ' . $e->getMessage());
                continue;
            }

            foreach ($batch as $vacancy) {
                $result = $results[$vacancy->id] ?? null;
                if ($result === null) {
                    $run->appendLog("[{$vacancy->id}] невалидный ответ скоринга, останется на следующий запуск");
                    continue;
                }
                $score = $this->finalScore($result, $weights);
                $scored++;

                // Near the threshold a single verdict decides matched/rejected, so those
                // get re-scored individually and settled by median instead.
                if (abs($score - $minScore) <= VacancyScorer::RECHECK_MARGIN) {
                    $toRecheck[] = ['vacancy' => $vacancy, 'score' => $score, 'result' => $result];

                    continue;
                }
                if ($this->persistScore($vacancy, $score, $result, $weights, $minScore)) {
                    $matched->push($vacancy);
                }
            }
            $run->appendLog("Оценено: {$scored}/{$pending->count()}");
        }

        foreach ($toRecheck as $item) {
            /** @var Vacancy $vacancy */
            $vacancy = $item['vacancy'];
            $runs = [['score' => $item['score'], 'result' => $item['result']]];

            // A failed attempt gets one retry: a median over an even number of runs
            // is a plain average, which is exactly the coin-flip we are removing.
            $attempts = VacancyScorer::RECHECK_EXTRA_RUNS + 1;
            while (count($runs) <= VacancyScorer::RECHECK_EXTRA_RUNS && $attempts-- > 0) {
                try {
                    $extra = $this->scorer->scoreSingle($resume, $vacancy, $knownLanguages);
                } catch (\Throwable $e) {
                    $run->appendLog("[{$vacancy->id}] перепроверка не удалась: {$e->getMessage()}");

                    continue;
                }
                if ($extra !== null) {
                    $runs[] = ['score' => $this->finalScore($extra, $weights), 'result' => $extra];
                }
            }

            $runScores = array_column($runs, 'score');
            $median = $this->scorer->median($runScores);
            // Keep the run that produced the median, so the stored evidence always
            // explains the stored score. With three runs the median IS one of them;
            // if an attempt was lost, the closest run wins instead of a synthetic average.
            usort($runs, fn (array $a, array $b) => abs($a['score'] - $median) <=> abs($b['score'] - $median));
            $chosen = $runs[0];

            $run->appendLog(
                "[{$vacancy->id}] score {$item['score']} близко к порогу {$minScore}: "
                . 'перепроверка [' . implode(', ', $runScores) . "] → медиана {$median}"
                . ($chosen['score'] === $median ? '' : ", итог {$chosen['score']}"),
            );

            if ($this->persistScore($vacancy, $chosen['score'], $chosen['result'], $weights, $minScore, $runScores)) {
                $matched->push($vacancy);
            }
        }

        $stats['scored'] = $scored;
        $stats['rechecked'] = count($toRecheck);
        $stats['matched'] = $matched->count();

        return $matched;
    }

    /** Weighted rubric score with the language barrier applied on top. */
    private function finalScore(array $result, array $weights): int
    {
        return $this->scorer->applyLanguagePenalty(
            $this->scorer->computeScore($result['criteria'], $weights),
            $result['language']['language_fit'] ?? null,
        );
    }

    /**
     * @param  int[]|null  $runScores
     * @return bool whether the vacancy ended up matched
     */
    private function persistScore(
        Vacancy $vacancy,
        int $score,
        array $result,
        array $weights,
        int $minScore,
        ?array $runScores = null,
    ): bool {
        $breakdown = [
            'criteria' => $result['criteria'],
            'weights' => $weights,
            'language_fit' => $result['language']['language_fit'] ?? null,
            'base_score' => $this->scorer->computeScore($result['criteria'], $weights),
        ];
        if ($runScores !== null) {
            $breakdown['rechecked'] = true;
            $breakdown['run_scores'] = array_values($runScores);
        }

        $analysis = array_filter([
            'summary' => $result['summary'],
            'language' => $result['language'],
        ], fn ($value) => $value !== null);

        $vacancy->update([
            'score' => $score,
            'score_reason' => $result['reason'],
            'score_breakdown' => $breakdown,
            'analysis' => $analysis ?: null,
            'status' => $score >= $minScore ? 'matched' : 'rejected',
        ]);

        return $vacancy->status === 'matched';
    }

    /** @param Collection<int, Vacancy> $matched */
    private function notifyMatched(Run $run, Collection $matched, array $settings, array &$stats): void
    {
        if ($matched->isEmpty() || empty($settings['telegram_enabled'])) {
            return;
        }
        $token = trim((string) ($settings['telegram_bot_token'] ?? ''));
        $chatId = trim((string) ($settings['telegram_chat_id'] ?? ''));
        if ($token === '' || $chatId === '') {
            $run->appendLog('Telegram включён, но не заданы token/chat_id — уведомления пропущены.');

            return;
        }

        $run->appendLog('Отправка в Telegram: ' . $matched->count() . ' вакансий...');
        $sent = 0;

        foreach ($matched as $vacancy) {
            try {
                $this->telegram->sendVacancy($token, $chatId, $vacancy);
                $sent++;
            } catch (\Throwable $e) {
                $run->appendLog("[{$vacancy->id}] Telegram не отправлен: {$e->getMessage()}");
            }
        }

        $run->appendLog("Отправлено в Telegram: {$sent}/{$matched->count()}");
        $stats['notified'] = $sent;
    }

    /** @param Collection<int, Vacancy> $matched */
    private function researchCompanies(Run $run, Collection $matched, array $settings, array &$stats): void
    {
        if (empty($settings['company_research_enabled'])) {
            return;
        }

        $names = $matched->pluck('company')->filter()
            ->unique(fn (string $name) => Company::normalize($name))
            ->values();
        if ($names->isEmpty()) {
            return;
        }
        if ($names->count() > self::RESEARCH_PER_RUN_LIMIT) {
            $skipped = $names->slice(self::RESEARCH_PER_RUN_LIMIT);
            $run->appendLog('Исследование компаний: лимит ' . self::RESEARCH_PER_RUN_LIMIT . ' за запуск, пропущены: ' . $skipped->implode(', '));
            $names = $names->take(self::RESEARCH_PER_RUN_LIMIT);
        }

        $run->appendLog('Исследование компаний: ' . $names->count() . '...');
        $researched = 0;

        foreach ($names as $name) {
            $company = Company::firstOrCreateForName($name);
            if ($this->researcher->isFresh($company)) {
                $run->appendLog("[{$company->name}] кэш свежий, пропускаю");
                continue;
            }
            $flag = "company-researching:{$company->id}";
            if (Cache::has($flag)) {
                $run->appendLog("[{$company->name}] исследование уже идёт, пропускаю");
                continue;
            }

            Cache::put($flag, true, now()->addMinutes(20));
            try {
                $context = $matched->first(fn (Vacancy $v) => $v->company && Company::normalize($v->company) === $company->normalized_name);
                $this->researcher->research($company, $context);
                $researched++;
                $run->appendLog("[{$company->name}] исследование готово");
            } catch (\Throwable $e) {
                $company->update(['last_error' => $e->getMessage()]);
                $run->appendLog("[{$company->name}] исследование не удалось: {$e->getMessage()}");
            } finally {
                Cache::forget($flag);
            }
        }

        $stats['researched'] = $researched;
    }

}
