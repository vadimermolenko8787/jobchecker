<?php

namespace Tests\Feature;

use App\Services\Sources\JobicoSource;
use App\Services\Sources\SourceHttp;
use App\Services\Sources\VacancyData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JobicoSourceTest extends TestCase
{
    // SourceHttp records every request in fetch_logs, so the source needs a database.
    use RefreshDatabase;

    private const FEED = 'www.jobico.io/api/feeds/jobs.xml';

    /** One <job> of the feed, in the shape jobico.io publishes it. */
    private function job(array $overrides = []): string
    {
        $fields = array_merge([
            'id' => '6ab96267b9e1370b1be07c39',
            'link' => 'https://www.jobico.io/jobs/php-developer-acme-3bga?utm_source=job-feed&amp;utm_medium=aggregator',
            'title' => 'PHP Developer',
            'company' => 'Acme',
            'employmenttype' => 'full-time',
            'locationtype' => 'remote',
            'salary' => '3000-4000 USD',
            'country' => 'Ukraine',
            'city' => 'Kyiv',
            'date' => '2026-09-27T18:37:27.637Z',
            'description' => '<![CDATA[<p>Laravel, MySQL &amp; Redis.</p>]]>',
        ], $overrides);

        $xml = '';
        foreach (array_filter($fields, fn ($v) => $v !== null) as $tag => $value) {
            $xml .= "<{$tag}>{$value}</{$tag}>";
        }

        return "<job>{$xml}</job>";
    }

    private function feed(array $jobs): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><source><publisher>Jobico</publisher>'
            . implode('', $jobs) . '</source>';
    }

    /** @return VacancyData[] */
    private function fetch(array $jobs, array $settings = []): array
    {
        Http::fake([self::FEED => Http::response($this->feed($jobs), 200, ['Content-Type' => 'application/xml'])]);

        return (new JobicoSource)->fetch($settings, new SourceHttp(null, 'jobico'));
    }

    public function test_a_feed_job_becomes_a_vacancy(): void
    {
        $vacancies = $this->fetch([$this->job()]);

        $this->assertCount(1, $vacancies);
        $vacancy = $vacancies[0];
        $this->assertSame('jobico', $vacancy->source);
        $this->assertSame('6ab96267b9e1370b1be07c39', $vacancy->externalId);
        $this->assertSame('PHP Developer', $vacancy->title);
        $this->assertSame('Acme', $vacancy->company);
        $this->assertSame('Kyiv, Ukraine (remote)', $vacancy->location);
        $this->assertSame('https://www.jobico.io/jobs/php-developer-acme-3bga', $vacancy->url);
        $this->assertSame('<p>Laravel, MySQL &amp; Redis.</p>', $vacancy->description);
        $this->assertSame('3000-4000 USD', $vacancy->salary);
        $this->assertSame('2026-09-27', $vacancy->publishedAt->toDateString());
        $this->assertArrayNotHasKey('description', $vacancy->raw);
    }

    public function test_missing_optional_fields_become_null(): void
    {
        $vacancy = $this->fetch([$this->job(['salary' => null, 'country' => null, 'city' => null, 'locationtype' => 'office'])])[0];

        $this->assertNull($vacancy->salary);
        $this->assertNull($vacancy->location);
    }

    public function test_remote_only_drops_office_and_hybrid_jobs(): void
    {
        $vacancies = $this->fetch([
            $this->job(['id' => 'a', 'locationtype' => 'remote']),
            $this->job(['id' => 'b', 'locationtype' => 'office']),
            $this->job(['id' => 'c', 'locationtype' => 'hybrid']),
        ], ['remote_only' => true]);

        $this->assertSame(['a'], array_map(fn (VacancyData $v) => $v->externalId, $vacancies));
    }

    public function test_without_remote_only_every_work_mode_is_kept(): void
    {
        $this->assertCount(2, $this->fetch([
            $this->job(['id' => 'a', 'locationtype' => 'remote']),
            $this->job(['id' => 'b', 'locationtype' => 'office']),
        ]));
    }

    public function test_keywords_keep_only_jobs_that_mention_one_of_the_first_three(): void
    {
        $vacancies = $this->fetch([
            $this->job(['id' => 'title', 'title' => 'Senior php engineer', 'description' => 'x']),
            $this->job(['id' => 'body', 'title' => 'Backend', 'description' => '<![CDATA[<p>We use <b>Laravel</b></p>]]>']),
            $this->job(['id' => 'fourth', 'title' => 'Backend', 'description' => 'Symfony only']),
            $this->job(['id' => 'none', 'title' => 'Product Manager', 'description' => 'roadmaps']),
        ], ['search_keywords' => ['PHP', 'Laravel', 'Yii2', 'Symfony']]);

        $this->assertSame(['title', 'body'], array_map(fn (VacancyData $v) => $v->externalId, $vacancies));
    }

    public function test_a_keyword_matches_whole_words_only(): void
    {
        $vacancies = $this->fetch([
            $this->job(['id' => 'part', 'title' => 'Legal adviser', 'description' => 'labour laws']),
            $this->job(['id' => 'word', 'title' => 'DevOps', 'description' => 'AWS, Terraform']),
            $this->job(['id' => 'dot', 'title' => 'Frontend', 'description' => 'Vue.js 3']),
        ], ['search_keywords' => ['AWS', 'Vue.js']]);

        $this->assertSame(['word', 'dot'], array_map(fn (VacancyData $v) => $v->externalId, $vacancies));
    }

    public function test_without_keywords_the_whole_feed_is_returned(): void
    {
        $this->assertCount(2, $this->fetch([
            $this->job(['id' => 'a', 'title' => 'Product Manager', 'description' => 'x']),
            $this->job(['id' => 'b']),
        ]));
    }

    public function test_a_job_repeated_in_the_feed_is_returned_once(): void
    {
        $this->assertCount(1, $this->fetch([$this->job(), $this->job()]));
    }

    public function test_a_job_without_id_or_link_is_skipped(): void
    {
        $this->assertCount(0, $this->fetch([$this->job(['id' => null]), $this->job(['id' => 'b', 'link' => null])]));
    }

    public function test_an_empty_but_valid_feed_yields_no_vacancies(): void
    {
        $this->assertSame([], $this->fetch([]));
    }

    public function test_a_failed_request_is_an_error_not_an_empty_result(): void
    {
        Http::fake([self::FEED => Http::response('', 500)]);

        $this->expectException(\RuntimeException::class);
        (new JobicoSource)->fetch([], new SourceHttp(null, 'jobico'));
    }

    public function test_a_body_that_is_not_the_feed_is_an_error(): void
    {
        Http::fake([self::FEED => Http::response('<!DOCTYPE html><html><title>Just a moment...</title></html>')]);

        $this->expectException(\RuntimeException::class);
        (new JobicoSource)->fetch([], new SourceHttp(null, 'jobico'));
    }
}
