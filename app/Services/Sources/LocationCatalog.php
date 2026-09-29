<?php

namespace App\Services\Sources;

/**
 * Search locations offered in the settings dropdown.
 *
 * The array key is what LinkedIn's guest endpoint receives as `location` and what
 * is stored in the `locations` setting; `label` is the English caption shown in the
 * UI, translated by labels(), following the DocumentGenerator::LANGUAGE_LABELS convention.
 */
class LocationCatalog
{
    /**
     * `indeed` = null means the location has no Indeed country and is searched on
     * LinkedIn only. `host` overrides the domain derived from the country code.
     */
    public const LOCATIONS = [
        'Germany' => ['label' => 'Germany', 'indeed' => 'DE'],
        'Netherlands' => ['label' => 'Netherlands', 'indeed' => 'NL'],
        'Poland' => ['label' => 'Poland', 'indeed' => 'PL'],
        'Austria' => ['label' => 'Austria', 'indeed' => 'AT'],
        'Switzerland' => ['label' => 'Switzerland', 'indeed' => 'CH'],
        'Belgium' => ['label' => 'Belgium', 'indeed' => 'BE'],
        'Ireland' => ['label' => 'Ireland', 'indeed' => 'IE'],
        'United Kingdom' => ['label' => 'United Kingdom', 'indeed' => 'GB'],
        'France' => ['label' => 'France', 'indeed' => 'FR'],
        'Spain' => ['label' => 'Spain', 'indeed' => 'ES'],
        'Portugal' => ['label' => 'Portugal', 'indeed' => 'PT'],
        'Sweden' => ['label' => 'Sweden', 'indeed' => 'SE'],
        'Denmark' => ['label' => 'Denmark', 'indeed' => 'DK'],
        'Norway' => ['label' => 'Norway', 'indeed' => 'NO'],
        'Finland' => ['label' => 'Finland', 'indeed' => 'FI'],
        'Czechia' => ['label' => 'Czechia', 'indeed' => 'CZ'],
        'Romania' => ['label' => 'Romania', 'indeed' => 'RO'],
        // Indeed has no Estonian site: EE answers 400, so the location is LinkedIn-only.
        'Estonia' => ['label' => 'Estonia', 'indeed' => null],
        'Lithuania' => ['label' => 'Lithuania', 'indeed' => 'LT'],
        'Latvia' => ['label' => 'Latvia', 'indeed' => 'LV'],
        'Luxembourg' => ['label' => 'Luxembourg', 'indeed' => 'LU'],
        'European Union' => ['label' => 'European Union (remote)', 'indeed' => null],
    ];

    /** Indeed domains that are not simply the lowercased country code. */
    private const HOST_OVERRIDES = [
        'GB' => 'uk.indeed.com',
        'US' => 'www.indeed.com',
    ];

    /**
     * Catalog key => UI label in the current locale, for rendering the multiselect.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return array_map(fn (array $entry) => __($entry['label']), self::LOCATIONS);
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
