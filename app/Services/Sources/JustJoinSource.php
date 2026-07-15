<?php

namespace App\Services\Sources;

use App\Models\Vacancy;
use Carbon\Carbon;

class JustJoinSource implements JobSourceInterface
{
    private const API_HEADERS = ['Version' => '2', 'Accept' => 'application/json'];
    private const MAX_DETAIL_FETCHES = 20;

    public function key(): string
    {
        return 'justjoin';
    }

    public function fetch(array $settings, SourceHttp $http): array
    {
        $category = (int) ($settings['justjoin_category'] ?? 3);
        $base = "https://api.justjoin.it/v2/user-panel/offers?categories[]={$category}&page=1&perPage=100&sortBy=published&orderBy=DESC";

        $urls = [$base];
        foreach (array_slice($settings['search_keywords'] ?? [], 0, 3) as $kw) {
            $urls[] = $base . '&keywords[]=' . urlencode($kw);
        }

        $result = [];
        foreach ($urls as $url) {
            $response = $http->get($url, self::API_HEADERS);
            if (! $response || ! $response->successful()) {
                continue;
            }
            foreach ($response->json('data') ?? [] as $offer) {
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

        $this->enrichNewOffers($result, $http);

        return array_values($result);
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
        $skills = implode(', ', $offer['requiredSkills'] ?? []);

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
     * Full description lives in the v1 detail endpoint; fetch it only for offers not yet in the DB.
     *
     * @param  array<string, VacancyData>  $offers
     */
    private function enrichNewOffers(array $offers, SourceHttp $http): void
    {
        if ($offers === []) {
            return;
        }
        $known = Vacancy::query()
            ->where('source', $this->key())
            ->whereIn('external_id', array_keys($offers))
            ->pluck('external_id')
            ->all();
        $new = array_diff_key($offers, array_flip($known));

        foreach (array_slice($new, 0, self::MAX_DETAIL_FETCHES, preserve_keys: true) as $vacancy) {
            $response = $http->get("https://api.justjoin.it/v1/offers/{$vacancy->externalId}", self::API_HEADERS);
            if (! $response || ! $response->successful()) {
                continue;
            }
            $body = $response->json('body');
            if ($body) {
                $vacancy->description = $body;
            }
        }
    }
}
