<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['key', 'value'];
    protected $casts = ['value' => 'json'];

    public const DEFAULTS = [
        'cron_expression' => '0 */6 * * *',
        'schedule_enabled' => false,
        'sources' => [
            'dou' => true,
            'djinni' => true,
            'justjoin' => true,
            'linkedin' => true,
            'indeed' => false,
            'pracuj' => true,
        ],
        'search_keywords' => [],
        'locations' => ['Germany'],
        'remote_only' => true,
        'include_keywords' => [],
        'exclude_keywords' => [],
        'min_score' => 70,
        'score_weights' => ['skills' => 40, 'stack' => 25, 'seniority' => 20, 'location' => 15],
        'cover_letter_language' => 'en',
        'known_languages' => ['English', 'Russian', 'Ukrainian'],
        'company_research_enabled' => false,
        'company_research_ttl_days' => 30,
        'dou_category' => 'PHP',
        'djinni_primary_keyword' => 'PHP',
        'justjoin_category' => 3,
        'pracuj_categories' => ['5016', '5015'],
        'linkedin_batch_size' => 2,
        'linkedin_batch_pause' => 15,
        'telegram_enabled' => false,
        'telegram_bot_token' => '',
        'telegram_chat_id' => '',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::query()->find($key);
        if ($row !== null) {
            return $row->value;
        }

        return $default ?? (static::DEFAULTS[$key] ?? null);
    }

    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** @return array<string, mixed> */
    public static function all_settings(): array
    {
        $stored = static::query()->pluck('value', 'key')->all();

        return array_merge(static::DEFAULTS, $stored);
    }
}
