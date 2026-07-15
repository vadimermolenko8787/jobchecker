<?php

namespace App\Services\Sources;

class DjinniSource implements JobSourceInterface
{
    use RssParser;

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

        return array_values($result);
    }
}
