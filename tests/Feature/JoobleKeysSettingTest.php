<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JoobleKeysSettingTest extends TestCase
{
    use RefreshDatabase;

    /** The smallest settings form the controller accepts. */
    private function save(string $joobleKeys)
    {
        return $this->from('/')->post('/settings', [
            'cron_expression' => '0 */6 * * *',
            'min_score' => 70,
            'score_weights' => ['skills' => 40, 'stack' => 25, 'seniority' => 20, 'location' => 15],
            'cover_letter_language' => 'en',
            'company_research_ttl_days' => 30,
            'linkedin_batch_size' => 2,
            'linkedin_batch_pause' => 15,
            'jooble_keys' => $joobleKeys,
        ]);
    }

    public function test_country_and_key_pairs_are_stored_by_lowercase_country(): void
    {
        $this->save('DE:aaaa1111-2222, pl : bbbb3333-4444')->assertSessionHas('status');

        $this->assertSame(['de' => 'aaaa1111-2222', 'pl' => 'bbbb3333-4444'], Setting::get('jooble_keys'));
    }

    public function test_an_empty_field_clears_the_keys(): void
    {
        Setting::set('jooble_keys', ['de' => 'old']);

        $this->save('')->assertSessionHas('status');

        $this->assertSame([], Setting::get('jooble_keys'));
    }

    public function test_a_key_without_a_country_is_rejected_and_nothing_is_saved(): void
    {
        Setting::set('jooble_keys', ['de' => 'old']);

        $this->save('aaaa1111-2222')->assertSessionHas('error');

        $this->assertSame(['de' => 'old'], Setting::get('jooble_keys'));
    }
}
