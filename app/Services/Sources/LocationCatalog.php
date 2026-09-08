<?php

namespace App\Services\Sources;

/**
 * Search locations offered in the settings dropdown.
 *
 * The array key is what LinkedIn's guest endpoint receives as `location` and what
 * is stored in the `locations` setting; `label` is the Russian caption shown in the
 * UI, following the DocumentGenerator::LANGUAGES / LANGUAGE_LABELS convention.
 */
class LocationCatalog
{
    /**
     * `indeed` = null means the location has no Indeed country and is searched on
     * LinkedIn only. `host` overrides the domain derived from the country code.
     */
    public const LOCATIONS = [
        'Germany' => ['label' => 'Германия', 'indeed' => 'DE'],
        'Netherlands' => ['label' => 'Нидерланды', 'indeed' => 'NL'],
        'Poland' => ['label' => 'Польша', 'indeed' => 'PL'],
        'Austria' => ['label' => 'Австрия', 'indeed' => 'AT'],
        'Switzerland' => ['label' => 'Швейцария', 'indeed' => 'CH'],
        'Belgium' => ['label' => 'Бельгия', 'indeed' => 'BE'],
        'Ireland' => ['label' => 'Ирландия', 'indeed' => 'IE'],
        'United Kingdom' => ['label' => 'Великобритания', 'indeed' => 'GB'],
        'France' => ['label' => 'Франция', 'indeed' => 'FR'],
        'Spain' => ['label' => 'Испания', 'indeed' => 'ES'],
        'Portugal' => ['label' => 'Португалия', 'indeed' => 'PT'],
        'Sweden' => ['label' => 'Швеция', 'indeed' => 'SE'],
        'Denmark' => ['label' => 'Дания', 'indeed' => 'DK'],
        'Norway' => ['label' => 'Норвегия', 'indeed' => 'NO'],
        'Finland' => ['label' => 'Финляндия', 'indeed' => 'FI'],
        'Czechia' => ['label' => 'Чехия', 'indeed' => 'CZ'],
        'Romania' => ['label' => 'Румыния', 'indeed' => 'RO'],
        // Indeed has no Estonian site: EE answers 400, so the location is LinkedIn-only.
        'Estonia' => ['label' => 'Эстония', 'indeed' => null],
        'Lithuania' => ['label' => 'Литва', 'indeed' => 'LT'],
        'Latvia' => ['label' => 'Латвия', 'indeed' => 'LV'],
        'Luxembourg' => ['label' => 'Люксембург', 'indeed' => 'LU'],
        'European Union' => ['label' => 'Евросоюз (remote)', 'indeed' => null],
    ];

    /** Indeed domains that are not simply the lowercased country code. */
    private const HOST_OVERRIDES = [
        'GB' => 'uk.indeed.com',
        'US' => 'www.indeed.com',
    ];

    /**
     * Catalog key => UI label, for rendering the multiselect.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return array_map(fn (array $entry) => $entry['label'], self::LOCATIONS);
    }

    /**
     * Turn a stored location entry into the data both sources need.
     *
     * Entries outside the catalog may carry an Indeed country as a `Tbilisi:GE`
     * suffix; without it they are searched on LinkedIn only.
     *
     * @return array{name: string, indeed: ?string, host: ?string}
     */
    public static function resolve(string $entry): array
    {
        $entry = trim($entry);

        if (isset(self::LOCATIONS[$entry])) {
            $country = self::LOCATIONS[$entry]['indeed'];

            return [
                'name' => $entry,
                'indeed' => $country,
                'host' => $country === null ? null : self::indeedHost($country),
            ];
        }

        if (preg_match('/^(.+?)\s*:\s*([A-Za-z]{2})$/', $entry, $m)) {
            $country = strtoupper($m[2]);

            return ['name' => trim($m[1]), 'indeed' => $country, 'host' => self::indeedHost($country)];
        }

        return ['name' => $entry, 'indeed' => null, 'host' => null];
    }

    public static function indeedHost(string $country): string
    {
        $country = strtoupper($country);

        return self::HOST_OVERRIDES[$country] ?? strtolower($country) . '.indeed.com';
    }
}
