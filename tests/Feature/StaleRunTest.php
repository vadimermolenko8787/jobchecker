<?php

namespace Tests\Feature;

use App\Models\Run;
use App\Services\Pipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class StaleRunTest extends TestCase
{
    use RefreshDatabase;

    private MockObject $pipeline;

    protected function setUp(): void
    {
        parent::setUp();
        // The command is under test, not the search itself: a real pipeline would hit the network.
        $this->pipeline = $this->createMock(Pipeline::class);
        $this->instance(Pipeline::class, $this->pipeline);
    }

    private function runningSince(string $startedAt): Run
    {
        return Run::create(['trigger' => 'cron', 'status' => 'running', 'started_at' => $startedAt]);
    }

    public function test_a_run_a_killed_process_left_behind_is_closed_and_no_longer_blocks(): void
    {
        $abandoned = $this->runningSince(now()->subHours(3)->toDateTimeString());
        $this->pipeline->expects($this->once())->method('execute');

        $this->artisan('jobs:search')->assertSuccessful();

        $abandoned->refresh();
        $this->assertSame('failed', $abandoned->status);
        $this->assertNotNull($abandoned->finished_at);
        $this->assertStringContainsString('прерван извне', (string) $abandoned->log);
        // The point of closing it: the next search actually starts.
        $this->assertSame(2, Run::query()->count());
    }

    public function test_a_live_run_still_blocks_a_second_start_and_is_left_alone(): void
    {
        $live = $this->runningSince(now()->subMinutes(10)->toDateTimeString());
        $this->pipeline->expects($this->never())->method('execute');

        $this->artisan('jobs:search')->assertFailed();

        $this->assertSame('running', $live->refresh()->status);
        $this->assertNull($live->finished_at);
        $this->assertSame(1, Run::query()->count());
    }

    public function test_a_finished_run_is_never_reopened(): void
    {
        $done = Run::create([
            'trigger' => 'cron', 'status' => 'ok',
            'started_at' => now()->subHours(5), 'finished_at' => now()->subHours(5),
        ]);
        $this->pipeline->expects($this->once())->method('execute');

        $this->artisan('jobs:search')->assertSuccessful();

        $this->assertSame('ok', $done->refresh()->status);
    }

    public function test_active_only_counts_a_run_young_enough_to_still_have_a_process(): void
    {
        $this->runningSince(now()->subHours(Run::STALE_AFTER_HOURS)->subMinute()->toDateTimeString());
        $this->assertNull(Run::active());
        $this->assertCount(1, Run::stale());

        $live = $this->runningSince(now()->subMinutes(5)->toDateTimeString());
        $this->assertSame($live->id, Run::active()?->id);
    }
}
