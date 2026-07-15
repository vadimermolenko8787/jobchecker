<?php

namespace App\Services;

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

class Pipeline
{
    private const SCORE_BATCH_SIZE = 10;
    private const DESCRIPTION_SNIPPET = 1500;

    public function __construct(
        private ClaudeCli $claude,
        private DocumentGenerator $generator,
        private TelegramNotifier $telegram,
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
                $run->appendLog('Резюме не загружено — scoring и генерация пропущены.');
            } else {
                $matched = $this->scoreNew($run, $resume, $settings, $stats);
                $this->notifyMatched($run, $matched, $settings, $stats);
                $this->generateDocuments($run, $resume, $settings, $stats);
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
                . "VACANCIES (JSON):\n" . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n"
                . "For EVERY vacancy rate how well it matches the candidate (skills, seniority, stack, location/remote fit) "
                . "on a 0-100 scale. Respond with ONLY a JSON array, no other text:\n"
                . '[{"id": <vacancy id>, "score": <0-100>, "reason": "<one short sentence in English>"}]';

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

    private function generateDocuments(Run $run, Resume $resume, array $settings, array &$stats): void
    {
        $limit = (int) ($settings['max_generate_per_run'] ?? 5);
        if ($limit === 0) {
            $run->appendLog('Генерация после поиска отключена (лимит 0).');

            return;
        }
        $matched = Vacancy::query()
            ->where('status', 'matched')
            ->orderByDesc('score')
            ->limit($limit)
            ->get();
        if ($matched->isEmpty()) {
            return;
        }
        $run->appendLog('Генерация документов для ' . $matched->count() . ' вакансий...');
        $generated = 0;

        foreach ($matched as $vacancy) {
            try {
                $this->generator->generate($resume, $vacancy);
                $generated++;
                $run->appendLog("[{$vacancy->id}] {$vacancy->title} — резюме и cover letter готовы");
            } catch (\Throwable $e) {
                $run->appendLog("[{$vacancy->id}] генерация не удалась: {$e->getMessage()}");
            }
        }

        $stats['generated'] = $generated;
    }
}
