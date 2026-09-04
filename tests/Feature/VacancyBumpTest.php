<?php

namespace Tests\Feature;

use App\Models\Run;
use App\Models\Vacancy;
use App\Services\Pipeline;
use App\Services\Sources\VacancyData;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class VacancyBumpTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  VacancyData[]  $fetched
     * @return array{0: Collection<int, Vacancy>, 1: array}
     */
    private function storeNew(Run $run, array $fetched): array
    {
        $stats = [];
        $method = new \ReflectionMethod(Pipeline::class, 'storeNew');
        $reposted = $method->invokeArgs(app(Pipeline::class), [$run, $fetched, [], &$stats]);

        return [$reposted, $stats];
    }

    private function vacancy(array $attributes = []): Vacancy
    {
        return Vacancy::create(array_merge([
            'source' => 'dou',
            'external_id' => 'https://jobs.dou.ua/companies/acme/vacancies/1/',
            'title' => 'PHP Developer',
            'url' => 'https://jobs.dou.ua/companies/acme/vacancies/1/',
            'description' => 'Original description',
            'published_at' => '2026-07-06 18:08:11',
        ], $attributes));
    }

    private function item(array $overrides = []): VacancyData
    {
        return new VacancyData(
            source: 'dou',
            externalId: 'https://jobs.dou.ua/companies/acme/vacancies/1/',
            title: 'PHP Developer',
            company: 'Acme',
            location: 'Kyiv',
            url: 'https://jobs.dou.ua/companies/acme/vacancies/1/',
            description: 'Fresh description',
            publishedAt: $overrides['publishedAt'] ?? Carbon::parse('2026-08-28 17:44:59'),
        );
    }

    public function test_a_repost_moves_forward_in_time_without_touching_the_verdict(): void
    {
        $vacancy = $this->vacancy(['status' => 'matched', 'score' => 82, 'score_reason' => 'старая причина']);
        $run = Run::create(['trigger' => 'manual', 'status' => 'running']);

        [$reposted, $stats] = $this->storeNew($run, [$this->item()]);

        $this->assertSame(0, $stats['new']);
        $this->assertSame(1, $stats['bumped']);

        $vacancy->refresh();
        $this->assertSame('2026-08-28 17:44:59', $vacancy->published_at->format('Y-m-d H:i:s'));
        $this->assertNotNull($vacancy->bumped_at);
        $this->assertSame($run->id, $vacancy->run_id);
        // The old verdict stands: no re-scoring, so status, score and reason are untouched.
        $this->assertSame('matched', $vacancy->status);
        $this->assertSame(82, $vacancy->score);
        $this->assertSame('старая причина', $vacancy->score_reason);
        // Sources like LinkedIn only load descriptions for rows they do not know yet,
        // so a repost must never overwrite the stored one.
        $this->assertSame('Original description', $vacancy->description);
        $this->assertSame(1, Vacancy::query()->count());
    }

    public function test_a_matched_repost_is_handed_over_for_telegram(): void
    {
        $vacancy = $this->vacancy(['status' => 'matched', 'score' => 82]);
        $run = Run::create(['trigger' => 'manual', 'status' => 'running']);

        [$reposted] = $this->storeNew($run, [$this->item()]);

        $this->assertSame([$vacancy->id], $reposted->pluck('id')->all());
    }

    public function test_a_repost_with_documents_is_still_worth_a_message(): void
    {
        $vacancy = $this->vacancy(['status' => 'done', 'score' => 88]);
        $run = Run::create(['trigger' => 'manual', 'status' => 'running']);

        [$reposted] = $this->storeNew($run, [$this->item()]);

        $this->assertSame([$vacancy->id], $reposted->pluck('id')->all());
    }

    public function test_an_applied_repost_is_not_pushed(): void
    {
        $this->vacancy(['status' => 'matched', 'score' => 82, 'applied_at' => '2026-08-01 10:00:00']);
        $run = Run::create(['trigger' => 'manual', 'status' => 'running']);

        [$reposted, $stats] = $this->storeNew($run, [$this->item()]);

        $this->assertSame(1, $stats['bumped']);
        $this->assertTrue($reposted->isEmpty());
    }

    public function test_a_rejected_repost_is_not_pushed(): void
    {
        $this->vacancy(['status' => 'rejected', 'score' => 40]);
        $run = Run::create(['trigger' => 'manual', 'status' => 'running']);

        [$reposted, $stats] = $this->storeNew($run, [$this->item()]);

        $this->assertSame(1, $stats['bumped']);
        $this->assertTrue($reposted->isEmpty());
    }

    public function test_a_repost_still_waiting_to_be_scored_is_left_to_scoring(): void
    {
        $vacancy = $this->vacancy(['status' => 'new']);
        $run = Run::create(['trigger' => 'manual', 'status' => 'running']);

        [$reposted, $stats] = $this->storeNew($run, [$this->item()]);

        $this->assertSame(1, $stats['bumped']);
        $this->assertTrue($reposted->isEmpty());
        $this->assertSame('new', $vacancy->refresh()->status);
    }

    public function test_an_unchanged_or_older_date_leaves_the_vacancy_alone(): void
    {
        $vacancy = $this->vacancy(['status' => 'rejected', 'published_at' => '2026-08-28 17:44:59']);
        $run = Run::create(['trigger' => 'manual', 'status' => 'running']);

        [$reposted, $stats] = $this->storeNew($run, [
            $this->item(),
            $this->item(['publishedAt' => Carbon::parse('2026-07-01 10:00:00')]),
        ]);

        $this->assertSame(0, $stats['bumped']);
        $this->assertTrue($reposted->isEmpty());

        $vacancy->refresh();
        $this->assertSame('rejected', $vacancy->status);
        $this->assertNull($vacancy->bumped_at);
        $this->assertSame('2026-08-28 17:44:59', $vacancy->published_at->format('Y-m-d H:i:s'));
    }

    public function test_a_source_without_dates_never_bumps(): void
    {
        $this->vacancy(['status' => 'matched', 'published_at' => null]);
        $run = Run::create(['trigger' => 'manual', 'status' => 'running']);

        [$reposted, $stats] = $this->storeNew($run, [$this->item()]);

        $this->assertSame(0, $stats['bumped']);
        $this->assertTrue($reposted->isEmpty());
    }

    public function test_a_first_time_vacancy_is_created_without_a_bump_mark(): void
    {
        $run = Run::create(['trigger' => 'manual', 'status' => 'running']);

        [$reposted, $stats] = $this->storeNew($run, [$this->item()]);

        $this->assertSame(1, $stats['new']);
        $this->assertSame(0, $stats['bumped']);
        $this->assertTrue($reposted->isEmpty());

        $vacancy = Vacancy::query()->sole();
        $this->assertSame('new', $vacancy->status);
        $this->assertNull($vacancy->bumped_at);
        $this->assertSame($run->id, $vacancy->run_id);
    }
}
