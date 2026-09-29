<?php

namespace Tests\Feature;

use App\Models\Vacancy;
use App\Services\Sources\JustJoinSource;
use App\Services\Sources\SourceHttp;
use App\Services\Sources\VacancyData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JustJoinSourceTest extends TestCase
{
    // SourceHttp records every request in fetch_logs, so the source needs a database.
    use RefreshDatabase;

    private const LISTING = 'justjoin.it/api/candidate-api/offers?*';

    private const DETAIL = 'justjoin.it/api/candidate-api/offers/*';

    /** One card of the listing, in the shape the candidate gateway returns it. */
    private function offer(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'acme-php-developer-krakow-php',
            'title' => 'PHP Developer',
            'companyName' => 'Acme',
            'city' => 'Kraków',
            'workplaceType' => 'remote',
            'publishedAt' => '2026-09-29T08:30:14.337Z',
            'requiredSkills' => [['name' => 'PHP', 'level' => 3], ['name' => 'Laravel', 'level' => 2]],
            'employmentTypes' => [
                ['from' => 15000.0, 'to' => 20000.0, 'currency' => 'PLN', 'type' => 'b2b'],
                ['from' => 3500.0, 'to' => 4700.0, 'currency' => 'EUR', 'type' => 'b2b'],
            ],
        ], $overrides);
    }

    private function listing(array $offers): array
    {
        return ['data' => $offers, 'meta' => ['from' => 0, 'totalItems' => count($offers)]];
    }

    /** @return VacancyData[] */
    private function fetch(array $settings = []): array
    {
        return (new JustJoinSource)->fetch($settings, new SourceHttp(null, 'justjoin'));
    }

    private function listingUrls(): array
    {
        return Http::recorded()
            ->map(fn (array $pair) => urldecode($pair[0]->url()))
            ->filter(fn (string $url) => str_contains($url, '/offers?'))
            ->values()
            ->all();
    }

    public function test_a_listing_card_becomes_a_vacancy(): void
    {
        Http::fake([
            self::LISTING => Http::response($this->listing([$this->offer()])),
            self::DETAIL => Http::response(['body' => '<p>Full description</p>']),
        ]);

        $vacancies = $this->fetch();

        $this->assertCount(1, $vacancies);
        $vacancy = $vacancies[0];
        $this->assertSame('acme-php-developer-krakow-php', $vacancy->externalId);
        $this->assertSame('PHP Developer', $vacancy->title);
        $this->assertSame('Acme', $vacancy->company);
        $this->assertSame('Kraków (remote)', $vacancy->location);
        $this->assertSame('https://justjoin.it/job-offer/acme-php-developer-krakow-php', $vacancy->url);
        $this->assertSame('15000–20000 PLN (b2b)', $vacancy->salary);
        $this->assertSame('<p>Full description</p>', $vacancy->description);
    }

    public function test_skill_names_describe_the_offer_when_the_detail_is_unavailable(): void
    {
        Http::fake([
            self::LISTING => Http::response($this->listing([$this->offer()])),
            self::DETAIL => Http::response(status: 500),
        ]);

        $this->assertSame('Required skills: PHP, Laravel', $this->fetch()[0]->description);
    }

    public function test_the_stored_numeric_category_is_sent_as_its_key(): void
    {
        Http::fake([self::LISTING => Http::response($this->listing([]))]);

        $this->fetch(['justjoin_category' => 5]);

        $this->assertStringContainsString('categories=python', $this->listingUrls()[0]);
    }

    public function test_an_unknown_category_falls_back_to_php(): void
    {
        Http::fake([self::LISTING => Http::response($this->listing([]))]);

        $this->fetch(['justjoin_category' => 999]);

        $this->assertStringContainsString('categories=php', $this->listingUrls()[0]);
    }

    public function test_the_category_listing_and_first_three_keywords_are_requested(): void
    {
        Http::fake([self::LISTING => Http::response($this->listing([]))]);

        $this->fetch(['search_keywords' => ['PHP', 'Laravel', 'Yii2', 'Symfony']]);

        $urls = $this->listingUrls();
        $this->assertCount(4, $urls);
        $this->assertStringNotContainsString('keywords=', $urls[0]);
        $this->assertStringContainsString('keywords=Laravel&keywordType=any', $urls[2]);
        $this->assertStringNotContainsString('Symfony', implode(' ', $urls));
    }

    public function test_remote_only_skips_offers_that_are_not_remote(): void
    {
        Http::fake([
            self::LISTING => Http::response($this->listing([
                $this->offer(),
                $this->offer(['slug' => 'office-one', 'workplaceType' => 'office']),
            ])),
            self::DETAIL => Http::response(['body' => 'x']),
        ]);

        $vacancies = $this->fetch(['remote_only' => true]);

        $this->assertSame(['acme-php-developer-krakow-php'], array_map(fn (VacancyData $v) => $v->externalId, $vacancies));
    }

    public function test_the_detail_is_only_fetched_for_offers_not_yet_stored(): void
    {
        Vacancy::query()->create([
            'source' => 'justjoin',
            'external_id' => 'acme-php-developer-krakow-php',
            'title' => 'PHP Developer',
            'url' => 'https://justjoin.it/job-offer/acme-php-developer-krakow-php',
        ]);
        Http::fake([
            self::LISTING => Http::response($this->listing([$this->offer()])),
            self::DETAIL => Http::response(['body' => 'x']),
        ]);

        $this->fetch();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/offers/acme'));
    }

    public function test_new_offers_past_the_detail_cap_wait_for_the_next_run(): void
    {
        // Stored with the skill list alone, an offer would never get its text: the scorer
        // then reads the English skill names as the posting's language.
        $offers = array_map(fn (int $i) => $this->offer(['slug' => "offer-{$i}"]), range(1, 25));
        Http::fake([
            self::LISTING => Http::response($this->listing($offers)),
            self::DETAIL => Http::response(['body' => '<p>Opis stanowiska</p>']),
        ]);

        $first = $this->fetch();

        $this->assertCount(20, $first);
        $this->assertSame(['<p>Opis stanowiska</p>'], array_values(array_unique(array_map(fn (VacancyData $v) => $v->description, $first))));

        foreach ($first as $vacancy) {
            Vacancy::query()->create(['source' => 'justjoin', 'external_id' => $vacancy->externalId, 'title' => $vacancy->title, 'url' => $vacancy->url]);
        }

        $second = array_filter($this->fetch(), fn (VacancyData $v) => in_array($v->externalId, ['offer-21', 'offer-25'], true));
        $this->assertCount(2, $second);
        $this->assertSame(['<p>Opis stanowiska</p>'], array_values(array_unique(array_map(fn (VacancyData $v) => $v->description, $second))));
    }

    public function test_a_gateway_that_answers_nothing_is_reported(): void
    {
        Http::fake([self::LISTING => Http::response('<html>503 Service Temporarily Unavailable</html>', 503)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('justjoin.it/api/candidate-api');

        $this->fetch(['search_keywords' => ['PHP']]);
    }

    public function test_an_empty_but_valid_listing_yields_no_vacancies(): void
    {
        Http::fake([self::LISTING => Http::response($this->listing([]))]);

        $this->assertSame([], $this->fetch());
    }
}
