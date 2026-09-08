<?php

namespace Tests\Feature;

use App\Services\Sources\PracujSource;
use App\Services\Sources\SourceHttp;
use App\Services\Sources\VacancyData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PracujSourceTest extends TestCase
{
    // SourceHttp records every request in fetch_logs, so the source needs a database.
    use RefreshDatabase;

    private const LISTING = 'massachusetts.pracuj.pl/*';
    // The app has no robots.txt: that page answers 404 and the runtime config sits in
    // the 404 body itself, so the stubs below answer 404 too or they prove nothing.
    private const ROBOTS = 'it.pracuj.pl/robots.txt';

    /** One card of the listing endpoint, two cities under one job posting. */
    private function group(array $overrides = []): array
    {
        return array_merge([
            'jobTitle' => 'PHP Developer',
            'companyName' => 'Acme',
            'salaryDisplayText' => '14 000–20 000 zł',
            'jobDescription' => 'Twój zakres obowiązków, Rozwój aplikacji webowych,...',
            'aiSummary' => '<ul><li>Masz doświadczenie w PHP.</li><li>Znasz Symfony.</li></ul>',
            'isRemoteWorkAllowed' => false,
            'lastPublicated' => '2026-09-07T08:05:56.437Z',
            'offers' => [
                ['partitionId' => 1005026576, 'displayWorkplace' => 'Warszawa', 'offerAbsoluteUri' => 'https://www.pracuj.pl/praca/php-developer-warszawa,oferta,1005026576'],
                ['partitionId' => 1005026575, 'displayWorkplace' => 'Kraków', 'offerAbsoluteUri' => 'https://www.pracuj.pl/praca/php-developer-krakow,oferta,1005026575'],
            ],
        ], $overrides);
    }

    private function listing(array $groups): array
    {
        return ['groupedOffers' => $groups, 'offersTotalCount' => count($groups), 'groupedOffersTotalCount' => count($groups)];
    }

    /** A full page of distinct cards, so the source has a reason to ask for the next one. */
    private function fullPage(int $firstId): array
    {
        $groups = [];
        for ($i = 0; $i < 50; $i++) {
            $id = $firstId + $i;
            $groups[] = $this->group(['offers' => [
                ['partitionId' => $id, 'displayWorkplace' => 'Kraków', 'offerAbsoluteUri' => "https://www.pracuj.pl/praca/x,oferta,{$id}"],
            ]]);
        }

        return $groups;
    }

    /** @return VacancyData[] */
    private function fetch(array $settings = []): array
    {
        return (new PracujSource)->fetch($settings, new SourceHttp(null, 'pracuj'));
    }

    private function sentUrls(): array
    {
        return Http::recorded()->map(fn (array $pair) => $pair[0]->url())->all();
    }

    public function test_one_card_with_several_cities_becomes_a_single_vacancy(): void
    {
        Http::fake([self::LISTING => Http::response($this->listing([$this->group()]))]);

        $vacancies = $this->fetch();

        $this->assertCount(1, $vacancies);
        $vacancy = $vacancies[0];
        $this->assertSame('pracuj', $vacancy->source);
        $this->assertSame('PHP Developer', $vacancy->title);
        $this->assertSame('Acme', $vacancy->company);
        // The lowest partition id wins, so the identity survives a reordering of the cities.
        $this->assertSame('1005026575', $vacancy->externalId);
        $this->assertSame('https://www.pracuj.pl/praca/php-developer-krakow,oferta,1005026575', $vacancy->url);
        $this->assertSame('Kraków, Warszawa', $vacancy->location);
        $this->assertSame('14 000–20 000 zł', $vacancy->salary);
        $this->assertSame('2026-09-07 08:05:56', $vacancy->publishedAt->utc()->format('Y-m-d H:i:s'));
    }

    public function test_remote_card_is_marked_in_the_location(): void
    {
        Http::fake([self::LISTING => Http::response($this->listing([$this->group(['isRemoteWorkAllowed' => true])]))]);

        $this->assertSame('Kraków, Warszawa (remote)', $this->fetch()[0]->location);
    }

    public function test_remote_only_narrows_the_query_to_home_office(): void
    {
        Http::fake([self::LISTING => Http::response($this->listing([]))]);

        $this->fetch(['remote_only' => true]);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'wm=home-office'));
    }

    public function test_without_remote_only_the_work_mode_is_not_sent(): void
    {
        Http::fake([self::LISTING => Http::response($this->listing([]))]);

        $this->fetch(['remote_only' => false]);

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'wm='));
    }

    public function test_every_picked_category_goes_into_the_query(): void
    {
        Http::fake([self::LISTING => Http::response($this->listing([]))]);

        $this->fetch(['pracuj_categories' => ['5016003', '5015004']]);

        $this->assertStringContainsString('cc=5016003&cc=5015004', $this->sentUrls()[0]);
    }

    public function test_categories_outside_the_catalog_fall_back_to_both_it_sections(): void
    {
        Http::fake([self::LISTING => Http::response($this->listing([]))]);

        $this->fetch(['pracuj_categories' => ['999', 'php']]);

        $this->assertStringContainsString('cc=5016&cc=5015', $this->sentUrls()[0]);
    }

    public function test_missing_category_setting_falls_back_to_both_it_sections(): void
    {
        Http::fake([self::LISTING => Http::response($this->listing([]))]);

        $this->fetch();

        $this->assertStringContainsString('cc=5016&cc=5015', $this->sentUrls()[0]);
    }

    public function test_first_three_keywords_each_get_their_own_request(): void
    {
        Http::fake([self::LISTING => Http::response($this->listing([]))]);

        $this->fetch(['search_keywords' => ['php', 'symfony', 'laravel', 'golang']]);

        Http::assertSentCount(3);
        $urls = implode(' ', $this->sentUrls());
        $this->assertStringContainsString('kw=php', $urls);
        $this->assertStringContainsString('kw=symfony', $urls);
        $this->assertStringContainsString('kw=laravel', $urls);
        $this->assertStringNotContainsString('kw=golang', $urls);
    }

    public function test_without_keywords_the_whole_category_is_requested_once(): void
    {
        Http::fake([self::LISTING => Http::response($this->listing([]))]);

        $this->fetch(['search_keywords' => []]);

        Http::assertSentCount(1);
        $this->assertStringNotContainsString('kw=', $this->sentUrls()[0]);
    }

    public function test_the_same_offer_found_by_two_keywords_is_returned_once(): void
    {
        Http::fake([self::LISTING => Http::response($this->listing([$this->group()]))]);

        $vacancies = $this->fetch(['search_keywords' => ['php', 'symfony']]);

        Http::assertSentCount(2);
        $this->assertCount(1, $vacancies);
    }

    public function test_description_keeps_the_intro_and_the_summary_bullets_apart(): void
    {
        Http::fake([self::LISTING => Http::response($this->listing([$this->group()]))]);

        $description = $this->fetch()[0]->description;

        $this->assertStringContainsString('Rozwój aplikacji webowych', $description);
        // The scorer strips tags without putting anything in their place, so the bullets
        // have to be separated by whitespace already inside the stored HTML.
        $this->assertStringContainsString(
            "Masz doświadczenie w PHP.\nZnasz Symfony.",
            strip_tags($description),
        );
    }

    public function test_an_empty_salary_is_stored_as_null(): void
    {
        Http::fake([self::LISTING => Http::response($this->listing([$this->group(['salaryDisplayText' => ''])]))]);

        $this->assertNull($this->fetch()[0]->salary);
    }

    public function test_a_card_without_offers_is_skipped(): void
    {
        Http::fake([self::LISTING => Http::response($this->listing([$this->group(['offers' => []]), $this->group()]))]);

        $this->assertCount(1, $this->fetch());
    }

    public function test_an_empty_but_valid_listing_yields_no_vacancies(): void
    {
        Http::fake([self::LISTING => Http::response($this->listing([]))]);

        $this->assertSame([], $this->fetch());
    }

    public function test_a_failed_request_does_not_hide_the_offers_of_the_others(): void
    {
        Http::fake([self::LISTING => Http::sequence()
            ->push(status: 500)
            ->push($this->listing([$this->group()])),
        ]);

        $this->assertCount(1, $this->fetch(['search_keywords' => ['php', 'symfony']]));
    }

    public function test_a_full_page_is_followed_by_the_next_one(): void
    {
        Http::fake([self::LISTING => Http::sequence()
            ->push($this->listing($this->fullPage(1000)))
            ->push($this->listing($this->fullPage(2000)))
            ->push($this->listing([$this->group()])),
        ]);

        $vacancies = $this->fetch();

        $this->assertCount(101, $vacancies);
        $this->assertSame(['pn=1', 'pn=2', 'pn=3'], array_map(
            fn (string $url) => substr($url, strrpos($url, 'pn=')),
            $this->sentUrls(),
        ));
    }

    public function test_a_short_page_ends_the_pagination(): void
    {
        Http::fake([self::LISTING => Http::response($this->listing([$this->group()]))]);

        $this->fetch();

        Http::assertSentCount(1);
    }

    public function test_pagination_stops_at_the_page_cap(): void
    {
        Http::fake([self::LISTING => Http::sequence()
            ->push($this->listing($this->fullPage(1000)))
            ->push($this->listing($this->fullPage(2000)))
            ->push($this->listing($this->fullPage(3000)))
            ->push($this->listing($this->fullPage(4000))),
        ]);

        $this->assertCount(150, $this->fetch());
        Http::assertSentCount(3);
    }

    public function test_a_moved_gateway_is_named_in_the_error(): void
    {
        Http::fake([
            self::LISTING => Http::response(status: 500),
            self::ROBOTS => Http::response('{"API_CLIENT_GATEWAY":"https://newhost.pracuj.pl","CDN_ROOT_PATH":"x"}', 404),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('шлюз сменился: https://massachusetts.pracuj.pl → https://newhost.pracuj.pl');

        $this->fetch();
    }

    public function test_a_gateway_value_that_is_not_a_pracuj_host_is_not_quoted_back(): void
    {
        Http::fake([
            self::LISTING => Http::response(status: 500),
            self::ROBOTS => Http::response('{"API_CLIENT_GATEWAY":"<a href=\"https://evil.example\">обновите токен</a>"}', 404),
        ]);

        // The message reaches the operator as an instruction, so a forged host must not
        // ride along with it.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('не отвечает ожидаемым JSON');

        $this->fetch();
    }

    public function test_an_unexpected_body_from_the_known_gateway_is_reported_as_such(): void
    {
        Http::fake([
            self::LISTING => Http::response(['offers' => []]),
            self::ROBOTS => Http::response('{"API_CLIENT_GATEWAY":"https://massachusetts.pracuj.pl"}', 404),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('не отвечает ожидаемым JSON');

        $this->fetch();
    }
}
