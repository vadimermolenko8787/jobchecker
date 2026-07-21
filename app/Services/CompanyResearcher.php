<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Setting;
use App\Models\Vacancy;

class CompanyResearcher
{
    private const ASPECTS = ['turnover', 'work_life_balance', 'ceo', 'compensation'];

    public function __construct(private ClaudeCli $claude)
    {
    }

    /**
     * Research the company as an employer via Claude with web tools and
     * persist the result. The vacancy, when given, only helps identify
     * the right company.
     *
     * @throws \RuntimeException on CLI failure or unusable response
     */
    public function research(Company $company, ?Vacancy $context = null): array
    {
        $decoded = $this->claude->json(
            $this->buildPrompt($company, $context),
            timeout: (int) config('jobchecker.research_timeout'),
            allowedTools: ['WebSearch', 'WebFetch'],
        );
        $research = $this->validate($decoded);

        $company->update([
            'research' => $research,
            'researched_at' => now(),
            'last_error' => null,
        ]);

        return $research;
    }

    public function isFresh(Company $company, ?int $ttlDays = null): bool
    {
        $ttlDays ??= (int) Setting::get('company_research_ttl_days');

        return $company->research !== null
            && $company->researched_at !== null
            && $company->researched_at->gt(now()->subDays(max(1, $ttlDays)));
    }

    private function buildPrompt(Company $company, ?Vacancy $context): string
    {
        $contextLine = $context
            ? "CONTEXT (helps identify the right company): job posting \"{$context->title}\", "
                . 'location: ' . ($context->location ?: '—') . ", posting url: {$context->url}\n"
            : '';

        return "You are researching an employer for a job seeker.\n\n"
            . "COMPANY: \"{$company->name}\"\n"
            . $contextLine . "\n"
            . "Use WebSearch (and WebFetch where pages are accessible) to research this company as an employer.\n"
            . "Check MULTIPLE review sources: Glassdoor, Indeed company reviews, Kununu, DOU (dou.ua), Habr Career, "
            . "LinkedIn, plus the company website for basic facts. Review sites often block direct fetching — "
            . "rely on search result snippets in that case and say so in the notes.\n\n"
            . "Research specifically: (a) employee turnover, (b) work-life balance / overtime culture, "
            . "(c) attitude towards the CEO and management (approval), (d) compensation vs market.\n\n"
            . "Respond with ONLY a JSON object, no other text. ALL free-text fields must be in RUSSIAN:\n"
            . <<<'JSON'
            {
              "found": true,
              "company_name": "<canonical company name>",
              "company_info": "<2-3 предложения: чем занимается, размер, где штаб-квартира>",
              "sources": [
                {"name": "Glassdoor", "url": "<url or null>", "rating": <number 1-5 or null>,
                 "reviews_count": <int or null>, "key_points": ["<короткий тезис на русском>", ...]}
              ],
              "aspects": {
                "turnover":          {"assessment": "low"|"medium"|"high"|"unknown", "note": "<1-2 предложения>"},
                "work_life_balance": {"assessment": "good"|"mixed"|"poor"|"unknown", "note": "<...>"},
                "ceo":               {"assessment": "positive"|"mixed"|"negative"|"unknown", "approval_percent": <int or null>, "note": "<...>"},
                "compensation":      {"assessment": "above_market"|"market"|"below_market"|"unknown", "note": "<...>"}
              },
              "verdict": "<итог на русском, 3-5 предложений: стоит ли туда идти и на что обратить внимание>"
            }
            JSON
            . "\n\nInclude a rating ONLY if you actually saw it in search results or on the page — never invent numbers.\n"
            . "If you cannot reliably identify the company or find any reviews, return \"found\": false "
            . "with \"unknown\" assessments and explain the situation in \"verdict\".";
    }

    /** Shape-check the LLM response so the view never sees garbage. */
    private function validate(mixed $decoded): array
    {
        if (! is_array($decoded)) {
            throw new \RuntimeException('Company research: ответ не является JSON-объектом.');
        }

        $sources = [];
        foreach ((array) ($decoded['sources'] ?? []) as $source) {
            if (! is_array($source) || ! is_string($source['name'] ?? null)) {
                continue;
            }
            $sources[] = [
                'name' => $source['name'],
                'url' => is_string($source['url'] ?? null) ? $source['url'] : null,
                'rating' => is_numeric($source['rating'] ?? null) ? round((float) $source['rating'], 1) : null,
                'reviews_count' => is_numeric($source['reviews_count'] ?? null) ? (int) $source['reviews_count'] : null,
                'key_points' => array_values(array_filter((array) ($source['key_points'] ?? []), 'is_string')),
            ];
        }

        $aspects = [];
        foreach (self::ASPECTS as $key) {
            $aspect = $decoded['aspects'][$key] ?? null;
            $aspects[$key] = [
                'assessment' => is_string($aspect['assessment'] ?? null) ? $aspect['assessment'] : 'unknown',
                'note' => is_string($aspect['note'] ?? null) ? $aspect['note'] : '',
            ];
            if ($key === 'ceo') {
                $aspects[$key]['approval_percent'] = is_numeric($aspect['approval_percent'] ?? null)
                    ? (int) $aspect['approval_percent']
                    : null;
            }
        }

        return [
            'found' => (bool) ($decoded['found'] ?? false),
            'company_name' => is_string($decoded['company_name'] ?? null) ? $decoded['company_name'] : '',
            'company_info' => is_string($decoded['company_info'] ?? null) ? $decoded['company_info'] : '',
            'sources' => $sources,
            'aspects' => $aspects,
            'verdict' => is_string($decoded['verdict'] ?? null) ? $decoded['verdict'] : '',
        ];
    }
}
