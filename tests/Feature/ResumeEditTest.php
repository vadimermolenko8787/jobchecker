<?php

namespace Tests\Feature;

use App\Models\Resume;
use App\Models\Setting;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ResumeEditTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        // The controllers build paths with storage_path(), so a temp storage root keeps
        // the real storage/ out of reach.
        $this->storage = sys_get_temp_dir() . '/jobchecker-test-' . uniqid();
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function resume(?string $pdfHtml = null): Resume
    {
        if ($pdfHtml !== null) {
            File::ensureDirectoryExists(storage_path('app/private/resumes'));
            File::put(storage_path('app/private/resumes/cv.pdf'), Pdf::loadHTML($pdfHtml)->output());
        }

        return Resume::create([
            'original_name' => 'cv.pdf',
            'path' => 'resumes/cv.pdf',
            'text' => 'old text',
            'keywords' => ['PHP'],
            'is_active' => true,
        ]);
    }

    public function test_saving_updates_the_text_keywords_and_search_keywords(): void
    {
        $resume = $this->resume();

        $this->from('/')->put('/resume', [
            'text' => 'Ten years of Go',
            'keywords' => [' Go ', 'go', '', 'Rust'],
        ])->assertRedirect('/')->assertSessionHas('status');

        $resume->refresh();
        $this->assertSame('Ten years of Go', $resume->text);
        $this->assertSame(['Go', 'Rust'], $resume->keywords);
        $this->assertSame(['Go', 'Rust'], Setting::get('search_keywords'));
    }

    public function test_removing_every_keyword_clears_the_search_keywords(): void
    {
        $this->resume();
        Setting::set('search_keywords', ['PHP']);

        $this->from('/')->put('/resume', ['text' => 'Ten years of Go'])->assertSessionHas('status');

        $this->assertSame([], Setting::get('search_keywords'));
    }

    public function test_saving_without_a_resume_reports_an_error(): void
    {
        Setting::set('search_keywords', ['PHP']);

        $this->from('/')->put('/resume', ['text' => 'Ten years of Go', 'keywords' => ['Go']])
            ->assertRedirect('/')->assertSessionHas('error');

        $this->assertSame(['PHP'], Setting::get('search_keywords'));
    }

    public function test_the_text_and_keywords_are_validated(): void
    {
        $this->resume();

        $this->from('/')->put('/resume', ['text' => ''])->assertSessionHasErrors('text');
        $this->from('/')->put('/resume', ['text' => str_repeat('a', 50001)])->assertSessionHasErrors('text');
        $this->from('/')->put('/resume', ['text' => 'ok', 'keywords' => array_fill(0, 21, 'Go')])->assertSessionHasErrors('keywords');
        $this->from('/')->put('/resume', ['text' => 'ok', 'keywords' => [str_repeat('a', 61)]])->assertSessionHasErrors('keywords.0');
    }

    public function test_restore_text_returns_the_text_of_the_stored_pdf_without_saving_it(): void
    {
        $resume = $this->resume('<p>Senior PHP Developer</p><p>Laravel</p>');

        $response = $this->postJson('/resume/restore-text')->assertOk();

        $this->assertStringContainsString('Senior PHP Developer', $response->json('text'));
        $this->assertStringContainsString('Laravel', $response->json('text'));
        $resume->refresh();
        $this->assertSame('old text', $resume->text);
        $this->assertSame(['PHP'], $resume->keywords);
    }

    public function test_restore_text_without_the_pdf_file_answers_with_a_json_error(): void
    {
        $this->resume();

        $this->postJson('/resume/restore-text')->assertNotFound()->assertJsonStructure(['message']);
    }

    public function test_the_original_pdf_is_served_and_a_missing_one_is_not_found(): void
    {
        $this->get('/resume/file')->assertNotFound();

        $this->resume();
        $this->get('/resume/file')->assertNotFound();

        $this->resume('<p>CV</p>');
        $this->get('/resume/file')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
