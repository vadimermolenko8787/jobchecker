<?php

namespace Tests\Unit;

use App\Services\Sources\LocationCatalog;
use Tests\TestCase;

class LocationCatalogTest extends TestCase
{
    public function test_catalog_location_carries_its_indeed_country(): void
    {
        $this->assertSame(
            ['name' => 'Germany', 'indeed' => 'DE', 'host' => 'de.indeed.com'],
            LocationCatalog::resolve('Germany'),
        );
    }

    public function test_uk_uses_the_legacy_indeed_domain(): void
    {
        $uk = LocationCatalog::resolve('United Kingdom');

        $this->assertSame('GB', $uk['indeed']);
        $this->assertSame('uk.indeed.com', $uk['host']);
    }

    public function test_eu_wide_entry_is_linkedin_only(): void
    {
        $eu = LocationCatalog::resolve('European Union');

        $this->assertSame('European Union', $eu['name']);
        $this->assertNull($eu['indeed']);
        $this->assertNull($eu['host']);
    }

    public function test_custom_entry_may_carry_a_country_suffix(): void
    {
        $this->assertSame(
            ['name' => 'Tbilisi', 'indeed' => 'GE', 'host' => 'ge.indeed.com'],
            LocationCatalog::resolve('Tbilisi:GE'),
        );
        $this->assertSame('Tbilisi', LocationCatalog::resolve('Tbilisi : ge')['name']);
        $this->assertSame('GE', LocationCatalog::resolve('Tbilisi : ge')['indeed']);
    }

    public function test_custom_entry_without_a_suffix_is_linkedin_only(): void
    {
        $this->assertSame(
            ['name' => 'Tbilisi', 'indeed' => null, 'host' => null],
            LocationCatalog::resolve('  Tbilisi  '),
        );
    }

    public function test_indeed_host_overrides(): void
    {
        $this->assertSame('www.indeed.com', LocationCatalog::indeedHost('US'));
        $this->assertSame('uk.indeed.com', LocationCatalog::indeedHost('gb'));
        $this->assertSame('pl.indeed.com', LocationCatalog::indeedHost('PL'));
    }

    public function test_labels_cover_every_catalog_entry(): void
    {
        $labels = LocationCatalog::labels();

        $this->assertSame(array_keys(LocationCatalog::LOCATIONS), array_keys($labels));
        $this->assertSame('Германия', $labels['Germany']);
        $this->assertNotContains('', $labels);
    }
}
