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
    private const SCORE_BATCH_SIZE = 10;
    private const DESCRIPTION_SNIPPET = 1500;
    private const RESEARCH_PER_RUN_LIMIT = 5;

    public function __construct(
        private ClaudeCli $claude,
        private TelegramNotifier $telegram,
        private CompanyResearcher $researcher,
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
        $knownLanguages = implode(', ', (array) ($settings['known_languages'] ?? ['English', 'Russian', 'Ukrainian']));
        $scored = 0;

        foreach ($pending->chunk(self::SCORE_BATCH_SIZE) as $batch) {
            $payload = $batch->map(fn (Vacancy $v) => [
                'id' => $v->id,
                'title' => $v->title,
                'company' => $v->company,
                'location' => $v->location,
                'salary' => $v->salary,
                'description' => mb_substr(strip_tags((string) $v->description), 0, self::DESCRIPTION_SNIPPET),
            ])->values()->all();

            $prompt = "You are screening job vacancies for a candidate.\n\n"
                . "CANDIDATE RESUME:\n" . $resume->text . "\n\n"
                . "CANDIDATE KNOWN LANGUAGES: {$knownLanguages}\n\n"
                . "VACANCIES (JSON):\n" . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n"
                . "For EVERY vacancy do ALL of the following:\n"
                . "1. Rate how well it matches the candidate (skills, seniority, stack, location/remote fit) on a 0-100 scale.\n"
                . "2. Determine the language the posting is written in and the languages the role actually requires "
                . "(explicitly stated or strongly implied, e.g. \"client communication in German\").\n"
                . "3. Assess the language barrier against the candidate's known languages. Judge how critical an unknown "
                . "language really is: a posting written in Polish for a developer role in a likely English-speaking team "
                . "is only a mild concern (warning); an explicit requirement like \"German C1 for client communication\" "
                . "is critical. The barrier MUST affect the score: critical barrier -> score at most 40; "
                . "warning -> reduce the score by 10-20; ok -> no change.\n"
                . "4. Write a short factual summary of the vacancy IN RUSSIAN: 2-3 sentences covering the role, "
                . "stack and conditions (remote/salary/company type). No marketing fluff.\n\n"
                . "Respond with ONLY a JSON array, no other text:\n"
                . '[{"id": <vacancy id>, "score": <0-100>, "reason": "<one short sentence in English>", '
                . '"summary": "<2-3 предложения на русском>", '
                . '"language": {"vacancy_language": "<language the posting is written in>", '
                . '"required_languages": ["<language>", ...], '
                . '"language_fit": "ok" | "warning" | "critical", '
                . '"note": "<короткое пояснение на русском; пустая строка если fit = ok>"}}]';

            try {
                $answers = $this->claude->json($prompt);
            } catch (\Throwable $e) {
                $run->appendLog('Scoring батча не удался: ' . $e->getMessage());
                continue;
            }

            foreach (is_array($answers) ? $answers : [] as $answer) {
                $vacancy = $batch->firstWhere('id', (int) ($answer['id'] ?? 0));
                if (! $vacancy) {
                    continue;
                }
                $score = max(0, min(100, (int) ($answer['score'] ?? 0)));
                $vacancy->update([
                    'score' => $score,
                    'score_reason' => (string) ($answer['reason'] ?? ''),
                    'analysis' => $this->parseAnalysis($answer) ?: null,
                    'status' => $score >= $minScore ? 'matched' : 'rejected',
                ]);
                if ($vacancy->status === 'matched') {
                    $matched->push($vacancy);
                }
                $scored++;
            }
            $run->appendLog("Оценено: {$scored}/{$pending->count()}");
        }

        $stats['scored'] = $scored;
        $stats['matched'] = $matched->count();

        return $matched;
    }

    /**
     * Extract the optional summary/language block from a scoring answer;
     * missing or malformed fields never break scoring.
     */
    private function parseAnalysis(array $answer): array
    {
        $analysis = [];

        if (is_string($answer['summary'] ?? null) && trim($answer['summary']) !== '') {
            $analysis['summary'] = trim($answer['summary']);
        }

        $lang = $answer['language'] ?? null;
        if (is_array($lang)) {
            $analysis['language'] = [
                'vacancy_language' => is_string($lang['vacancy_language'] ?? null) ? $lang['vacancy_language'] : null,
                'required_languages' => array_values(array_filter((array) ($lang['required_languages'] ?? []), 'is_string')),
                'language_fit' => in_array($lang['language_fit'] ?? null, ['ok', 'warning', 'critical'], true) ? $lang['language_fit'] : null,
                'note' => is_string($lang['note'] ?? null) ? trim($lang['note']) : null,
            ];
        }

        return $analysis;
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
