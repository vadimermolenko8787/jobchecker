<?php

namespace Tests\Feature;

use App\Models\Run;
use App\Models\Vacancy;
use App\Services\Pipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramNotifyTest extends TestCase
{
    use RefreshDatabase;

    private const SETTINGS = [
        'telegram_enabled' => true,
        'telegram_bot_token' => 'test-token',
        'telegram_chat_id' => '42',
    ];

    private int $telegramStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        // Read at request time, so a test can make the API fail without stacking a second stub.
        Http::fake(['api.telegram.org/*' => fn () => Http::response(
            $this->telegramStatus === 200 ? ['ok' => true] : ['description' => 'chat not found'],
            $this->telegramStatus,
        )]);
    }

    /** @param Vacancy[] $matched */
    private function notify(array $matched): array
    {
        $stats = [];
        $run = Run::create(['trigger' => 'manual', 'status' => 'running']);
        $method = new \ReflectionMethod(Pipeline::class, 'notifyMatched');
        $method->invokeArgs(app(Pipeline::class), [$run, collect($matched), self::SETTINGS, &$stats]);

        return $stats;
    }

    private function vacancy(array $attributes = []): Vacancy
    {
        static $n = 0;
        $n++;

        return Vacancy::create(array_merge([
            'source' => 'dou',
            'external_id' => "ext-{$n}",
            'title' => 'PHP Developer',
            'company' => 'Acme',
            'url' => "https://example.test/{$n}",
            'status' => 'matched',
            'score' => 82,
        ], $attributes));
    }

    /** @return string[] тексты отправленных сообщений */
    private function sentTexts(): array
    {
        return Http::recorded()->map(fn (array $pair) => $pair[0]->data()['text'])->all();
    }

    public function test_the_message_names_the_source(): void
    {
        $this->notify([$this->vacancy(['source' => 'linkedin'])]);

        $this->assertStringContainsString('🌐 Источник: linkedin', $this->sentTexts()[0]);
    }

    public function test_the_same_job_from_two_sources_is_sent_once(): void
    {
        $dou = $this->vacancy(['source' => 'dou']);
        // Same job, another board: its own external id, and the title spelled a bit differently.
        $linkedin = $this->vacancy(['source' => 'linkedin', 'title' => 'php  Developer', 'company' => 'acme']);

        $stats = $this->notify([$dou, $linkedin]);

        $this->assertSame(1, $stats['notified']);
        $this->assertCount(1, $this->sentTexts());
        $this->assertNotNull($dou->refresh()->notified_at);
        $this->assertNull($linkedin->refresh()->notified_at);
    }

    public function test_a_job_already_sent_today_is_skipped(): void
    {
        $this->vacancy(['source' => 'dou', 'notified_at' => now()->startOfDay()->addHour()]);
        $repost = $this->vacancy(['source' => 'indeed']);

        $stats = $this->notify([$repost]);

        $this->assertSame(0, $stats['notified']);
        $this->assertSame([], $this->sentTexts());
        $this->assertNull($repost->refresh()->notified_at);
    }

    public function test_yesterdays_message_does_not_block_today(): void
    {
        $vacancy = $this->vacancy(['notified_at' => now()->subDay()]);

        $stats = $this->notify([$vacancy]);

        $this->assertSame(1, $stats['notified']);
        $this->assertTrue($vacancy->refresh()->notified_at->isToday());
    }

    public function test_the_same_title_at_another_company_is_a_different_job(): void
    {
        $acme = $this->vacancy(['company' => 'Acme']);
        $globex = $this->vacancy(['source' => 'djinni', 'company' => 'Globex']);

        $stats = $this->notify([$acme, $globex]);

        $this->assertSame(2, $stats['notified']);
        $this->assertCount(2, $this->sentTexts());
    }

    public function test_a_muted_vacancy_is_not_sent(): void
    {
        $vacancy = $this->vacancy(['muted_at' => now()]);

        $stats = $this->notify([$vacancy]);

        $this->assertSame(0, $stats['notified']);
        $this->assertSame([], $this->sentTexts());
        $this->assertNull($vacancy->refresh()->notified_at);
    }

    public function test_muting_one_board_silences_the_same_job_on_the_others(): void
    {
        $this->vacancy(['source' => 'dou', 'muted_at' => now()]);
        $copy = $this->vacancy(['source' => 'linkedin', 'title' => 'php  Developer', 'company' => 'acme']);

        $stats = $this->notify([$copy]);

        $this->assertSame(0, $stats['notified']);
        $this->assertSame([], $this->sentTexts());
    }

    public function test_muting_one_job_leaves_the_others_alone(): void
    {
        $this->vacancy(['company' => 'Acme', 'muted_at' => now()]);
        $globex = $this->vacancy(['source' => 'djinni', 'company' => 'Globex']);

        $stats = $this->notify([$globex]);

        $this->assertSame(1, $stats['notified']);
        $this->assertNotNull($globex->refresh()->notified_at);
    }

    public function test_a_failed_send_leaves_the_job_open_for_the_next_run(): void
    {
        $this->telegramStatus = 400;
        $vacancy = $this->vacancy();

        $stats = $this->notify([$vacancy]);

        $this->assertSame(0, $stats['notified']);
        $this->assertNull($vacancy->refresh()->notified_at);
    }
}
