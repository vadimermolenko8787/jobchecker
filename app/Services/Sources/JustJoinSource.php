<?php

namespace App\Services\Sources;

use App\Models\Vacancy;
use Carbon\Carbon;

/**
 * justjoin.it via the candidate gateway its own frontend calls (`cpApiUrl` in the page
 * config). The old api.justjoin.it answers 503 to everything since 2026-08-21.
 */
class JustJoinSource implements JobSourceInterface
{
    private const GATEWAY = 'https://justjoin.it/api/candidate-api';

    private const API_HEADERS = ['Accept' => 'application/json'];

    private const MAX_DETAIL_FETCHES = 20;

    /**
     * The setting stores the numeric category of the old API; the gateway takes its key.
     * Both come from `/job-categories` (`coreId` => `key`).
     */
    private const CATEGORY_KEYS = [
        1 => 'javascript', 2 => 'html', 3 => 'php', 4 => 'ruby', 5 => 'python', 6 => 'java',
        7 => 'net', 8 => 'scala', 9 => 'c', 10 => 'mobile', 11 => 'testing', 12 => 'devops',
        13 => 'admin', 14 => 'ux', 15 => 'pm', 16 => 'game', 17 => 'analytics', 18 => 'security',
        19 => 'data', 20 => 'go', 21 => 'support', 22 => 'erp', 23 => 'architecture', 24 => 'other',
        25 => 'ai',
    ];

    public function key(): string
    {
        return 'justjoin';
    }

    public function fetch(array $settings, SourceHttp $http): array
    {
        $category = self::CATEGORY_KEYS[(int) ($settings['justjoin_category'] ?? 3)] ?? 'php';
        $base = self::GATEWAY . "/offers?categories={$category}&itemsCount=100&sortBy=publishedAt&orderBy=descending";

        $urls = [$base];
        foreach (array_slice($settings['search_keywords'] ?? [], 0, 3) as $kw) {
            $urls[] = $base . '&keywords=' . urlencode($kw) . '&keywordType=any';
        }

        $result = [];
        $answered = false;
        foreach ($urls as $url) {
            $response = $http->get($url, self::API_HEADERS);
            $offers = $response?->successful() ? $response->json('data') : null;
            if (! is_array($offers)) {
                continue;
            }
            $answered = true;
            foreach ($offers as $offer) {
                $slug = $offer['slug'] ?? null;
                if (! $slug || isset($result[$slug])) {
                    continue;
                }
                if (! empty($settings['remote_only']) && ($offer['workplaceType'] ?? null) !== 'remote') {
                    continue;
                }
                $result[$slug] = $this->toVacancy($offer);
            }
        }

        // Otherwise a dead gateway passes for "nothing new today", as it did for five weeks.
        if (! $answered) {
            throw new \RuntimeException(__('gateway :gateway does not answer with the expected JSON, no vacancies received', ['gateway' => self::GATEWAY]));
        }

        return array_values($this->enrichNewOffers($result, $http));
    }

    private function toVacancy(array $offer): VacancyData
    {
        $salary = null;
        foreach ($offer['employmentTypes'] ?? [] as $et) {
            if (isset($et['from'], $et['to'])) {
                $salary = "{$et['from']}–{$et['to']} " . strtoupper($et['currency'] ?? '') . ' (' . ($et['type'] ?? '') . ')';
                break;
            }
        }
        $skills = implode(', ', array_column($offer['requiredSkills'] ?? [], 'name'));

        return new VacancyData(
            source: $this->key(),
            externalId: $offer['slug'],
            title: $offer['title'] ?? '',
            company: $offer['companyName'] ?? null,
            location: ($offer['city'] ?? '') . (($offer['workplaceType'] ?? '') === 'remote' ? ' (remote)' : ''),
            url: 'https://justjoin.it/job-offer/' . $offer['slug'],
            description: $skills ? "Required skills: {$skills}" : null,
            salary: $salary,
            publishedAt: isset($offer['publishedAt']) ? Carbon::parse($offer['publishedAt']) : null,
            raw: $offer,
        );
    }

    /**
     * Full description lives in the detail endpoint; fetch it only for offers not yet in the DB.
     * New offers past the cap are held back for the next run: once stored, an offer is never
     * fetched again, and with the skill list alone the scorer reads the English skill names
     * as the posting's language, so a Polish one slips past the language filter.
     *
     * @param  array<string, VacancyData>  $offers
     * @return array<string, VacancyData>
     */
    private function enrichNewOffers(array $offers, SourceHttp $http): array
    {
        if ($offers === []) {
            return [];
        }
        $known = Vacancy::query()
            ->where('source', $this->key())
            ->whereIn('external_id', array_keys($offers))
            ->pluck('external_id')
            ->all();
        $new = array_diff_key($offers, array_flip($known));
        $offers = array_diff_key($offers, array_slice($new, self::MAX_DETAIL_FETCHES, preserve_keys: true));

        foreach (array_slice($new, 0, self::MAX_DETAIL_FETCHES, preserve_keys: true) as $vacancy) {
            $response = $http->get(self::GATEWAY . "/offers/{$vacancy->externalId}", self::API_HEADERS);
            if (! $response || ! $response->successful()) {
                continue;
            }
            $body = $response->json('body');
            if ($body) {
                $vacancy->description = $body;
            }
        }

        return $offers;
    }
}
