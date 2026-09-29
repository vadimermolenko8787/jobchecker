<?php

namespace Tests\Feature;

use App\Services\Sources\JoobleSource;
use App\Services\Sources\SourceHttp;
use App\Services\Sources\VacancyData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JoobleSourceTest extends TestCase
{
    // SourceHttp records every request in fetch_logs, so the source needs a database.
    use RefreshDatabase;

    private const KEYS = ['jooble_keys' => ['de' => 'de-key']];

    /** One job of the API response, in the shape de.jooble.org returns it. */
    private function job(array $overrides = []): array
    {
        return array_merge([
            'title' => 'PHP Laravel Backend Developer [gn]',
            'location' => 'Koblenz',
            'snippet' => '&nbsp;...Bei uns entwickelst du mit PHP und <b>Laravel </b>Anwendungen...&nbsp;',
            'salary' => '',
            'source' => 'paid.gohiring.com',
            'type' => 'Vollzeit',
            'link' => 'https://de.jooble.org/jdp/-2759684975191572422/PHP-Laravel-Backend-Developer-Koblenz',
            'company' => 'LUMASERV GmbH',
            'updated' => '2026-09-24T12:51:52.6721640+00:00',
            'id' => -2759684975191572422,
        ], $overrides);
    }

    private function answer(array $jobs): array
    {
        return ['totalCount' => count($jobs), 'jobs' => $jobs];
    }

    /** @return VacancyData[] */
    private function fetch(array $settings = self::KEYS): array
    {
        return (new JoobleSource)->fetch($settings, new SourceHttp(null, 'jooble'));
    }

    /** @return array<int, array{url: string, body: array}> */
    private function sent(): array
    {
        return Http::recorded()->map(fn (array $pair) => ['url' => $pair[0]->url(), 'body' => $pair[0]->data()])->all();
    }

    public function test_an_api_job_becomes_a_vacancy(): void
    {
        Http::fake(['de.jooble.org/*' => Http::response($this->answer([$this->job()]))]);

        $vacancies = $this->fetch();

        $this->assertCount(1, $vacancies);
        $vacancy = $vacancies[0];
        $this->assertSame('jooble', $vacancy->source);
        $this->assertSame('-2759684975191572422', $vacancy->externalId);
        $this->assertSame('PHP Laravel Backend Developer [gn]', $vacancy->title);
        $this->assertSame('LUMASERV GmbH', $vacancy->company);
        $this->assertSame('Koblenz, DE', $vacancy->location);
        $this->assertSame('https://de.jooble.org/jdp/-2759684975191572422/PHP-Laravel-Backend-Developer-Koblenz', $vacancy->url);
        $this->assertStringContainsString('<b>Laravel </b>', $vacancy->description);
        $this->assertNull($vacancy->salary);
        $this->assertSame('2026-09-24', $vacancy->publishedAt->toDateString());
    }

    public function test_every_country_is_asked_on_its_own_site_with_its_own_key(): void
    {
        Http::fake(['*' => Http::response($this->answer([]))]);

        $this->fetch(['jooble_keys' => ['de' => 'de-key', 'pl' => 'pl-key'], 'search_keywords' => ['PHP']]);

        $this->assertSame(
            ['https://de.jooble.org/api/de-key', 'https://pl.jooble.org/api/pl-key'],
            array_column($this->sent(), 'url'),
        );
    }

    public function test_the_country_goes_into_the_location(): void
    {
        Http::fake(['pl.jooble.org/*' => Http::response($this->answer([$this->job(['location' => 'Wrocław'])]))]);

        $vacancy = $this->fetch(['jooble_keys' => ['pl' => 'pl-key']])[0];

        $this->assertSame('Wrocław, PL', $vacancy->location);
    }

    public function test_first_three_keywords_each_get_their_own_request(): void
    {
        Http::fake(['*' => Http::response($this->answer([]))]);

        $this->fetch(self::KEYS + ['search_keywords' => ['PHP', 'Laravel', 'Yii2', 'Symfony']]);

        $this->assertSame(['PHP', 'Laravel', 'Yii2'], array_column(array_column($this->sent(), 'body'), 'keywords'));
    }

    public function test_without_keywords_php_is_searched(): void
    {
        Http::fake(['*' => Http::response($this->answer([]))]);

        $this->fetch();

        $this->assertSame(['PHP'], array_column(array_column($this->sent(), 'body'), 'keywords'));
    }

    public function test_remote_only_asks_for_remote_jobs(): void
    {
        Http::fake(['*' => Http::response($this->answer([]))]);

        $this->fetch(self::KEYS + ['remote_only' => true]);

        $this->assertSame('Remote', $this->sent()[0]['body']['location']);
    }

    public function test_without_remote_only_no_location_is_sent(): void
    {
        Http::fake(['*' => Http::response($this->answer([]))]);

        $this->fetch();

        $this->assertArrayNotHasKey('location', $this->sent()[0]['body']);
    }

    public function test_the_same_job_found_by_two_keywords_is_returned_once(): void
    {
        Http::fake(['*' => Http::response($this->answer([$this->job()]))]);

        $this->assertCount(1, $this->fetch(self::KEYS + ['search_keywords' => ['PHP', 'Laravel']]));
    }

    public function test_a_job_without_id_or_link_is_skipped(): void
    {
        Http::fake(['*' => Http::response($this->answer([$this->job(['id' => null]), $this->job(['id' => 1, 'link' => ''])]))]);

        $this->assertSame([], $this->fetch());
    }

    public function test_without_keys_nothing_is_requested(): void
    {
        Http::fake();

        $this->assertSame([], $this->fetch([]));
        Http::assertNothingSent();
    }

    public function test_a_refused_key_alone_is_an_error_that_does_not_quote_the_key(): void
    {
        Http::fake(['*' => Http::response('<title>Fehler 403</title>', 403)]);

        try {
            $this->fetch();
            $this->fail('A source that got nothing at all must throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('de', $e->getMessage());
            $this->assertStringNotContainsString('de-key', $e->getMessage());
        }
    }

    public function test_a_refused_key_does_not_hide_the_jobs_of_another_country(): void
    {
        Http::fake([
            'de.jooble.org/*' => Http::response($this->answer([$this->job()])),
            'pl.jooble.org/*' => Http::response('', 403),
        ]);

        $this->assertCount(1, $this->fetch(['jooble_keys' => ['de' => 'de-key', 'pl' => 'pl-key']]));
    }
}
