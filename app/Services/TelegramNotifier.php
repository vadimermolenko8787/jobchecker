<?php

namespace App\Services;

use App\Models\Vacancy;
use Illuminate\Support\Facades\Http;

class TelegramNotifier
{
    private const MAX_LENGTH = 3900;
    private const SUMMARY_LENGTH = 600;

    public function sendVacancy(string $token, string $chatId, Vacancy $vacancy): void
    {
        $this->sendText($token, $chatId, $this->formatMessage($vacancy));
    }

    public function sendText(string $token, string $chatId, string $text): void
    {
        $response = Http::timeout(15)->connectTimeout(10)
            ->asForm()
            ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

        if (! $response->successful()) {
            $description = $response->json('description') ?? ('HTTP ' . $response->status());
            throw new \RuntimeException("Telegram API: {$description}");
        }
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
