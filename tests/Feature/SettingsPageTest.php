<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_the_settings_page_holds_the_settings_form(): void
    {
        Setting::set('search_keywords', ['PHP', 'Laravel']);

        $this->get('/settings')->assertOk()
            ->assertSee('action="' . route('settings.update') . '"', false)
            ->assertSee('name="cron_expression"', false)
            ->assertSee('name="telegram_chat_id"', false)
            ->assertSee('form="telegram-test"', false)
            // keywords are shown, but edited on the dashboard only
            ->assertSee('Laravel')
            ->assertDontSee('name="search_keywords"', false);
    }

    public function test_the_dashboard_no_longer_holds_the_settings_form(): void
    {
        $this->get('/')->assertOk()
            ->assertDontSee('name="cron_expression"', false)
            ->assertSee('href="' . route('settings.edit') . '"', false);
    }

    public function test_saving_settings_keeps_the_search_keywords(): void
    {
        Setting::set('search_keywords', ['PHP', 'Laravel']);

        $this->from('/settings')->post('/settings', [
            'cron_expression' => '0 */6 * * *',
            'min_score' => 70,
            'score_weights' => ['skills' => 40, 'stack' => 25, 'seniority' => 20, 'location' => 15],
            'cover_letter_language' => 'en',
            'company_research_ttl_days' => 30,
            'linkedin_batch_size' => 2,
            'linkedin_batch_pause' => 15,
            // a stale form field must not overwrite the keywords edited on the dashboard
            'search_keywords' => 'Go',
        ])->assertRedirect('/settings')->assertSessionHas('status');

        $this->assertSame(['PHP', 'Laravel'], Setting::get('search_keywords'));
    }
}
