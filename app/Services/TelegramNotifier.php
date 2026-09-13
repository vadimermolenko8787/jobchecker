<?php

namespace App\Services;

use App\Models\Vacancy;
use Illuminate\Support\Facades\Http;

class TelegramNotifier
{
    private const MAX_LENGTH = 3900;
    private const SUMMARY_LENGTH = 600;
    /** Seconds getUpdates holds the connection open waiting for a press. */
    private const POLL_SECONDS = 50;

    public function sendVacancy(string $token, string $chatId, Vacancy $vacancy): void
    {
        $this->call($token, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => $this->formatMessage($vacancy),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => json_encode($this->muteKeyboard($vacancy)),
        ]);
    }

    public function sendText(string $token, string $chatId, string $text): void
    {
        $this->call($token, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);
    }

    /** @return array<int, array<string, mixed>> апдейты начиная с offset */
    public function getUpdates(string $token, int $offset): array
    {
        // Long polling, because a press stays answerable for only a few seconds: a poll that
        // merely looks once a minute still gets the press, but always too late for Telegram
        // to accept the reply, and the chat then shows nothing at all.
        $response = $this->call($token, 'getUpdates', [
            'timeout' => self::POLL_SECONDS,
            'offset' => $offset,
            'allowed_updates' => json_encode(['callback_query']),
        ], self::POLL_SECONDS + 10);

        return is_array($response['result'] ?? null) ? $response['result'] : [];
    }

    /** Stops the spinner on the pressed button and shows the toast. */
    public function answerCallback(string $token, string $callbackId, string $text): void
    {
        $this->call($token, 'answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => $text,
        ]);
    }

    /** Redraws the button under an already sent message so its label matches the new state. */
    public function updateMuteButton(string $token, string $chatId, int $messageId, Vacancy $vacancy): void
    {
        $this->call($token, 'editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => json_encode($this->muteKeyboard($vacancy)),
        ]);
    }

    /** @return array<string, mixed> */
    private function call(string $token, string $method, array $payload, int $timeout = 15): array
    {
        try {
            $response = Http::timeout($timeout)->connectTimeout(10)
                ->asForm()
                ->post("https://api.telegram.org/bot{$token}/{$method}", $payload);
        } catch (\Throwable $e) {
            // A connection error carries the full request URL, and the token sits in it. Callers
            // write these messages into the run log and onto the dashboard, so it is masked here
            // rather than at each of them. The original exception is dropped for the same reason.
            throw new \RuntimeException(str_replace($token, '***', $e->getMessage()));
        }

        if (! $response->successful()) {
            $description = $response->json('description') ?? ('HTTP ' . $response->status());
            throw new \RuntimeException("Telegram API: {$description}");
        }

        return (array) $response->json();
    }

    /** @return array<string, mixed> */
    private function muteKeyboard(Vacancy $vacancy): array
    {
        $muted = $vacancy->muted_at !== null;

        return ['inline_keyboard' => [[[
            'text' => $muted ? '🔔 Присылать снова' : '🔕 Не присылать',
            'callback_data' => ($muted ? 'unmute:' : 'mute:') . $vacancy->id,
        ]]]];
    }

    private function formatMessage(Vacancy $vacancy): string
    {
        $e = fn (?string $value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        $lines = ['<b>' . $e($vacancy->title) . '</b>'];

        // Without this a re-posted vacancy arrives a second time and reads as a duplicate.
        if ($vacancy->bumped_at) {
            $lines[] = '🔁 Переопубликована источником ' . $e($vacancy->published_at?->format('d.m.Y H:i'));
        }
        $companyLocation = implode(', ', array_filter([$vacancy->company, $vacancy->location]));
        if ($companyLocation !== '') {
            $lines[] = $e($companyLocation);
        }
        $lines[] = '🌐 Источник: ' . $e($vacancy->source);
        if ($vacancy->salary) {
            $lines[] = '💰 ' . $e($vacancy->salary);
        }
        if ($vacancy->score !== null) {
            $lines[] = "Score: {$vacancy->score}/100";
        }
        if (is_array($vacancy->score_breakdown['criteria'] ?? null)) {
            $parts = [];
            foreach (VacancyScorer::LABELS as $key => $label) {
                $parts[] = $label . ' ' . (int) ($vacancy->score_breakdown['criteria'][$key]['score'] ?? 0);
            }
            $lines[] = $e(implode(' · ', $parts));
        }
        if ($vacancy->score_reason) {
            $lines[] = $e($vacancy->score_reason);
        }
        if ($summary = $vacancy->analysis['summary'] ?? null) {
            $lines[] = '';
            $lines[] = '<i>' . $e(mb_substr($summary, 0, self::SUMMARY_LENGTH)) . '</i>';
        }
        $lang = $vacancy->analysis['language'] ?? null;
        if (in_array($lang['language_fit'] ?? null, ['warning', 'critical'], true)) {
            $icon = $lang['language_fit'] === 'critical' ? '‼️' : '⚠️';
            $note = trim(implode('. ', array_filter([$lang['vacancy_language'] ?? null, $lang['note'] ?? null])), ' .');
            $lines[] = $icon . ' Язык: ' . $e($note . '.');
        }
        $lines[] = '';
        $lines[] = '<a href="' . $e($vacancy->url) . '">Открыть вакансию</a>';

        return mb_substr(implode("\n", $lines), 0, self::MAX_LENGTH);
    }
}
