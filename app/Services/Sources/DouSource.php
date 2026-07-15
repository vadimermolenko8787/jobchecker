<?php

namespace App\Services\Sources;

class DouSource implements JobSourceInterface
{
    use RssParser;

    public function key(): string
    {
        return 'dou';
    }

    public function fetch(array $settings, SourceHttp $http): array
    {
        $base = 'https://jobs.dou.ua/vacancies/feeds/?category=' . urlencode($settings['dou_category'] ?? 'PHP');
        if (! empty($settings['remote_only'])) {
            $base .= '&remote';
        }

        $urls = [$base];
        foreach (array_slice($settings['search_keywords'] ?? [], 0, 3) as $kw) {
            $urls[] = $base . '&search=' . urlencode($kw);
        }

        $result = [];
        foreach ($urls as $url) {
            $response = $http->get($url);
            if (! $response || ! $response->successful()) {
                continue;
            }
            foreach ($this->parseRssItems($response->body()) as $item) {
                $link = preg_replace('/\?.*$/', '', $item['link']);
                if (isset($result[$link])) {
                    continue;
                }
                // DOU title format: "Role в Company, City[, віддалено]"
                $title = $item['title'];
                $company = null;
                $location = null;
                if (preg_match('/^(.*?) в (.*?)(?:, (.+))?$/u', $title, $m)) {
                    $title = $m[1];
                    $company = $m[2];
                    $location = $m[3] ?? null;
                }
                $result[$link] = new VacancyData(
                    source: $this->key(),
                    externalId: $link,
                    title: $title,
                    company: $company,
                    location: $location,
                    url: $link,
                    description: $item['description'],
                    publishedAt: $item['pubDate'],
                    raw: ['title' => $item['title'], 'link' => $item['link']],
                );
            }
        }

        return array_values($result);
    }
}
