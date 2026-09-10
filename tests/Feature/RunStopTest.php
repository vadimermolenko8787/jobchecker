<?php

namespace Tests\Feature;

use App\Models\Resume;
use App\Models\Run;
use App\Models\Setting;
use App\Models\Vacancy;
use App\Services\Pipeline;
use App\Services\VacancyScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RunStopTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // A stop must be noticed before anything leaves the machine, so an outgoing
        // request here is itself the failure the assertions below look for.
        Http::fake();
    }

    private function startedRun(): Run
    {
        return Run::create(['trigger' => 'manual', 'status' => 'running', 'started_at' => now()]);
    }

    private function invoke(string $method, Run $run, array $args, array &$stats): mixed
    {
        $reflected = new \ReflectionMethod(Pipeline::class, $method);

        return $reflected->invokeArgs(app(Pipeline::class), [$run, ...$args, &$stats]);
    }

    public function test_the_button_raises_the_flag_on_the_running_search(): void
    {
        $run = $this->startedRun();

        $this->post(route('run.stop'))->assertRedirect();

        $this->assertTrue($run->stopRequested());
    }

    public function test_stopping_with_nothing_running_says_so(): void
    {
        $this->startedRun()->update(['status' => 'ok', 'finished_at' => now()]);

        $this->post(route('run.stop'))->assertSessionHas('error');
    }

    public function test_the_remaining_sources_are_skipped(): void
    {
        $run = $this->startedRun();
        $run->requestStop();
        $stats = [];

        $fetched = $this->invoke('fetchSources', $run, [Setting::all_settings()], $stats);

        $this->assertSame([], $fetched);
        $this->assertArrayNotHasKey('fetched', $stats);
        Http::assertNothingSent();
        $this->assertStringContainsString('Остановка по запросу', (string) $run->refresh()->log);
    }

    public function test_the_remaining_scoring_is_skipped_and_vacancies_stay_unscored(): void
    {
        $resume = Resume::create(['original_name' => 'cv.pdf', 'path' => 'cv.pdf', 'text' => 'PHP']);
        $vacancy = Vacancy::create([
            'source' => 'dou', 'external_id' => 'ext-1', 'title' => 'PHP Developer',
            'url' => 'https://example.test/1', 'status' => 'new',
        ]);
        $scorer = $this->createMock(VacancyScorer::class);
        $scorer->expects($this->never())->method('scoreBatch');
        $this->instance(VacancyScorer::class, $scorer);

        $run = $this->startedRun();
        $run->requestStop();
        $stats = [];

        $matched = $this->invoke('scoreNew', $run, [$resume, Setting::all_settings()], $stats);

        $this->assertTrue($matched->isEmpty());
        // Still 'new', so the next run picks it up instead of it silently keeping a half verdict.
        $this->assertSame('new', $vacancy->refresh()->status);
        $this->assertNull($vacancy->score);
    }

    public function test_a_stopped_run_finishes_as_cancelled(): void
    {
        Setting::set('sources', []);
        $run = $this->startedRun();
        $run->requestStop();

        app(Pipeline::class)->execute($run);

        $run->refresh();
        $this->assertSame('cancelled', $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertStringContainsString('Остановлено по запросу', (string) $run->log);
    }

    public function test_a_run_nobody_stopped_still_finishes_as_ok(): void
    {
        Setting::set('sources', []);
        $run = $this->startedRun();

        app(Pipeline::class)->execute($run);

        $this->assertSame('ok', $run->refresh()->status);
    }
}
