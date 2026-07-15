<?php

namespace App\Services\Sources;

use Carbon\Carbon;

class IndeedSource implements JobSourceInterface
{
    // Indeed's own mobile-app key, publicly known via the JobSpy project.
    // If Indeed rotates it, pull the current one from github.com/speedyapply/JobSpy.
    private const API_KEY = '161092c2017b5bbab13edb12461a62d5a833871e7cad6d9d475304573de67ac8';

    public function key(): string
    {
        return 'indeed';
    }

    public function fetch(array $settings, SourceHttp $http): array
    {
        $what = implode(' ', array_slice($settings['search_keywords'] ?? [], 0, 3)) ?: 'PHP';
        $where = ($settings['locations'] ?? ['Germany'])[0] ?? 'Germany';
        $country = strtoupper($settings['indeed_country'] ?? 'DE');

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
            'indeed-api-key' => self::API_KEY,
            'indeed-co' => $country,
            'indeed-locale' => 'en-US',
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Indeed App 193.1',
            'indeed-app-info' => 'appv=193.1; appid=com.indeed.jobsearch; osv=16.6.1; os=ios; dtype=phone',
        ], ['query' => $query]);

        if (! $response || ! $response->successful()) {
            return [];
        }

        $host = $country === 'US' ? 'www.indeed.com' : strtolower($country) . '.indeed.com';
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
