<?php

namespace App\Services\Sources;

use Carbon\Carbon;

/**
 * jooble — an aggregator with one site per country (de.jooble.org, pl.jooble.org, ...).
 *
 * Everything but the official REST API sits behind a Cloudflare challenge, job pages
 * included, so the description is the API's snippet and nothing more. An API key is
 * issued for one country site and is refused (403) by every other one, hence a key per
 * country in the settings, and every configured country is searched.
 */
class JoobleSource implements JobSourceInterface
{
    /** The API's own page size cap. */
    private const RESULTS_PER_PAGE = 100;

    public function key(): string
    {
        return 'jooble';
    }

    public function fetch(array $settings, SourceHttp $http): array
    {
        $keys = $settings['jooble_keys'] ?? [];
        if ($keys === []) {
            $http->log(__('no API keys set, skipping'));

            return [];
        }

        $keywords = array_slice($settings['search_keywords'] ?? [], 0, 3) ?: ['PHP'];

        $result = [];
        $failed = [];
        foreach ($keys as $country => $apiKey) {
            $answered = false;
            foreach ($keywords as $kw) {
                $body = ['keywords' => $kw, 'ResultOnPage' => self::RESULTS_PER_PAGE];
                // Every country site maps "Remote" onto its own word for it (Homeoffice, Zdalna).
                if (! empty($settings['remote_only'])) {
                    $body['location'] = 'Remote';
                }
                $response = $http->post("https://{$country}.jooble.org/api/{$apiKey}", ['Accept' => 'application/json'], $body);
                $jobs = $response?->successful() ? $response->json('jobs') : null;
                if (! is_array($jobs)) {
                    continue;
                }
                $answered = true;
                foreach ($jobs as $job) {
                    $vacancy = $this->toVacancy($job, $country);
                    if ($vacancy !== null) {
                        $result[$vacancy->externalId] ??= $vacancy;
                    }
                }
            }
            if (! $answered) {
                $failed[] = $country;
            }
        }

        // The key sits in the URL, so the message names the country only.
        if ($failed !== []) {
            $message = __('API did not answer for :countries, check the keys', ['countries' => implode(', ', $failed)]);
            if ($result === []) {
                throw new \RuntimeException($message);
            }
            $http->log($message);
        }

        return array_values($result);
    }

    private function toVacancy(array $job, string $country): ?VacancyData
    {
        if (! isset($job['id']) || empty($job['link'])) {
            return null;
        }
        $location = trim((string) ($job['location'] ?? ''));

        return new VacancyData(
            source: $this->key(),
            externalId: (string) $job['id'],
            title: trim((string) ($job['title'] ?? '')),
            company: ($job['company'] ?? null) ?: null,
            // The API gives only the city, and the country site says which country it is in.
            location: ($location === '' ? '' : $location . ', ') . strtoupper($country),
            url: (string) $job['link'],
            description: trim((string) ($job['snippet'] ?? '')) ?: null,
            salary: ($job['salary'] ?? null) ?: null,
            publishedAt: ! empty($job['updated']) ? Carbon::parse($job['updated']) : null,
            raw: $job,
        );
    }
}
