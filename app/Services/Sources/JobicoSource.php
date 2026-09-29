<?php

namespace App\Services\Sources;

use Carbon\Carbon;

/**
 * jobico.io — a Ukrainian IT job board.
 *
 * The site publishes one XML feed for aggregators (robots.txt allows /api/feeds/ and
 * disallows the rest of /api/). It carries every open posting with its full description,
 * but takes no search parameters, so the keyword and remote filters are applied here.
 */
class JobicoSource implements JobSourceInterface
{
    private const FEED = 'https://www.jobico.io/api/feeds/jobs.xml';

    public function key(): string
    {
        return 'jobico';
    }

    public function fetch(array $settings, SourceHttp $http): array
    {
        $response = $http->get(self::FEED, ['Accept' => 'application/xml']);

        $prev = libxml_use_internal_errors(true);
        $doc = $response?->successful() ? simplexml_load_string($response->body(), options: LIBXML_NOCDATA) : false;
        libxml_use_internal_errors($prev);
        // One request is the whole source, so a broken feed must not pass for "nothing found".
        if ($doc === false || $doc->getName() !== 'source') {
            throw new \RuntimeException('фид ' . self::FEED . ' не отвечает ожидаемым XML, вакансии не получены');
        }

        // The same three keywords the searchable sources query with, matched as whole words.
        $patterns = array_map(
            fn (string $kw) => '/(?<![\p{L}\p{N}])' . preg_quote($kw, '/') . '(?![\p{L}\p{N}])/iu',
            array_filter(array_map('trim', array_slice($settings['search_keywords'] ?? [], 0, 3))),
        );

        $result = [];
        foreach ($doc->job as $job) {
            $vacancy = $this->toVacancy($job);
            if ($vacancy === null) {
                continue;
            }
            if (! empty($settings['remote_only']) && ($vacancy->raw['locationtype'] ?? null) !== 'remote') {
                continue;
            }
            $haystack = $vacancy->title . ' ' . strip_tags((string) $vacancy->description);
            if ($patterns !== [] && ! array_filter($patterns, fn (string $p) => preg_match($p, $haystack))) {
                continue;
            }
            $result[$vacancy->externalId] ??= $vacancy;
        }

        return array_values($result);
    }

    private function toVacancy(\SimpleXMLElement $job): ?VacancyData
    {
        $id = trim((string) $job->id);
        $link = trim((string) $job->link);
        if ($id === '' || $link === '') {
            return null;
        }

        // Everything but the description, which is stored in its own column anyway.
        $raw = [];
        foreach ($job->children() as $field) {
            if ($field->getName() !== 'description') {
                $raw[$field->getName()] = trim((string) $field);
            }
        }

        $place = implode(', ', array_filter([$raw['city'] ?? '', $raw['country'] ?? '']));
        $remote = ($raw['locationtype'] ?? '') === 'remote' ? ' (remote)' : '';

        return new VacancyData(
            source: $this->key(),
            externalId: $id,
            title: $raw['title'] ?? '',
            company: ($raw['company'] ?? '') ?: null,
            location: trim($place . $remote) ?: null,
            // The feed tags every link with utm_* for its own statistics; the page is the same without them.
            url: preg_replace('/\?.*$/', '', $link),
            description: trim((string) $job->description) ?: null,
            salary: ($raw['salary'] ?? '') ?: null,
            publishedAt: ($raw['date'] ?? '') !== '' ? Carbon::parse($raw['date']) : null,
            raw: $raw,
        );
    }
}
