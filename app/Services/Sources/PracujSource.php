<?php

namespace App\Services\Sources;

use Carbon\Carbon;

/**
 * it.pracuj.pl — the IT section of pracuj.pl.
 *
 * The listing pages sit behind a Cloudflare challenge, so the offers come from the
 * JSON API their frontend calls. It needs no key and no cookie, but the gateway host
 * is not part of any contract: it is announced by the frontend, which is why a broken
 * response is diagnosed against `API_CLIENT_GATEWAY` instead of passing for "nothing
 * found today". A single offer covers several cities, so one card becomes one vacancy.
 */
class PracujSource implements JobSourceInterface
{
    /** Host the frontend currently names as API_CLIENT_GATEWAY. */
    private const GATEWAY = 'https://massachusetts.pracuj.pl';

    /** Offers per page the endpoint returns, and how many pages one query is worth. */
    private const PAGE_SIZE = 50;

    private const MAX_PAGES = 3;

    /** @return array<int, string> code => caption in the current locale */
    public static function categoryLabels(): array
    {
        return array_map(fn (string $label) => __($label), self::CATEGORIES);
    }

    /** Both IT sections, i.e. everything it.pracuj.pl itself lists. */
    private const SECTIONS = [5016, 5015];

    /**
     * Search categories offered in the settings multiselect: the two IT sections
     * it.pracuj.pl itself accepts, plus their subcategories. The key is what goes
     * into `cc`, the value is the English caption, translated by categoryLabels().
     */
    public const CATEGORIES = [
        5016 => 'IT development, whole section',
        5016003 => 'IT development: programming',
        5016002 => 'IT development: architecture',
        5016001 => 'IT development: business and system analysis',
        5016004 => 'IT development: testing',
        5016005 => 'IT development: project and product management',
        5016006 => 'IT development: UX/UI design',
        5015 => 'IT administration, whole section',
        5015001 => 'IT administration: databases and storage',
        5015002 => 'IT administration: networks',
        5015003 => 'IT administration: systems',
        5015004 => 'IT administration: security and audit',
        5015005 => 'IT administration: ERP implementation',
        5015006 => 'IT administration: tech support and helpdesk',
        5015007 => 'IT administration: service management',
    ];

    public function key(): string
    {
        return 'pracuj';
    }

    public function fetch(array $settings, SourceHttp $http): array
    {
        $base = self::GATEWAY . '/jobOffers/listing/grouped?' . $this->categoryQuery($settings)
            . (! empty($settings['remote_only']) ? '&wm=home-office' : '');

        $urls = [];
        foreach (array_slice($settings['search_keywords'] ?? [], 0, 3) as $kw) {
            $urls[] = $base . '&kw=' . urlencode($kw);
        }
        // Without keywords the whole picked category is still a meaningful listing.
        $urls = $urls ?: [$base];

        $result = [];
        $answered = false;
        foreach ($urls as $url) {
            // A keyword search runs past 50 offers regularly, and the plain category
            // listing always does, so pages are followed until one comes back short.
            for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                $response = $http->get($url . '&pn=' . $page, ['Accept' => 'application/json']);
                $offers = $response?->successful() ? $response->json('groupedOffers') : null;
                if (! is_array($offers)) {
                    break;
                }
                $answered = true;
                foreach ($offers as $group) {
                    $vacancy = $this->toVacancy($group);
                    if ($vacancy !== null) {
                        $result[$vacancy->externalId] ??= $vacancy;
                    }
                }
                if (count($offers) < self::PAGE_SIZE) {
                    break;
                }
            }
        }

        if (! $answered) {
            throw new \RuntimeException($this->gatewayDiagnosis($http));
        }

        return array_values($result);
    }

    /** `cc` repeated per picked category; unknown codes are dropped, so the query is always ours. */
    private function categoryQuery(array $settings): string
    {
        $picked = array_values(array_intersect(
            array_map('intval', (array) ($settings['pracuj_categories'] ?? [])),
            array_keys(self::CATEGORIES),
        ));

        // With no category at all the endpoint answers with the whole of pracuj.pl,
        // so an empty pick falls back to the IT sections instead of leaving cc out.
        return implode('&', array_map(fn (int $code) => 'cc=' . $code, $picked ?: self::SECTIONS));
    }

    private function toVacancy(array $group): ?VacancyData
    {
        // Each city is its own offer with its own URL. The lowest id identifies the group:
        // it is the id the public URL carries, and ids are issued in order, so a city added
        // later cannot undercut it. The group's own GUID is not used — it appears in no URL
        // and there is no way to tell whether it survives an edit of the offer.
        $offers = array_filter($group['offers'] ?? [], fn ($offer) => isset($offer['partitionId']));
        if ($offers === []) {
            return null;
        }
        usort($offers, fn (array $a, array $b) => $a['partitionId'] <=> $b['partitionId']);
        $primary = $offers[0];

        $places = array_filter(array_map(fn (array $offer) => trim((string) ($offer['displayWorkplace'] ?? '')), $offers));
        $salary = trim((string) ($group['salaryDisplayText'] ?? ''));

        return new VacancyData(
            source: $this->key(),
            externalId: (string) $primary['partitionId'],
            title: (string) ($group['jobTitle'] ?? ''),
            company: ($group['companyName'] ?? null) ?: null,
            location: trim(implode(', ', array_unique($places)) . (! empty($group['isRemoteWorkAllowed']) ? ' (remote)' : '')) ?: null,
            url: (string) ($primary['offerAbsoluteUri'] ?? ''),
            description: $this->description($group),
            salary: $salary ?: null,
            publishedAt: isset($group['lastPublicated']) ? Carbon::parse($group['lastPublicated']) : null,
            raw: $group,
        );
    }

    /**
     * The API truncates jobDescription, so the AI summary of requirements is glued on.
     * VacancyScorer::plainDescription() strips tags without putting anything in their
     * place, hence the newline after every </li>: otherwise the bullets run together.
     */
    private function description(array $group): ?string
    {
        $intro = trim((string) ($group['jobDescription'] ?? ''));
        $summary = trim(str_replace('</li>', "</li>\n", (string) ($group['aiSummary'] ?? '')));

        $parts = array_filter([$intro === '' ? '' : '<p>' . $intro . '</p>', $summary]);

        return $parts === [] ? null : implode("\n", $parts);
    }

    /**
     * Nothing came back in the expected shape. The gateway host is whatever the frontend
     * currently names, so it is read back from the only it.pracuj.pl page Cloudflare lets
     * through — its 404 body carries the runtime config.
     */
    private function gatewayDiagnosis(SourceHttp $http): string
    {
        // That page answers 404 (the app has no robots.txt), and the config is in the
        // 404 body itself, so the status is deliberately not looked at.
        $body = $http->get('https://it.pracuj.pl/robots.txt')?->body() ?? '';

        // Narrow on purpose: the captured host is quoted back at the operator as the one
        // to put into the code, so anything but a pracuj.pl host is not worth repeating.
        if (preg_match('~"API_CLIENT_GATEWAY":"(https://[a-z0-9.-]+\.pracuj\.pl)"~', $body, $m) && $m[1] !== self::GATEWAY) {
            return __('gateway changed: :old → :new, update PracujSource::GATEWAY', ['old' => self::GATEWAY, 'new' => $m[1]]);
        }

        return __('gateway :gateway does not answer with the expected JSON, no vacancies received', ['gateway' => self::GATEWAY]);
    }
}
