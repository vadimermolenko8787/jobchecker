<?php

namespace App\Services\Sources;

use App\Models\Vacancy;
use Carbon\Carbon;
use Symfony\Component\DomCrawler\Crawler;

class LinkedInSource implements JobSourceInterface
{
    private const MAX_DETAIL_FETCHES = 10;
    private const REQUEST_DELAY_SECONDS = 2;

    public function key(): string
    {
        return 'linkedin';
    }

    public function fetch(array $settings, SourceHttp $http): array
    {
        $keywords = implode(' ', array_slice($settings['search_keywords'] ?? [], 0, 2)) ?: 'PHP Developer';
        $locations = array_slice($settings['locations'] ?? ['Germany'], 0, 2);

        $result = [];
        foreach ($locations as $location) {
            $url = 'https://www.linkedin.com/jobs-guest/jobs/api/seeMoreJobPostings/search?'
                . http_build_query(array_filter([
                    'keywords' => $keywords,
                    'location' => $location,
                    'f_TPR' => 'r604800', // posted within last 7 days
                    'f_WT' => ! empty($settings['remote_only']) ? '2' : null,
                    'start' => 0,
                ]));
            $response = $http->get($url);
            sleep(self::REQUEST_DELAY_SECONDS);
            if (! $response || ! $response->successful()) {
                continue;
            }
            foreach ($this->parseCards($response->body()) as $vacancy) {
                $result[$vacancy->externalId] ??= $vacancy;
            }
        }

        $this->enrichNewJobs($result, $http);

        return array_values($result);
    }

    /** @return VacancyData[] */
    private function parseCards(string $html): array
    {
        $crawler = new Crawler($html);
        $vacancies = [];
        $crawler->filter('li')->each(function (Crawler $card) use (&$vacancies) {
            $urnNode = $card->filter('[data-entity-urn]');
            if (! $urnNode->count()) {
                return;
            }
            $id = preg_replace('/^.*:/', '', $urnNode->attr('data-entity-urn'));
            $text = fn (string $selector) => $card->filter($selector)->count()
                ? trim($card->filter($selector)->text())
                : null;
            $publishedAt = null;
            if ($card->filter('time[datetime]')->count()) {
                try {
                    $publishedAt = Carbon::parse($card->filter('time[datetime]')->attr('datetime'));
                } catch (\Throwable) {
                }
            }
            $vacancies[] = new VacancyData(
                source: 'linkedin',
                externalId: $id,
                title: $text('.base-search-card__title') ?? '',
                company: $text('.base-search-card__subtitle'),
                location: $text('.job-search-card__location'),
                url: "https://www.linkedin.com/jobs/view/{$id}",
                publishedAt: $publishedAt,
            );
        });

        return $vacancies;
    }

    /**
     * Fetch full descriptions for jobs not yet in the DB, throttled to avoid 429s.
     *
     * @param  array<string, VacancyData>  $jobs
     */
    private function enrichNewJobs(array $jobs, SourceHttp $http): void
    {
        if ($jobs === []) {
            return;
        }
        $known = Vacancy::query()
            ->where('source', $this->key())
            ->whereIn('external_id', array_keys($jobs))
            ->pluck('external_id')
            ->all();
        $new = array_diff_key($jobs, array_flip($known));

        foreach (array_slice($new, 0, self::MAX_DETAIL_FETCHES, preserve_keys: true) as $vacancy) {
            $response = $http->get("https://www.linkedin.com/jobs-guest/jobs/api/jobPosting/{$vacancy->externalId}");
            sleep(self::REQUEST_DELAY_SECONDS);
            if (! $response || ! $response->successful()) {
                continue;
            }
            $crawler = new Crawler($response->body());
            $node = $crawler->filter('.show-more-less-html__markup, .description__text');
            if ($node->count()) {
                $vacancy->description = trim($node->first()->html());
            }
        }
    }
}
