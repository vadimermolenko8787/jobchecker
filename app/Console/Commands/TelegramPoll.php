<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\Vacancy;
use App\Services\TelegramNotifier;
use Illuminate\Console\Command;

class TelegramPoll extends Command
{
    /** Where the id of the last processed update is kept between runs. */
    private const OFFSET_KEY = 'telegram_update_offset';

    protected $signature = 'telegram:poll';
    protected $description = 'Pick up presses of the mute button under Telegram messages';

    /**
     * The app is local and has no public URL, so a webhook is out: presses are fetched with
     * getUpdates instead. The call waits on the open connection, so a press is picked up within
     * a second of it, and the scheduler only has to keep one such wait alive.
     */
    public function handle(TelegramNotifier $telegram): int
    {
        $settings = Setting::all_settings();
        $token = trim((string) ($settings['telegram_bot_token'] ?? ''));
        $chatId = trim((string) ($settings['telegram_chat_id'] ?? ''));
        if (empty($settings['telegram_enabled']) || $token === '' || $chatId === '') {
            return self::SUCCESS;
        }

        try {
            $updates = $telegram->getUpdates($token, (int) Setting::get(self::OFFSET_KEY, 0));
        } catch (\Throwable $e) {
            $this->warn('Telegram: ' . $e->getMessage());

            return self::FAILURE;
        }

        foreach ($updates as $update) {
            $updateId = (int) ($update['update_id'] ?? 0);
            // An element without a usable id must not move the offset: zero would rewind it and
            // the whole backlog would be replayed, toggling old presses again every minute.
            if ($updateId <= 0) {
                continue;
            }
            // The offset moves for ignored updates too: otherwise one foreign callback would be
            // re-read on every run and block everything queued behind it.
            Setting::set(self::OFFSET_KEY, $updateId + 1);

            if (! is_array($update['callback_query'] ?? null)) {
                continue;
            }
            try {
                $this->handleCallback($telegram, $token, $chatId, $update['callback_query']);
            } catch (\Throwable $e) {
                $this->warn('Telegram: ' . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $callback */
    private function handleCallback(TelegramNotifier $telegram, string $token, string $chatId, array $callback): void
    {
        // The bot can be added to any chat, and a press from there must not touch the vacancies.
        if ((string) ($callback['message']['chat']['id'] ?? '') !== $chatId) {
            return;
        }
        $callbackId = (string) ($callback['id'] ?? '');
        if ($callbackId === '' || ! preg_match('/^(mute|unmute):(\d+)$/', (string) ($callback['data'] ?? ''), $parsed)) {
            return;
        }

        $vacancy = Vacancy::query()->find((int) $parsed[2]);
        if ($vacancy === null) {
            $telegram->answerCallback($token, $callbackId, 'Вакансия не найдена.');

            return;
        }

        $mute = $parsed[1] === 'mute';
        $vacancy->muteJob($mute);
        $this->info("[{$vacancy->id}] " . ($mute ? 'заглушена' : 'снова присылается'));

        // The redrawn button is the lasting confirmation and it can be sent at any time, so it
        // goes first. The toast expires seconds after the press, and a poll that arrived late
        // must not let that dead toast hide the reaction, hence its own catch.
        $messageId = (int) ($callback['message']['message_id'] ?? 0);
        if ($messageId > 0) {
            $telegram->updateMuteButton($token, $chatId, $messageId, $vacancy);
        }
        try {
            $telegram->answerCallback($token, $callbackId, $mute ? '🔕 Больше не присылаю' : '🔔 Снова присылаю');
        } catch (\Throwable $e) {
            $this->warn("[{$vacancy->id}] подтверждение нажатия не принято: {$e->getMessage()}");
        }
    }
}
