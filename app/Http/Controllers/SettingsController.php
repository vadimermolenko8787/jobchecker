<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\TelegramNotifier;
use Cron\CronExpression;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate([
            'cron_expression' => ['required', 'string'],
            'schedule_enabled' => ['nullable', 'boolean'],
            'sources' => ['nullable', 'array'],
            'search_keywords' => ['nullable', 'string'],
            'locations' => ['nullable', 'string'],
            'remote_only' => ['nullable', 'boolean'],
            'include_keywords' => ['nullable', 'string'],
            'exclude_keywords' => ['nullable', 'string'],
            'min_score' => ['required', 'integer', 'min:0', 'max:100'],
            'max_generate_per_run' => ['required', 'integer', 'min:0', 'max:50'],
            'cover_letter_language' => ['required', 'string', 'in:' . implode(',', array_keys(\App\Services\DocumentGenerator::LANGUAGES))],
            'dou_category' => ['nullable', 'string'],
            'djinni_primary_keyword' => ['nullable', 'string'],
            'justjoin_category' => ['nullable', 'integer'],
            'indeed_country' => ['nullable', 'string', 'size:2'],
            'telegram_enabled' => ['nullable', 'boolean'],
            'telegram_bot_token' => ['nullable', 'string', 'max:255'],
            'telegram_chat_id' => ['nullable', 'string', 'max:255'],
        ]);

        if (! CronExpression::isValidExpression($data['cron_expression'])) {
            return back()->with('error', 'Невалидное cron-выражение: ' . $data['cron_expression'])->withInput();
        }

        $csv = fn (?string $value) => array_values(array_filter(array_map('trim', explode(',', (string) $value))));

        Setting::set('cron_expression', $data['cron_expression']);
        Setting::set('schedule_enabled', (bool) ($data['schedule_enabled'] ?? false));
        Setting::set('sources', array_map(
            fn (string $key) => (bool) ($data['sources'][$key] ?? false),
            array_combine(array_keys(Setting::DEFAULTS['sources']), array_keys(Setting::DEFAULTS['sources'])),
        ));
        Setting::set('search_keywords', $csv($data['search_keywords'] ?? null));
        Setting::set('locations', $csv($data['locations'] ?? null));
        Setting::set('remote_only', (bool) ($data['remote_only'] ?? false));
        Setting::set('include_keywords', $csv($data['include_keywords'] ?? null));
        Setting::set('exclude_keywords', $csv($data['exclude_keywords'] ?? null));
        Setting::set('min_score', (int) $data['min_score']);
        Setting::set('max_generate_per_run', (int) $data['max_generate_per_run']);
        Setting::set('cover_letter_language', $data['cover_letter_language']);
        Setting::set('dou_category', $data['dou_category'] ?? 'PHP');
        Setting::set('djinni_primary_keyword', $data['djinni_primary_keyword'] ?? 'PHP');
        Setting::set('justjoin_category', (int) ($data['justjoin_category'] ?? 3));
        Setting::set('indeed_country', strtoupper($data['indeed_country'] ?? 'DE'));
        Setting::set('telegram_enabled', (bool) ($data['telegram_enabled'] ?? false));
        Setting::set('telegram_bot_token', trim((string) ($data['telegram_bot_token'] ?? '')));
        Setting::set('telegram_chat_id', trim((string) ($data['telegram_chat_id'] ?? '')));

        return back()->with('status', 'Настройки сохранены.');
    }

    public function telegramTest(TelegramNotifier $telegram)
    {
        $token = (string) Setting::get('telegram_bot_token');
        $chatId = (string) Setting::get('telegram_chat_id');

        if ($token === '' || $chatId === '') {
            return back()->with('error', 'Сначала сохраните bot token и chat ID.');
        }

        try {
            $telegram->sendText($token, $chatId, 'JobChecker: тестовое сообщение ✅');
        } catch (\Throwable $e) {
            return back()->with('error', 'Не удалось отправить: ' . $e->getMessage());
        }

        return back()->with('status', 'Тестовое сообщение отправлено.');
    }
}
