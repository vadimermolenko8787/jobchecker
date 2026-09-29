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
use App\Services\Sources\JobicoSource;
use App\Services\Sources\JobSourceInterface;
use App\Services\Sources\JoobleSource;
use App\Services\Sources\JustJoinSource;
use App\Services\Sources\LinkedInSource;
use App\Services\Sources\PracujSource;
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
    ) {}

    public function execute(Run $run): void
    {
        $settings = Setting::all_settings();
        $resume = Resume::active();
        $stats = [];

        try {
            $fetched = $this->fetchSources($run, $settings, $stats);
            $reposted = $this->storeNew($run, $fetched, $settings, $stats);
            $run->appendLog(__('Fetched in total: :fetched, new after filters: :new', ['fetched' => count($fetched), 'new' => $stats['new']])
                . ($stats['bumped'] ? __(', bumped again: :bumped, of them to Telegram: :reposted', ['bumped' => $stats['bumped'], 'reposted' => $reposted->count()]) : ''));

            if (! $resume) {
                $run->appendLog(__('No resume uploaded, scoring skipped.'));
            } else {
                $matched = $this->scoreNew($run, $resume, $settings, $stats);
                // Reposts keep their old verdict, so they join the notification without scoring.
                $this->notifyMatched($run, $matched->merge($reposted), $settings, $stats);
                $this->researchCompanies($run, $matched, $settings, $stats);
            }

            $stopped = $run->stopRequested();
            $run->update(['status' => $stopped ? 'cancelled' : 'ok', 'stats' => $stats, 'finished_at' => now()]);
            $run->appendLog($stopped ? __('Stopped on request.') : __('Done.'));
        } catch (\Throwable $e) {
            $run->appendLog(__('ERROR: :error', ['error' => $e->getMessage()]));
            $run->update(['status' => 'failed', 'stats' => $stats, 'finished_at' => now()]);
            throw $e;
        }
    }

    /** @return VacancyData[] */
    private function fetchSources(Run $run, array $settings, array &$stats): array
    {
        /** @var JobSourceInterface[] $sources */
        $sources = [new DouSource, new DjinniSource, new JustJoinSource, new LinkedInSource, new IndeedSource, new PracujSource, new JobicoSource, new JoobleSource];
        $enabled = $settings['sources'] ?? [];
        $all = [];

        foreach ($sources as $source) {
            if ($this->stopped($run, __('the remaining sources'))) {
                break;
            }
            $key = $source->key();
            if (empty($enabled[$key])) {
                $run->appendLog(__('[:source] disabled, skipping', ['source' => $key]));

                continue;
            }
            try {
                $items = $source->fetch($settings, new SourceHttp($run, $key));
                $stats['fetched'][$key] = count($items);
                $run->appendLog(__('[:source] vacancies fetched: :count', ['source' => $key, 'count' => count($items)]));
                $all = array_merge($all, $items);
            } catch (\Throwable $e) {
                $stats['errors'][$key] = $e->getMessage();
                $run->appendLog(__('[:source] error: :error', ['source' => $key, 'error' => $e->getMessage()]));
                // Sources swallow ordinary bad responses themselves, so an exception here
                // means the source is broken and silently returning nothing otherwise.
                $this->alert($run, $settings, __('⚠️ Source :source failed: :error', ['source' => $key, 'error' => $e->getMessage()]));
            }
        }

        return $all;
    }

    /**
     * @param  VacancyData[]  $fetched
     * @return Collection<int, Vacancy> re-posted vacancies worth sending to Telegram
     */
    private function storeNew(Run $run, array $fetched, array $settings, array &$stats): Collection
    {
        $include = array_filter(array_map('mb_strtolower', $settings['include_keywords'] ?? []));
        $exclude = array_filter(array_map('mb_strtolower', $settings['exclude_keywords'] ?? []));
        $reposted = collect();
        $new = 0;
        $bumped = 0;

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

                continue;
            }
            if (! $this->bump($run, $vacancy, $item)) {
                continue;
            }
            $bumped++;
            if ($this->worthReposting($vacancy)) {
                $reposted->push($vacancy);
            }
        }

        $stats['new'] = $new;
        $stats['bumped'] = $bumped;

        return $reposted;
    }

    /**
     * Boards refresh pubDate when a recruiter re-posts an old vacancy: it is the same row
     * with the same text, so the old verdict still stands and nothing is re-scored. It only
     * moves back to the top by date. The description is left alone too: sources like
     * LinkedIn only load it for rows they do not know yet, and would overwrite it with null.
     */
    private function bump(Run $run, Vacancy $vacancy, VacancyData $item): bool
    {
        if ($item->publishedAt === null
            || $vacancy->published_at === null
            || ! $item->publishedAt->greaterThan($vacancy->published_at)) {
            return false;
        }

        $vacancy->update([
            'published_at' => $item->publishedAt,
            'bumped_at' => now(),
            'run_id' => $run->id,
        ]);
        $run->appendLog(__('[:id] bumped by the source to :date, status :status kept without rescoring', [
            'id' => $vacancy->id,
            'date' => $item->publishedAt->format('d.m.Y H:i'),
            'status' => $vacancy->status,
        ]));

        return true;
    }

    /**
     * A repost is worth a message only when the vacancy already passed scoring and the user
     * has not applied yet. One still waiting to be scored is left to scoreNew().
     */
    private function worthReposting(Vacancy $vacancy): bool
    {
        return in_array($vacancy->status, ['matched', 'done'], true) && $vacancy->applied_at === null;
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

    /** @return Collection<int, Vacancy> vacancies that got the matched status in this pass */
    private function scoreNew(Run $run, Resume $resume, array $settings, array &$stats): Collection
    {
        $matched = collect();
        $pending = Vacancy::query()->where('status', 'new')->orderBy('id')->get();
        if ($pending->isEmpty()) {
            return $matched;
        }
        $run->appendLog(__('Scoring with Claude: :count vacancies...', ['count' => $pending->count()]));
        $minScore = (int) ($settings['min_score'] ?? 70);
        $knownLanguages = array_values((array) ($settings['known_languages'] ?? ['English', 'Russian', 'Ukrainian']));
        $weights = VacancyScorer::weights($settings);
        $scored = 0;
        $foreignCount = 0;
        /** @var array<int, array{vacancy: Vacancy, score: int, result: array}> $toRecheck */
        $toRecheck = [];

        foreach ($pending->chunk(self::SCORE_BATCH_SIZE) as $batch) {
            if ($this->stopped($run, __('the rest of scoring'))) {
                break;
            }
            try {
                $results = $this->scorer->scoreBatch($resume, $batch, $knownLanguages);
            } catch (\Throwable $e) {
                $run->appendLog(__('Batch scoring failed: :error', ['error' => $e->getMessage()]));

                continue;
            }

            foreach ($batch as $vacancy) {
                $result = $results[$vacancy->id] ?? null;
                if ($result === null) {
                    $run->appendLog(__('[:id] invalid scoring answer, left for the next run', ['id' => $vacancy->id]));

                    continue;
                }
                $score = $this->finalScore($result, $weights);
                $scored++;
                $foreign = $this->foreignLanguage($result, $knownLanguages);

                // Near the threshold a single verdict decides matched/rejected, so those
                // get re-scored individually and settled by median instead. A posting in a
                // language the candidate does not know is rejected whatever the score, so
                // re-scoring it would only burn tokens.
                if ($foreign === null && abs($score - $minScore) <= VacancyScorer::RECHECK_MARGIN) {
                    $toRecheck[] = ['vacancy' => $vacancy, 'score' => $score, 'result' => $result];

                    continue;
                }
                if ($foreign !== null) {
                    $foreignCount++;
                }
                if ($this->persistScore($vacancy, $score, $result, $weights, $minScore, foreign: $foreign)) {
                    $matched->push($vacancy);
                }
            }
            $run->appendLog(__('Scored: :scored/:total', ['scored' => $scored, 'total' => $pending->count()]));
        }

        foreach ($toRecheck as $item) {
            if ($this->stopped($run, __('the rest of rechecks'))) {
                break;
            }
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
                    $run->appendLog(__('[:id] recheck failed: :error', ['id' => $vacancy->id, 'error' => $e->getMessage()]));

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
                __('[:id] score :score is close to the threshold :threshold: recheck [:runs] → median :median', [
                    'id' => $vacancy->id,
                    'score' => $item['score'],
                    'threshold' => $minScore,
                    'runs' => implode(', ', $runScores),
                    'median' => $median,
                ])
                . ($chosen['score'] === $median ? '' : __(', final :score', ['score' => $chosen['score']])),
            );

            // The first verdict found the language known; the chosen run gets the final say.
            $foreign = $this->foreignLanguage($chosen['result'], $knownLanguages);
            if ($foreign !== null) {
                $foreignCount++;
            }
            if ($this->persistScore($vacancy, $chosen['score'], $chosen['result'], $weights, $minScore, $runScores, $foreign)) {
                $matched->push($vacancy);
            }
        }

        if ($foreignCount > 0) {
            $run->appendLog(__('Rejected by vacancy language: :count (not among the known languages: :languages)', ['count' => $foreignCount, 'languages' => implode(', ', $knownLanguages)]));
        }
        $stats['scored'] = $scored;
        $stats['rejected_language'] = $foreignCount;
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
     * The language a posting is written in when the candidate knows none of it, null when
     * it is known or the model did not say. The model has answered with free text before
     * ("English (with Ukrainian UI labels)", "German company, posting written in English"),
     * so a known language named anywhere in it as a whole word counts; a bilingual posting
     * passes the same way.
     *
     * @param  string[]  $knownLanguages
     */
    private function foreignLanguage(array $result, array $knownLanguages): ?string
    {
        $language = trim((string) ($result['language']['vacancy_language'] ?? ''));
        if ($language === '') {
            return null;
        }
        foreach (array_filter(array_map('trim', $knownLanguages)) as $known) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($known, '/') . '(?![\p{L}\p{N}])/iu', $language)) {
                return null;
            }
        }

        return $language;
    }

    /**
     * @param  int[]|null  $runScores
     * @param  string|null  $foreign  the posting's language when the candidate does not know it
     * @return bool whether the vacancy ended up matched
     */
    private function persistScore(
        Vacancy $vacancy,
        int $score,
        array $result,
        array $weights,
        int $minScore,
        ?array $runScores = null,
        ?string $foreign = null,
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
        if ($foreign !== null) {
            $breakdown['foreign_language'] = $foreign;
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
            'status' => $score >= $minScore && $foreign === null ? 'matched' : 'rejected',
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
            $run->appendLog(__('Telegram is enabled, but token/chat_id are not set, notifications skipped.'));

            return;
        }

        $run->appendLog(__('Sending to Telegram: :count vacancies...', ['count' => $matched->count()]));
        $sent = 0;
        $skipped = 0;
        $muted = 0;
        // The same job sits on several boards under different external ids, so the chat would
        // get it once per source. One message a day per job is enough, whoever posted it.
        $sentToday = Vacancy::query()->where('notified_at', '>=', today())->get(['company', 'title'])
            ->mapWithKeys(fn (Vacancy $v) => [$v->duplicateKey() => true])->all();
        // The mute button marks a row, but silences the job behind it: its copies on the other
        // boards are the same vacancy and must stay quiet too.
        $mutedJobs = Vacancy::query()->whereNotNull('muted_at')->get(['company', 'title'])
            ->mapWithKeys(fn (Vacancy $v) => [$v->duplicateKey() => true])->all();

        foreach ($matched as $vacancy) {
            $key = $vacancy->duplicateKey();
            if (isset($mutedJobs[$key])) {
                $run->appendLog(__("[:id] muted with the «don't send» button, skipped", ['id' => $vacancy->id]));
                $muted++;

                continue;
            }
            if (isset($sentToday[$key])) {
                $run->appendLog(__('[:id] already sent today, :source skipped', ['id' => $vacancy->id, 'source' => $vacancy->source]));
                $skipped++;

                continue;
            }
            try {
                $this->telegram->sendVacancy($token, $chatId, $vacancy);
                $vacancy->update(['notified_at' => now()]);
                $sentToday[$key] = true;
                $sent++;
            } catch (\Throwable $e) {
                $run->appendLog(__('[:id] Telegram not sent: :error', ['id' => $vacancy->id, 'error' => $e->getMessage()]));
            }
        }

        $run->appendLog(__('Sent to Telegram: :sent/:total', ['sent' => $sent, 'total' => $matched->count()])
            . ($skipped ? __(', duplicates from today skipped: :count', ['count' => $skipped]) : '')
            . ($muted ? __(', muted skipped: :count', ['count' => $muted]) : ''));
        $stats['notified'] = $sent;
    }

    /**
     * A one-off warning to the same Telegram chat the vacancies go to. It must never
     * take the run down with it, so a misconfigured or unreachable bot only gets logged.
     */
    private function alert(Run $run, array $settings, string $text): void
    {
        $token = trim((string) ($settings['telegram_bot_token'] ?? ''));
        $chatId = trim((string) ($settings['telegram_chat_id'] ?? ''));
        if (empty($settings['telegram_enabled']) || $token === '' || $chatId === '') {
            return;
        }

        try {
            // sendText posts with parse_mode=HTML, and the text carries a third-party
            // string (the host a broken source reports), so it is escaped like
            // TelegramNotifier::formatMessage() escapes everything it interpolates.
            $this->telegram->sendText($token, $chatId, htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
        } catch (\Throwable $e) {
            // A connection error carries the full request URL, and the token sits in it.
            $run->appendLog(__('Alert not sent: :error', ['error' => str_replace($token, '***', $e->getMessage())]));
        }
    }

    /**
     * Checkpoint for a stop requested from the web: it is checked between items, so whatever
     * is in flight (a source fetch, a Claude call) finishes first and the run ends within one
     * item rather than instantly. Notification is deliberately left outside this: a vacancy
     * that already got its verdict here is never revisited, so its message must still go out.
     */
    private function stopped(Run $run, string $skipped): bool
    {
        if (! $run->stopRequested()) {
            return false;
        }
        $run->appendLog(__('Stop requested, skipping: :skipped', ['skipped' => $skipped]));

        return true;
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
            $run->appendLog(__('Company research: limit :limit per run, skipped: :companies', ['limit' => self::RESEARCH_PER_RUN_LIMIT, 'companies' => $skipped->implode(', ')]));
            $names = $names->take(self::RESEARCH_PER_RUN_LIMIT);
        }

        $run->appendLog(__('Company research: :count...', ['count' => $names->count()]));
        $researched = 0;

        foreach ($names as $name) {
            if ($this->stopped($run, __('the rest of the research'))) {
                break;
            }
            $company = Company::firstOrCreateForName($name);
            if ($this->researcher->isFresh($company)) {
                $run->appendLog(__('[:company] cache is fresh, skipping', ['company' => $company->name]));

                continue;
            }
            $flag = "company-researching:{$company->id}";
            if (Cache::has($flag)) {
                $run->appendLog(__('[:company] research already running, skipping', ['company' => $company->name]));

                continue;
            }

            Cache::put($flag, true, now()->addMinutes(20));
            try {
                $context = $matched->first(fn (Vacancy $v) => $v->company && Company::normalize($v->company) === $company->normalized_name);
                $this->researcher->research($company, $context);
                $researched++;
                $run->appendLog(__('[:company] research done', ['company' => $company->name]));
            } catch (\Throwable $e) {
                $company->update(['last_error' => $e->getMessage()]);
                $run->appendLog(__('[:company] research failed: :error', ['company' => $company->name, 'error' => $e->getMessage()]));
            } finally {
                Cache::forget($flag);
            }
        }

        $stats['researched'] = $researched;
    }
}
