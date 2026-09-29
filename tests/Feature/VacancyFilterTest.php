<?php

namespace Tests\Feature;

use App\Models\Run;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VacancyFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function vacancy(array $attributes = []): Vacancy
    {
        static $n = 0;
        $n++;

        return Vacancy::create(array_merge([
            'source' => 'linkedin',
            'external_id' => "ext-{$n}",
            'title' => "Vacancy {$n}",
            'company' => "Company {$n}",
            'url' => "https://example.test/{$n}",
            'published_at' => '2026-07-15 12:00:00',
        ], $attributes));
    }

    public function test_title_filter_matches_substring_case_insensitively(): void
    {
        $this->vacancy(['title' => 'Senior Laravel Developer']);
        $this->vacancy(['title' => 'Frontend Engineer']);

        $response = $this->get('/vacancies?q=laravel');

        $response->assertOk();
        $response->assertSee('Senior Laravel Developer');
        $response->assertDontSee('Frontend Engineer');
        $response->assertSee('1 записей');
    }

    public function test_company_filter_matches_substring(): void
    {
        $this->vacancy(['title' => 'Backend Dev', 'company' => 'Acme GmbH']);
        $this->vacancy(['title' => 'Backend Ops', 'company' => 'Other Corp']);

        $response = $this->get('/vacancies?company=acme');

        $response->assertOk();
        $response->assertSee('Backend Dev');
        $response->assertDontSee('Backend Ops');
    }

    public function test_source_filter_keeps_only_the_picked_sources(): void
    {
        $this->vacancy(['title' => 'From LinkedIn', 'source' => 'linkedin']);
        $this->vacancy(['title' => 'From Indeed', 'source' => 'indeed']);
        $this->vacancy(['title' => 'From Dou', 'source' => 'dou']);

        $single = $this->get('/vacancies?' . http_build_query(['source' => ['linkedin']]));

        $single->assertOk();
        $single->assertSee('From LinkedIn');
        $single->assertDontSee('From Indeed');
        $single->assertSee('1 записей');

        $multiple = $this->get('/vacancies?' . http_build_query(['source' => ['linkedin', 'indeed']]));

        $multiple->assertOk();
        $multiple->assertSee('From LinkedIn');
        $multiple->assertSee('From Indeed');
        $multiple->assertDontSee('From Dou');
        $multiple->assertSee('2 записей');
    }

    public function test_source_filter_combines_with_the_other_filters_and_is_carried_over(): void
    {
        $this->vacancy(['title' => 'Laravel Dev', 'source' => 'linkedin']);
        $this->vacancy(['title' => 'Laravel Ops', 'source' => 'dou']);
        $this->vacancy(['title' => 'Python Dev', 'source' => 'linkedin']);

        $response = $this->get('/vacancies?' . http_build_query(['q' => 'Laravel', 'source' => ['linkedin']]));

        $response->assertOk();
        $response->assertSee('Laravel Dev');
        $response->assertDontSee('Laravel Ops');
        $response->assertDontSee('Python Dev');
        // Sort headers, status tabs and pagination all keep the picked source.
        $response->assertSee('source%5B0%5D=linkedin', false);
    }

    public function test_like_wildcards_in_the_query_are_treated_literally(): void
    {
        $this->vacancy(['title' => 'Discount 100% Role']);
        $this->vacancy(['title' => 'Plain Role']);

        $response = $this->get('/vacancies?' . http_build_query(['q' => '100%']));

        $response->assertOk();
        $response->assertSee('Discount 100% Role', false);
        $response->assertDontSee('Plain Role');
    }

    public function test_date_range_filters_on_published_at_falling_back_to_created_at(): void
    {
        $this->vacancy(['title' => 'Too Early', 'published_at' => '2026-07-01 09:00:00']);
        $this->vacancy(['title' => 'In Range', 'published_at' => '2026-07-10 23:30:00']);
        $this->vacancy(['title' => 'Too Late', 'published_at' => '2026-07-20 01:00:00']);
        // No published_at: the filter has to fall back to created_at, same as the rendered column.
        $this->vacancy(['title' => 'Fallback Row', 'published_at' => null])
            ->forceFill(['created_at' => '2026-07-10 08:00:00'])->save();

        $response = $this->get('/vacancies?from=2026-07-10&to=2026-07-10');

        $response->assertOk();
        $response->assertSee('In Range');
        $response->assertSee('Fallback Row');
        $response->assertDontSee('Too Early');
        $response->assertDontSee('Too Late');
    }

    public function test_filters_are_carried_over_into_sort_status_and_pagination_links(): void
    {
        foreach (range(1, 51) as $i) {
            $this->vacancy(['title' => "Laravel Role {$i}"]);
        }

        $response = $this->get('/vacancies?q=Laravel&company=Company');

        $response->assertOk();
        // Sort header, status tab and pagination links all keep both filters.
        $response->assertSee('sort=score&amp;dir=desc', false);
        $response->assertSee('q=Laravel', false);
        $response->assertSee('company=Company', false);
        $response->assertSee('status=matched', false);
        $response->assertSee('page=2', false);
    }

    public function test_malformed_filter_input_is_ignored_instead_of_failing(): void
    {
        $this->vacancy(['title' => 'Still Listed']);

        $this->get('/vacancies?from=abc&to=2026-13-99')->assertOk()->assertSee('Still Listed');
        $this->get('/vacancies?sort[]=score')->assertOk()->assertSee('Still Listed');
        $this->get('/vacancies?q[]=x&company[]=y')->assertOk()->assertSee('Still Listed');
        // The dropdown always sends source[], so a scalar or a nested array is ignored, not applied.
        $this->get('/vacancies?source=linkedin')->assertOk()->assertSee('Still Listed');
        $this->get('/vacancies?source[][]=linkedin')->assertOk()->assertSee('Still Listed');
        // An empty value must not narrow the list down to the rows with an empty source, i.e. none.
        $this->get('/vacancies?source[]=')->assertOk()->assertSee('Still Listed');
    }

    public function test_new_tab_shows_the_latest_run_because_no_row_keeps_the_new_status(): void
    {
        $older = Run::create(['trigger' => 'manual', 'status' => 'ok']);
        $latest = Run::create(['trigger' => 'manual', 'status' => 'ok']);
        // Scoring rewrites 'new' to matched/rejected, so real rows never carry it.
        $this->vacancy(['title' => 'From Latest Run', 'status' => 'matched', 'run_id' => $latest->id]);
        $this->vacancy(['title' => 'Also From Latest', 'status' => 'rejected', 'run_id' => $latest->id]);
        $this->vacancy(['title' => 'From Older Run', 'status' => 'matched', 'run_id' => $older->id]);

        $response = $this->get('/vacancies?status=new');

        $response->assertOk();
        $response->assertSee('From Latest Run');
        $response->assertSee('Also From Latest');
        $response->assertDontSee('From Older Run');
        $response->assertSee('2 записей');
    }

    public function test_status_tabs_filter_on_the_status_column(): void
    {
        $this->vacancy(['title' => 'Matched One', 'status' => 'matched']);
        $this->vacancy(['title' => 'Done One', 'status' => 'done']);
        $this->vacancy(['title' => 'Rejected One', 'status' => 'rejected']);

        foreach (['matched' => 'Matched One', 'done' => 'Done One', 'rejected' => 'Rejected One'] as $status => $kept) {
            $response = $this->get("/vacancies?status={$status}");
            $response->assertOk();
            $response->assertSee($kept);
            $response->assertSee('1 записей');
        }
    }

    public function test_status_and_text_filters_combine(): void
    {
        $this->vacancy(['title' => 'Laravel Dev', 'status' => 'matched']);
        $this->vacancy(['title' => 'Laravel Ops', 'status' => 'rejected']);
        $this->vacancy(['title' => 'Python Dev', 'status' => 'matched']);

        $response = $this->get('/vacancies?status=matched&q=Laravel');

        $response->assertOk();
        $response->assertSee('Laravel Dev');
        $response->assertDontSee('Laravel Ops');
        $response->assertDontSee('Python Dev');
    }

    public function test_empty_result_explains_that_filters_matched_nothing(): void
    {
        $this->vacancy(['title' => 'Something Else']);

        $response = $this->get('/vacancies?q=nothing-matches-this');

        $response->assertOk();
        $response->assertSee('Ничего не найдено по заданным фильтрам.');
        $response->assertDontSee('Пока пусто. Запустите поиск на панели.');
    }
}
