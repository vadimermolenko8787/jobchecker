<?php

namespace App\Services\Sources;

use App\Models\Vacancy;
use Symfony\Component\DomCrawler\Crawler;

class DjinniSource implements JobSourceInterface
{
    use RssParser;

    private const MAX_DETAIL_FETCHES = 30;

    private const REQUEST_DELAY_SECONDS = 1;

    public function key(): string
    {
        return 'djinni';
    }

    public function fetch(array $settings, SourceHttp $http): array
    {
        $base = 'https://djinni.co/jobs/rss/?primary_keyword=' . urlencode($settings['djinni_primary_keyword'] ?? 'PHP');
        if (! empty($settings['remote_only'])) {
            $base .= '&employment=remote';
        }

        $urls = [$base];
        foreach (array_slice($settings['search_keywords'] ?? [], 0, 3) as $kw) {
            $urls[] = $base . '&all_keywords=' . urlencode($kw);
        }

        $result = [];
        foreach ($urls as $url) {
            $response = $http->get($url);
            if (! $response || ! $response->successful()) {
                continue;
            }
            foreach ($this->parseRssItems($response->body()) as $item) {
                // guid like https://djinni.co/jobs/830982-senior-php-developer/
                $externalId = trim(parse_url($item['link'], PHP_URL_PATH) ?: $item['link'], '/');
                if (isset($result[$externalId])) {
                    continue;
                }
                $result[$externalId] = new VacancyData(
                    source: $this->key(),
                    externalId: $externalId,
                    title: $item['title'],
                    url: $item['link'],
                    description: $item['description'],
                    publishedAt: $item['pubDate'],
                    raw: ['categories' => $item['categories']],
                );
            }
        }

        $this->enrichNewJobs($result, $http);

        return array_values($result);
    }

    /**
     * The RSS carries no company at all, so it comes from the job page instead. Only rows
     * the DB does not know yet are fetched, throttled the same way LinkedIn details are.
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
        if ($new === []) {
            return;
        }

        $loaded = 0;
        foreach (array_slice($new, 0, self::MAX_DETAIL_FETCHES, preserve_keys: true) as $vacancy) {
            $response = $http->get($vacancy->url);
            sleep(self::REQUEST_DELAY_SECONDS);
            if (! $response || ! $response->successful()) {
                continue;
            }
            $vacancy->company = $this->parseCompany($response->body());
            if ($vacancy->company !== null) {
                $loaded++;
            }
        }

        // Without a company the vacancy can never be researched, so say how many were missed.
        $http->log(__('companies found for :loaded of :total new vacancies', ['loaded' => $loaded, 'total' => count($new)])
            . (count($new) > self::MAX_DETAIL_FETCHES ? __(' (limit :limit per run)', ['limit' => self::MAX_DETAIL_FETCHES]) : ''));
    }

    /** Company name out of the JobPosting JSON-LD the job page embeds, or its page title. */
    private function parseCompany(string $html): ?string
    {
        $crawler = new Crawler($html);
        foreach ($crawler->filter('script[type="application/ld+json"]') as $node) {
            $data = json_decode($node->textContent, true);
            $name = trim((string) ($data['hiringOrganization']['name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        // A closed vacancy drops the JSON-LD but keeps the "<job> at <company> – Djinni"
        // title. A job title may itself contain " at ", so the company is what follows
        // the last one, which is what the greedy first group leaves.
        $title = $crawler->filter('title');
        if ($title->count() && preg_match('/^.* at (.+) – Djinni$/u', trim($title->text()), $m)) {
            return trim($m[1]) ?: null;
        }

        return null;
    }
}
