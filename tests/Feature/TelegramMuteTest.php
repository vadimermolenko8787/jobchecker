<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramMuteTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-token';

    private const CHAT_ID = '42';

    /** @var array<int, array<string, mixed>> what the next getUpdates call returns */
    private array $updates = [];

    /** Bot API method the fake answers with an error, e.g. an expired callback query. */
    private ?string $failing = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        Setting::set('telegram_enabled', true);
        Setting::set('telegram_bot_token', self::TOKEN);
        Setting::set('telegram_chat_id', self::CHAT_ID);
        // Read at request time, so a test can set up its updates after the fake is in place.
        Http::fake(function (Request $request) {
            if ($this->failing !== null && str_ends_with($request->url(), "/{$this->failing}")) {
                return Http::response(['description' => 'query is too old'], 400);
            }

            return Http::response(str_contains($request->url(), '/getUpdates')
                ? ['ok' => true, 'result' => $this->updates]
                : ['ok' => true, 'result' => true]);
        });
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

    /** @param array<string, mixed> $callback */
    private function press(array $callback, int $updateId = 100): void
    {
        $this->updates = [['update_id' => $updateId, 'callback_query' => $callback]];
    }

    /** @return array<string, mixed> нажатие кнопки под сообщением в настроенном чате */
    private function pressPayload(string $data, int|string $chatId = self::CHAT_ID): array
    {
        return [
            'id' => 'cb-1',
            'data' => $data,
            'message' => ['message_id' => 7, 'chat' => ['id' => $chatId]],
        ];
    }

    /** @return array<string, mixed> единственная кнопка из reply_markup вызова Bot API */
    private function button(array $payload): array
    {
        return json_decode($payload['reply_markup'], true)['inline_keyboard'][0][0];
    }

    /**
     * @return array<int, array<string, mixed>> полезная нагрузка вызовов одного метода Bot API
     */
    private function calls(string $method): array
    {
        return Http::recorded()
            ->filter(fn (array $pair) => str_ends_with($pair[0]->url(), "/{$method}"))
            ->map(fn (array $pair) => $pair[0]->data())
            ->values()->all();
    }

    public function test_nothing_is_polled_while_telegram_is_off(): void
    {
        Setting::set('telegram_enabled', false);

        $this->assertSame(0, Artisan::call('telegram:poll'));
        Http::assertNothingSent();
    }

    public function test_a_press_mutes_the_vacancy(): void
    {
        $vacancy = $this->vacancy();
        $this->press($this->pressPayload("mute:{$vacancy->id}"));

        $this->assertSame(0, Artisan::call('telegram:poll'));

        $this->assertNotNull($vacancy->refresh()->muted_at);
        $this->assertSame('🔕 Больше не присылаю', $this->calls('answerCallbackQuery')[0]['text']);
    }

    public function test_the_button_under_the_message_switches_to_the_opposite_action(): void
    {
        $vacancy = $this->vacancy();
        $this->press($this->pressPayload("mute:{$vacancy->id}"));

        Artisan::call('telegram:poll');

        $edit = $this->calls('editMessageReplyMarkup')[0];
        $this->assertSame(7, (int) $edit['message_id']);
        $this->assertSame(
            ['text' => '🔔 Присылать снова', 'callback_data' => "unmute:{$vacancy->id}"],
            $this->button($edit),
        );
    }

    public function test_the_mark_lands_on_every_board_copy_of_the_job(): void
    {
        $dou = $this->vacancy(['source' => 'dou']);
        // Same job on another board: its own row, the title spelled a bit differently.
        $linkedin = $this->vacancy(['source' => 'linkedin', 'title' => 'php  Developer', 'company' => 'acme']);
        $other = $this->vacancy(['company' => 'Globex']);

        $this->press($this->pressPayload("mute:{$dou->id}"));
        Artisan::call('telegram:poll');

        $this->assertNotNull($linkedin->refresh()->muted_at);
        $this->assertNull($other->refresh()->muted_at);

        $this->press($this->pressPayload("unmute:{$linkedin->id}"), updateId: 101);
        Artisan::call('telegram:poll');

        $this->assertNull($dou->refresh()->muted_at);
    }

    public function test_the_button_is_redrawn_even_when_the_toast_is_already_expired(): void
    {
        // Telegram accepts an answer for a few seconds after the press; the redrawn button has
        // no such window, so it stays the confirmation when the answer comes too late.
        $this->failing = 'answerCallbackQuery';
        $vacancy = $this->vacancy();
        $this->press($this->pressPayload("mute:{$vacancy->id}"));

        $this->assertSame(0, Artisan::call('telegram:poll'));

        $this->assertNotNull($vacancy->refresh()->muted_at);
        $this->assertSame("unmute:{$vacancy->id}", $this->button($this->calls('editMessageReplyMarkup')[0])['callback_data']);
    }

    public function test_the_poll_waits_for_a_press_instead_of_looking_once(): void
    {
        Artisan::call('telegram:poll');

        // Without the wait the press is picked up up to a minute late, and by then Telegram
        // refuses the answer, which is what makes the button look dead in the chat.
        $this->assertGreaterThan(0, (int) $this->calls('getUpdates')[0]['timeout']);
    }

    public function test_a_second_press_unmutes_it(): void
    {
        $vacancy = $this->vacancy(['muted_at' => now()]);
        $this->press($this->pressPayload("unmute:{$vacancy->id}"));

        Artisan::call('telegram:poll');

        $this->assertNull($vacancy->refresh()->muted_at);
        $this->assertSame('🔔 Снова присылаю', $this->calls('answerCallbackQuery')[0]['text']);
        $this->assertSame(
            ['text' => '🔕 Не присылать', 'callback_data' => "mute:{$vacancy->id}"],
            $this->button($this->calls('editMessageReplyMarkup')[0]),
        );
    }

    public function test_a_press_from_another_chat_changes_nothing(): void
    {
        $vacancy = $this->vacancy();
        $this->press($this->pressPayload("mute:{$vacancy->id}", chatId: 999));

        Artisan::call('telegram:poll');

        $this->assertNull($vacancy->refresh()->muted_at);
        $this->assertSame([], $this->calls('answerCallbackQuery'));
    }

    public function test_a_handled_press_is_not_read_again(): void
    {
        $vacancy = $this->vacancy();
        $this->press($this->pressPayload("mute:{$vacancy->id}"), updateId: 100);

        Artisan::call('telegram:poll');
        $this->updates = [];
        Artisan::call('telegram:poll');

        $this->assertSame(101, (int) Setting::get('telegram_update_offset'));
        $this->assertSame(101, (int) $this->calls('getUpdates')[1]['offset']);
    }

    public function test_an_empty_queue_leaves_the_offset_alone(): void
    {
        Setting::set('telegram_update_offset', 101);

        $this->assertSame(0, Artisan::call('telegram:poll'));

        $this->assertSame(101, (int) Setting::get('telegram_update_offset'));
    }

    public function test_an_update_without_an_id_does_not_rewind_the_offset(): void
    {
        Setting::set('telegram_update_offset', 101);
        $this->updates = [['callback_query' => $this->pressPayload('mute:1')]];

        $this->assertSame(0, Artisan::call('telegram:poll'));

        $this->assertSame(101, (int) Setting::get('telegram_update_offset'));
        $this->assertSame([], $this->calls('answerCallbackQuery'));
    }

    public function test_a_press_on_a_deleted_vacancy_is_answered_not_crashed(): void
    {
        $this->press($this->pressPayload('mute:999999'));

        $this->assertSame(0, Artisan::call('telegram:poll'));

        $this->assertSame('Вакансия не найдена.', $this->calls('answerCallbackQuery')[0]['text']);
    }

    public function test_callback_data_from_another_feature_is_ignored(): void
    {
        $vacancy = $this->vacancy();
        $this->press($this->pressPayload('subscribe:all'));

        $this->assertSame(0, Artisan::call('telegram:poll'));

        $this->assertNull($vacancy->refresh()->muted_at);
        $this->assertSame([], $this->calls('answerCallbackQuery'));
        $this->assertSame(101, (int) Setting::get('telegram_update_offset'));
    }

    public function test_the_web_button_switches_the_mark_both_ways(): void
    {
        $vacancy = $this->vacancy();

        $this->post(route('vacancies.muted', $vacancy))->assertRedirect();
        $this->assertNotNull($vacancy->refresh()->muted_at);

        $this->post(route('vacancies.muted', $vacancy))->assertRedirect();
        $this->assertNull($vacancy->refresh()->muted_at);
    }

    public function test_a_connection_error_fails_the_run_without_printing_the_token(): void
    {
        Http::fake(fn () => throw new ConnectionException(
            'cURL error 7: Failed to connect to api.telegram.org/bot' . self::TOKEN . '/getUpdates',
        ));

        $this->assertSame(1, Artisan::call('telegram:poll'));

        $output = Artisan::output();
        $this->assertStringNotContainsString(self::TOKEN, $output);
        $this->assertStringContainsString('***', $output);
    }
}
