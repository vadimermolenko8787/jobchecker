<?php

namespace App\Services\Sources;

use Carbon\Carbon;

class IndeedSource implements JobSourceInterface
{
    private const REQUEST_DELAY_SECONDS = 1;

    /**
     * Indeed ANDs the terms in `what`, so each extra keyword narrows the search: three
     * ("PHP Laravel Yii2") demand all of them in one ad and return almost nothing, while
     * two fill the whole limit with on-stack results. This matches LinkedInSource.
     */
    private const KEYWORD_LIMIT = 2;

    public function key(): string
    {
        return 'indeed';
    }

    public function fetch(array $settings, SourceHttp $http): array
    {
        $apiKey = trim((string) ($settings['indeed_api_key'] ?? ''));
        if ($apiKey === '') {
            throw new \RuntimeException(__('Indeed API key is not set in the source settings'));
        }

        $what = implode(' ', array_slice($settings['search_keywords'] ?? [], 0, self::KEYWORD_LIMIT)) ?: 'PHP';
        $locations = array_values(($settings['locations'] ?? []) ?: ['Germany']);

        $result = [];
        $skipped = [];
        foreach ($locations as $i => $entry) {
            $location = LocationCatalog::resolve($entry);
            if ($location['indeed'] === null) {
                $skipped[] = $location['name'];

                continue;
            }
            if ($i > 0) {
                sleep(self::REQUEST_DELAY_SECONDS);
            }
            foreach ($this->fetchLocation($http, $apiKey, $what, $location) as $vacancy) {
                $result[$vacancy->externalId] ??= $vacancy;
            }
        }

        if ($skipped !== []) {
            $http->log(__('skipped locations without a country code: :locations', ['locations' => implode(', ', $skipped)]));
        }

        return array_values($result);
    }

    /**
     * @param  array{name: string, indeed: ?string, host: ?string}  $location
     * @return VacancyData[]
     */
    private function fetchLocation(SourceHttp $http, string $apiKey, string $what, array $location): array
    {
        $where = $location['name'];
        $country = $location['indeed'];

        $query = <<<GQL
        query {
          jobSearch(
            what: "{$this->escape($what)}"
            location: {where: "{$this->escape($where)}", radius: 35, radiusUnit: MILES}
            limit: 100
            sort: RELEVANCE
          ) {
            results {
              job {
                key
                title
                datePublished
                description { html }
                location { city countryCode formatted { long } }
                compensation { baseSalary { unitOfWork range { ... on Range { min max } } } }
                employer { name }
              }
            }
          }
        }
        GQL;

        $response = $http->post('https://apis.indeed.com/graphql', [
            'Content-Type' => 'application/json',
            'indeed-api-key' => $apiKey,
            'indeed-co' => $country,
            'indeed-locale' => 'en-US',
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Indeed App 193.1',
            'indeed-app-info' => 'appv=193.1; appid=com.indeed.jobsearch; osv=16.6.1; os=ios; dtype=phone',
        ], ['query' => $query]);

        if (! $response || ! $response->successful()) {
            // Most often an unsupported country code: Indeed answers 400 for markets
            // it does not run, and the location would otherwise fail silently.
            $http->log("«{$where}» ({$country}): " . ($response ? 'HTTP ' . $response->status() : __('request failed')));

            return [];
        }

        $host = $location['host'] ?? LocationCatalog::indeedHost($country);
        $result = [];
        foreach ($response->json('data.jobSearch.results') ?? [] as $item) {
            $job = $item['job'] ?? null;
            if (! $job || empty($job['key'])) {
                continue;
            }
            $salary = null;
            $range = $job['compensation']['baseSalary']['range'] ?? null;
            if (isset($range['min'], $range['max'])) {
                $salary = "{$range['min']}–{$range['max']} / " . strtolower($job['compensation']['baseSalary']['unitOfWork'] ?? '');
            }
            $result[] = new VacancyData(
                source: $this->key(),
                externalId: $job['key'],
                title: $job['title'] ?? '',
                company: $job['employer']['name'] ?? null,
                location: $job['location']['formatted']['long'] ?? ($job['location']['city'] ?? null),
                url: "https://{$host}/viewjob?jk={$job['key']}",
                description: $job['description']['html'] ?? null,
                salary: $salary,
                publishedAt: isset($job['datePublished']) ? Carbon::createFromTimestampMs($job['datePublished']) : null,
                raw: $job,
            );
        }

        return $result;
    }

    private function escape(string $value): string
    {
        return addcslashes($value, '"\\');
    }
}
