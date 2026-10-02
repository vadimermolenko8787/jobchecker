<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\DocumentGenerator;
use App\Services\Sources\LocationCatalog;
use App\Services\Sources\PracujSource;
use App\Services\TelegramNotifier;
use App\Services\VacancyScorer;
use Cron\CronExpression;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function edit()
    {
        return view('settings.edit', ['settings' => Setting::all_settings()]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'cron_expression' => ['required', 'string'],
            'schedule_enabled' => ['nullable', 'boolean'],
            'sources' => ['nullable', 'array'],
            'locations' => ['nullable', 'array'],
            'locations.*' => ['string', Rule::in(array_keys(LocationCatalog::LOCATIONS))],
            'locations_custom' => ['nullable', 'string'],
            'remote_only' => ['nullable', 'boolean'],
            'include_keywords' => ['nullable', 'string'],
            'exclude_keywords' => ['nullable', 'string'],
            'min_score' => ['required', 'integer', 'min:0', 'max:100'],
            'score_weights' => ['required', 'array'],
            'score_weights.skills' => ['required', 'integer', 'min:0', 'max:100'],
            'score_weights.stack' => ['required', 'integer', 'min:0', 'max:100'],
            'score_weights.seniority' => ['required', 'integer', 'min:0', 'max:100'],
            'score_weights.location' => ['required', 'integer', 'min:0', 'max:100'],
            'cover_letter_language' => ['required', 'string', 'in:' . implode(',', array_keys(DocumentGenerator::LANGUAGES))],
            'known_languages' => ['nullable', 'string'],
            'company_research_enabled' => ['nullable', 'boolean'],
            'company_research_ttl_days' => ['required', 'integer', 'min:1', 'max:365'],
            'dou_category' => ['nullable', 'string'],
            'djinni_primary_keyword' => ['nullable', 'string'],
            'justjoin_category' => ['nullable', 'integer'],
            'pracuj_categories' => ['nullable', 'array'],
            'pracuj_categories.*' => ['string', Rule::in(array_keys(PracujSource::CATEGORIES))],
            'jooble_keys' => ['nullable', 'string'],
            'indeed_api_key' => ['nullable', 'string', 'max:255'],
            'linkedin_batch_size' => ['required', 'integer', 'min:1', 'max:10'],
            'linkedin_batch_pause' => ['required', 'integer', 'min:0', 'max:300'],
            'telegram_enabled' => ['nullable', 'boolean'],
            'telegram_bot_token' => ['nullable', 'string', 'max:255'],
            'telegram_chat_id' => ['nullable', 'string', 'max:255'],
        ], [
            'locations.*.in' => __('Location :input is not in the catalog.'),
            'pracuj_categories.*.in' => __('Category :input is not in the it.pracuj.pl catalog.'),
        ]);

        if (! CronExpression::isValidExpression($data['cron_expression'])) {
            return back()->with('error', __('Invalid cron expression: :expression', ['expression' => $data['cron_expression']]))->withInput();
        }

        $weights = array_map(
            fn (string $key) => (int) $data['score_weights'][$key],
            array_combine(VacancyScorer::CRITERIA, VacancyScorer::CRITERIA),
        );
        if (array_sum($weights) === 0) {
            return back()->with('error', __('At least one criterion weight must be above zero.'))->withInput();
        }

        $csv = fn (?string $value) => array_values(array_filter(array_map('trim', explode(',', (string) $value))));

        // "de:KEY, pl:KEY": a jooble key only works on the country site it was issued for.
        $joobleKeys = [];
        foreach ($csv($data['jooble_keys'] ?? null) as $entry) {
            if (! preg_match('/^([a-z]{2})\s*:\s*([A-Za-z0-9-]+)$/i', $entry, $m)) {
                return back()->with('error', __('A jooble key is written as a country code and the key separated by a colon, for example de:xxxxxxxx.'))->withInput();
            }
            $joobleKeys[strtolower($m[1])] = $m[2];
        }

        Setting::set('cron_expression', $data['cron_expression']);
        Setting::set('schedule_enabled', (bool) ($data['schedule_enabled'] ?? false));
        Setting::set('sources', array_map(
            fn (string $key) => (bool) ($data['sources'][$key] ?? false),
            array_combine(array_keys(Setting::DEFAULTS['sources']), array_keys(Setting::DEFAULTS['sources'])),
        ));
        // search_keywords are not in this form: they are edited with the resume on the dashboard.
        // Catalog picks and free-form entries end up in one flat list of location strings.
        $locations = array_values(array_unique(array_merge(
            array_values($data['locations'] ?? []),
            $csv($data['locations_custom'] ?? null),
        )));
        Setting::set('locations', $locations ?: Setting::DEFAULTS['locations']);
        Setting::set('remote_only', (bool) ($data['remote_only'] ?? false));
        Setting::set('include_keywords', $csv($data['include_keywords'] ?? null));
        Setting::set('exclude_keywords', $csv($data['exclude_keywords'] ?? null));
        Setting::set('min_score', (int) $data['min_score']);
        Setting::set('score_weights', $weights);
        Setting::set('cover_letter_language', $data['cover_letter_language']);
        Setting::set('known_languages', $csv($data['known_languages'] ?? null) ?: Setting::DEFAULTS['known_languages']);
        Setting::set('company_research_enabled', (bool) ($data['company_research_enabled'] ?? false));
        Setting::set('company_research_ttl_days', (int) $data['company_research_ttl_days']);
        Setting::set('dou_category', $data['dou_category'] ?? 'PHP');
        Setting::set('djinni_primary_keyword', $data['djinni_primary_keyword'] ?? 'PHP');
        Setting::set('justjoin_category', (int) ($data['justjoin_category'] ?? 3));
        Setting::set('pracuj_categories', array_values($data['pracuj_categories'] ?? []) ?: Setting::DEFAULTS['pracuj_categories']);
        Setting::set('jooble_keys', $joobleKeys);
        Setting::set('indeed_api_key', trim((string) ($data['indeed_api_key'] ?? '')));
        Setting::set('linkedin_batch_size', (int) $data['linkedin_batch_size']);
        Setting::set('linkedin_batch_pause', (int) $data['linkedin_batch_pause']);
        Setting::set('telegram_enabled', (bool) ($data['telegram_enabled'] ?? false));
        Setting::set('telegram_bot_token', trim((string) ($data['telegram_bot_token'] ?? '')));
        Setting::set('telegram_chat_id', trim((string) ($data['telegram_chat_id'] ?? '')));

        return back()->with('status', __('Settings saved.'));
    }

    public function telegramTest(TelegramNotifier $telegram)
    {
        $token = (string) Setting::get('telegram_bot_token');
        $chatId = (string) Setting::get('telegram_chat_id');

        if ($token === '' || $chatId === '') {
            return back()->with('error', __('Save the bot token and chat ID first.'));
        }

        try {
            $telegram->sendText($token, $chatId, __('JobChecker: test message ✅'));
        } catch (\Throwable $e) {
            return back()->with('error', __('Could not send: :error', ['error' => $e->getMessage()]));
        }

        return back()->with('status', __('Test message sent.'));
    }
}
