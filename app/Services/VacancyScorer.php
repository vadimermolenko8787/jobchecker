<?php

namespace App\Services;

use App\Models\Resume;
use App\Models\Setting;
use App\Models\Vacancy;
use Illuminate\Support\Collection;

/**
 * Rubric scoring: the model rates four criteria 0-10 with evidence, the final
 * 0-100 score is computed here from configurable weights. Keeping the arithmetic
 * in PHP makes the score reproducible and explainable, which a single "rate this
 * 0-100" call never was.
 */
class VacancyScorer
{
    public const CRITERIA = ['skills', 'stack', 'seniority', 'location'];

    public const LABELS = [
        'skills' => 'Навыки',
        'stack' => 'Стек',
        'seniority' => 'Уровень',
        'location' => 'Локация и формат',
    ];

    /** Vacancies within this many points of min_score get re-scored individually. */
    public const RECHECK_MARGIN = 7;

    public const RECHECK_EXTRA_RUNS = 2;

    private const EVIDENCE_LIMIT = 5;
    private const EVIDENCE_LENGTH = 140;

    public function __construct(private ClaudeCli $claude)
    {
    }

    /**
     * Score a batch in one CLI call.
     *
     * @param  Collection<int, Vacancy>  $batch
     * @param  string[]  $knownLanguages
     * @return array<int, array{criteria: array, reason: string, summary: ?string, language: ?array}> keyed by vacancy id
     */
    public function scoreBatch(Resume $resume, Collection $batch, array $knownLanguages): array
    {
        $answers = $this->claude->json($this->prompt($resume, $batch, $knownLanguages));
        $answers = $this->unwrapList($answers);
        $ids = $batch->pluck('id')->all();
        $out = [];

        foreach ($answers as $answer) {
            $id = (int) ($answer['id'] ?? 0);
            if (! in_array($id, $ids, true)) {
                continue;
            }
            if ($parsed = $this->parseAnswer($answer)) {
                $out[$id] = $parsed;
            }
        }

        return $out;
    }

    /**
     * Score a single vacancy on its own — used for the re-check pass near the
     * threshold, where batch anchoring is exactly what we want to avoid.
     *
     * @param  string[]  $knownLanguages
     * @return array{criteria: array, reason: string, summary: ?string, language: ?array}|null
     */
    public function scoreSingle(Resume $resume, Vacancy $vacancy, array $knownLanguages): ?array
    {
        $answers = $this->unwrapList($this->claude->json($this->prompt($resume, collect([$vacancy]), $knownLanguages)));

        return $this->parseAnswer($answers[0] ?? null);
    }

    /**
     * @param  array<string, array{score: int}>  $criteria
     * @param  array<string, int>  $weights  raw (un-normalized) weights
     */
    public function computeScore(array $criteria, array $weights): int
    {
        $shares = $this->normalizeWeights($weights);
        $sum = 0.0;

        foreach (self::CRITERIA as $key) {
            $score = max(0, min(10, (int) ($criteria[$key]['score'] ?? 0)));
            $sum += $score * 10 * $shares[$key];
        }

        return max(0, min(100, (int) round($sum)));
    }

    /**
     * The language barrier is applied after aggregation instead of being folded
     * into the model's own number, so the penalty stays visible in the breakdown.
     */
    public function applyLanguagePenalty(int $score, ?string $fit): int
    {
        return match ($fit) {
            'critical' => min($score, 40),
            'warning' => max(0, $score - 15),
            default => $score,
        };
    }

    /**
     * @param  array<string, mixed>  $weights
     * @return array<string, float> shares summing to 1
     */
    public function normalizeWeights(array $weights): array
    {
        $clean = [];
        foreach (self::CRITERIA as $key) {
            $clean[$key] = max(0, (int) ($weights[$key] ?? 0));
        }
        if (array_sum($clean) <= 0) {
            $clean = Setting::DEFAULTS['score_weights'];
        }
        $total = array_sum($clean);

        return array_map(fn (int $w) => $w / $total, $clean);
    }

    /** Raw weights from settings, with every criterion guaranteed present. */
    public static function weights(array $settings): array
    {
        return array_replace(
            Setting::DEFAULTS['score_weights'],
            array_intersect_key((array) ($settings['score_weights'] ?? []), Setting::DEFAULTS['score_weights']),
        );
    }

    /** @param int[] $scores */
    public function median(array $scores): int
    {
        $scores = array_values(array_map('intval', $scores));
        sort($scores);
        $count = count($scores);
        if ($count === 0) {
            return 0;
        }
        $mid = intdiv($count, 2);

        return $count % 2 === 1
            ? $scores[$mid]
            : (int) round(($scores[$mid - 1] + $scores[$mid]) / 2);
    }

    /**
     * @return array{criteria: array, reason: string, summary: ?string, language: ?array}|null
     *                                                                                        null when the answer lacks a usable rubric — the caller leaves the vacancy for the next run
     */
    public function parseAnswer(mixed $answer): ?array
    {
        if (! is_array($answer)) {
            return null;
        }
        $criteria = $this->parseCriteria($answer['criteria'] ?? null);
        if ($criteria === null) {
            return null;
        }

        $reason = is_string($answer['reason'] ?? null) ? trim($answer['reason']) : '';
        $summary = is_string($answer['summary'] ?? null) && trim($answer['summary']) !== ''
            ? trim($answer['summary'])
            : null;

        return [
            'criteria' => $criteria,
            'reason' => $reason !== '' ? $reason : $this->fallbackReason($criteria),
            'summary' => $summary,
            'language' => $this->parseLanguage($answer['language'] ?? null),
        ];
    }

    /**
     * @return array<string, array{matched: string[], missing: string[], score: int}>|null
     */
    public function parseCriteria(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $out = [];

        foreach (self::CRITERIA as $key) {
            $item = $raw[$key] ?? null;
            if (! is_array($item) || ! is_numeric($item['score'] ?? null)) {
                return null;
            }
            $out[$key] = [
                'matched' => $this->evidence($item['matched'] ?? []),
                'missing' => $this->evidence($item['missing'] ?? []),
                'score' => max(0, min(10, (int) round((float) $item['score']))),
            ];
        }

        return $out;
    }

    /** @param array<string, array{score: int}> $criteria */
    public function fallbackReason(array $criteria): string
    {
        $parts = [];
        foreach (self::CRITERIA as $key) {
            $parts[] = $key . ' ' . ($criteria[$key]['score'] ?? 0) . '/10';
        }

        return implode(', ', $parts);
    }

    /** @return string[] */
    private function evidence(mixed $raw): array
    {
        $items = array_filter(is_array($raw) ? $raw : [], 'is_string');
        $items = array_map(fn (string $s) => mb_substr(trim($s), 0, self::EVIDENCE_LENGTH), $items);

        return array_values(array_slice(array_filter($items, fn (string $s) => $s !== ''), 0, self::EVIDENCE_LIMIT));
    }

    private function parseLanguage(mixed $lang): ?array
    {
        if (! is_array($lang)) {
            return null;
        }

        return [
            'vacancy_language' => is_string($lang['vacancy_language'] ?? null) ? $lang['vacancy_language'] : null,
            'required_languages' => array_values(array_filter((array) ($lang['required_languages'] ?? []), 'is_string')),
            'language_fit' => in_array($lang['language_fit'] ?? null, ['ok', 'warning', 'critical'], true) ? $lang['language_fit'] : null,
            'note' => is_string($lang['note'] ?? null) ? trim($lang['note']) : null,
        ];
    }

    /** The model sometimes wraps the array in {"results": [...]} or returns a bare object. */
    private function unwrapList(mixed $decoded): array
    {
        if (! is_array($decoded)) {
            return [];
        }
        if (array_is_list($decoded)) {
            return $decoded;
        }
        foreach (['results', 'vacancies', 'data'] as $key) {
            if (is_array($decoded[$key] ?? null) && array_is_list($decoded[$key])) {
                return $decoded[$key];
            }
        }

        return [$decoded];
    }

    /**
     * @param  Collection<int, Vacancy>  $batch
     * @param  string[]  $knownLanguages
     */
    private function prompt(Resume $resume, Collection $batch, array $knownLanguages): string
    {
        $payload = $batch->map(fn (Vacancy $v) => [
            'id' => $v->id,
            'title' => $v->title,
            'company' => $v->company,
            'location' => $v->location,
            'salary' => $v->salary,
            'description' => $this->plainDescription($v->description),
        ])->values()->all();

        $languages = implode(', ', $knownLanguages);

        return "You are screening job vacancies for a candidate against a fixed rubric.\n\n"
            . "CANDIDATE RESUME:\n" . $resume->text . "\n\n"
            . "CANDIDATE KNOWN LANGUAGES: {$languages}\n\n"
            . "VACANCIES (JSON):\n" . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n"
            . "Judge each vacancy INDEPENDENTLY on its own merits. Never compare the vacancies in this "
            . "batch to each other and never spread scores out to differentiate them.\n\n"
            . "For EVERY vacancy rate the four criteria below from 0 to 10. For each criterion FIRST list the "
            . "concrete evidence — \"matched\" = requirements the candidate demonstrably meets, \"missing\" = "
            . "requirements they do not — and only THEN pick the score that follows from that evidence.\n\n"
            . "Anchor scale, identical for all four criteria:\n"
            . "  9-10 = every key requirement of this criterion is covered\n"
            . "  6-8  = covered, with minor gaps that close quickly on the job\n"
            . "  3-5  = several important requirements are not met\n"
            . "  0-2  = fundamental mismatch\n\n"
            . "Criteria:\n"
            . "- skills: the engineering substance the role needs (architecture, data modelling, query/performance "
            . "work, integrations, queues, testing, code review, domain complexity), independent of product names.\n"
            . "- stack: the concrete technologies. Read the requirements LITERALLY. If the posting accepts any one "
            . "of several tools (\"Symfony, Laravel or Zend/Laminas\", \"Doctrine or another ORM\") and the candidate "
            . "knows at least one of them, that requirement is FULLY met — score it as met even when the team's "
            . "day-to-day tool is a different item from that list. A specific framework or library the candidate "
            . "has not used but which sits next to what they know (same language, same paradigm) is a MINOR gap "
            . "(6-8), not a mismatch. Score 0-5 only when the role is built on technologies the candidate has no "
            . "path into: another language, another platform, another discipline. Tools listed under "
            . "\"nice to have\" / \"буде плюсом\" must never pull the score below 9 when everything required is met.\n"
            . "- seniority: required years and level versus the candidate's. Being clearly overqualified is a gap "
            . "too, not a bonus.\n"
            . "- location: location, remote policy, timezone and work-permit fit, plus stated preferences the "
            . "candidate happens to satisfy (e.g. \"fully remote\", \"residence abroad preferred\"). If the posting "
            . "says nothing at all about location or remote work, score 6 and record the silence in \"missing\" — "
            . "never invent a restriction that is not written.\n\n"
            . "Then, for every vacancy:\n"
            . "- reason: one short sentence in English stating the overall verdict.\n"
            . "- summary: 2-3 factual sentences IN RUSSIAN covering the role, stack and conditions "
            . "(remote/salary/company type). No marketing fluff.\n"
            . "- language: the language the posting is written in, the languages the role actually requires "
            . "(explicitly stated or strongly implied, e.g. \"client communication in German\"), and how critical "
            . "the barrier is against the candidate's known languages. A posting merely written in Polish for a "
            . "developer role in a likely English-speaking team is \"warning\"; an explicit \"German C1 for client "
            . "communication\" is \"critical\". Do NOT adjust the criteria scores for the language barrier — it is "
            . "applied separately.\n\n"
            . "Respond with ONLY a JSON array, no other text:\n"
            . '[{"id": <vacancy id>, "criteria": {'
            . '"skills": {"matched": ["..."], "missing": ["..."], "score": <0-10>}, '
            . '"stack": {"matched": ["..."], "missing": ["..."], "score": <0-10>}, '
            . '"seniority": {"matched": ["..."], "missing": ["..."], "score": <0-10>}, '
            . '"location": {"matched": ["..."], "missing": ["..."], "score": <0-10>}}, '
            . '"reason": "<one short sentence in English>", '
            . '"summary": "<2-3 предложения на русском>", '
            . '"language": {"vacancy_language": "<language the posting is written in>", '
            . '"required_languages": ["<language>", ...], '
            . '"language_fit": "ok" | "warning" | "critical", '
            . '"note": "<короткое пояснение на русском; пустая строка если fit = ok>"}}]';
    }

    /**
     * Descriptions go to the model in FULL — truncating them was silently hiding
     * requirements and conditions from the rubric. Only markup and entity noise
     * is removed so the text stays cheap to read.
     */
    private function plainDescription(?string $html): string
    {
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{a0}", ' ', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
