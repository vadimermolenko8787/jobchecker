<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class VacancyDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        // Documents live in storage/app/private/output/{id}, and test ids collide with real ones.
        $this->storage = sys_get_temp_dir() . '/jobchecker-test-' . uniqid();
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    /** @param array<string, string> $files file name in output/{id} => content */
    private function vacancyWith(array $files): Vacancy
    {
        $vacancy = Vacancy::create([
            'source' => 'dou', 'external_id' => 'ext-1', 'title' => 'PHP Developer',
            'company' => 'Acme', 'url' => 'https://example.test/1', 'status' => 'matched',
        ]);
        File::ensureDirectoryExists(storage_path("app/private/output/{$vacancy->id}"));
        foreach ($files as $name => $content) {
            File::put(storage_path("app/private/output/{$vacancy->id}/{$name}"), $content);
            $column = str_starts_with($name, 'resume') ? 'resume_path' : 'cover_letter_path';
            $vacancy->{$column} = "output/{$vacancy->id}/{$name}";
        }
        $vacancy->save();

        return $vacancy;
    }

    private function stored(Vacancy $vacancy, string $name): string
    {
        return File::get(storage_path("app/private/output/{$vacancy->id}/{$name}"));
    }

    public function test_saving_the_cv_replaces_its_body_keeps_its_style_and_strips_scripts(): void
    {
        $vacancy = $this->vacancyWith([
            'resume.html' => '<!doctype html><html><head><style>h1{color:#c00}</style></head><body class="cv"><h1>Old</h1></body></html>',
        ]);

        $this->put(route('vacancies.documents.update', [$vacancy, 'resume']), [
            'html' => '<h1 onclick="x()">Опыт · Doświadczenie</h1><SCRIPT>alert(1)</SCRIPT><p><a href="javascript:alert(1)">link</a></p><iframe src="https://e.x"></iframe>',
        ])->assertRedirect(route('vacancies.show', $vacancy) . '#cv')->assertSessionHas('status');

        $html = $this->stored($vacancy, 'resume.html');
        $this->assertStringContainsString('<style>h1{color:#c00}</style>', $html);
        $this->assertStringContainsString('<body class="cv"><h1>Опыт · Doświadczenie</h1><p><a>link</a></p></body>', $html);
        $this->assertStringNotContainsStringIgnoringCase('script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('iframe', $html);
        $this->assertStringNotContainsString('Old', $html);
    }

    public function test_saving_a_markdown_cover_letter_turns_it_into_html(): void
    {
        $vacancy = $this->vacancyWith(['cover_letter.md' => "Dear team\n"]);

        $this->put(route('vacancies.documents.update', [$vacancy, 'cover_letter']), [
            'html' => '<p>Dear Acme team, dziękuję</p>',
        ])->assertRedirect(route('vacancies.show', $vacancy) . '#cover');

        $vacancy->refresh();
        $this->assertSame("output/{$vacancy->id}/cover_letter.html", $vacancy->cover_letter_path);
        $this->assertFileDoesNotExist(storage_path("app/private/output/{$vacancy->id}/cover_letter.md"));
        $html = $this->stored($vacancy, 'cover_letter.html');
        $this->assertStringContainsString('<body><p>Dear Acme team, dziękuję</p></body>', $html);
        $this->assertStringContainsString('@page', $html);
    }

    public function test_a_legacy_markdown_cv_is_saved_as_html(): void
    {
        $vacancy = $this->vacancyWith(['resume.md' => "# Jane Doe\n"]);

        $this->put(route('vacancies.documents.update', [$vacancy, 'resume']), ['html' => '<h1>Jane Roe</h1>']);

        $vacancy->refresh();
        $this->assertSame("output/{$vacancy->id}/resume.html", $vacancy->resume_path);
        $this->assertFileDoesNotExist(storage_path("app/private/output/{$vacancy->id}/resume.md"));
        $this->assertStringContainsString('<body><h1>Jane Roe</h1></body>', $this->stored($vacancy, 'resume.html'));
    }

    public function test_a_stored_cv_without_a_body_element_is_wrapped_in_the_template(): void
    {
        $vacancy = $this->vacancyWith(['resume.html' => '<h1>Old</h1>']);

        $this->put(route('vacancies.documents.update', [$vacancy, 'resume']), ['html' => '<h1>New</h1>']);

        $html = $this->stored($vacancy, 'resume.html');
        $this->assertStringContainsString('<body><h1>New</h1></body>', $html);
        $this->assertStringContainsString('@page', $html);
    }

    public function test_a_styled_cv_without_a_body_element_keeps_its_styles(): void
    {
        $vacancy = $this->vacancyWith(['resume.html' => '<style>h1{color:#c00}</style><h1>Old</h1>']);

        $this->put(route('vacancies.documents.update', [$vacancy, 'resume']), ['html' => '<h1>New</h1>']);

        $html = $this->stored($vacancy, 'resume.html');
        $this->assertStringContainsString('<style>h1{color:#c00}</style>', $html);
        $this->assertStringContainsString('<body><h1>New</h1></body>', $html);
        $this->assertStringNotContainsString('Old', $html);
    }

    public function test_a_stray_closing_tag_does_not_drop_the_rest_of_the_document(): void
    {
        $vacancy = $this->vacancyWith(['resume.html' => '<html><body><h1>Old</h1></body></html>']);

        $this->put(route('vacancies.documents.update', [$vacancy, 'resume']), ['html' => '<p>one</p></div><p>two</p><h2>three</h2>']);

        $this->assertStringContainsString('<body><p>one</p><p>two</p><h2>three</h2></body>', $this->stored($vacancy, 'resume.html'));
    }

    public function test_saving_needs_html_and_an_existing_document(): void
    {
        $vacancy = $this->vacancyWith(['resume.html' => '<html><body>x</body></html>']);

        $this->from(route('vacancies.show', $vacancy))
            ->put(route('vacancies.documents.update', [$vacancy, 'resume']), ['html' => ''])
            ->assertSessionHasErrors('html');
        $this->put(route('vacancies.documents.update', [$vacancy, 'cover_letter']), ['html' => '<p>x</p>'])
            ->assertNotFound();
    }

    public function test_the_pdf_is_shown_inline_and_a_missing_document_is_not_found(): void
    {
        $vacancy = $this->vacancyWith(['resume.html' => '<html><body><h1>Jane Doe</h1></body></html>']);

        $response = $this->get(route('vacancies.pdf', [$vacancy, 'resume']))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));

        $this->get(route('vacancies.pdf', [$vacancy, 'cover_letter']))->assertNotFound();
    }

    public function test_an_html_cover_letter_downloads_as_html_and_as_pdf(): void
    {
        $vacancy = $this->vacancyWith(['cover_letter.html' => '<html><body><p>Dear team</p></body></html>']);

        $this->get(route('vacancies.download', [$vacancy, 'cover_letter']))
            ->assertDownload("vacancy-{$vacancy->id}-cover_letter.html");
        $this->get(route('vacancies.download', [$vacancy, 'cover_letter', 'pdf']))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_a_vacancy_without_documents_offers_to_generate_them(): void
    {
        $vacancy = $this->vacancyWith([]);

        $this->get(route('vacancies.show', $vacancy))->assertOk()
            ->assertSee('href="https://example.test/1"', false)
            ->assertSee(route('vacancies.generate', [$vacancy, 'resume']) . '#cv', false)
            ->assertSee(route('vacancies.generate', [$vacancy, 'cover_letter']) . '#cover', false)
            ->assertDontSee(route('vacancies.pdf', [$vacancy, 'resume']), false)
            ->assertDontSee('data-confirm="', false);
    }

    public function test_a_vacancy_with_a_cv_shows_its_pdf_and_editor(): void
    {
        $vacancy = $this->vacancyWith([
            'resume.html' => '<html><head><style>h1{color:#c00}</style></head><body><h1>Jane Doe</h1></body></html>',
        ]);

        $this->get(route('vacancies.show', $vacancy))->assertOk()
            ->assertSee(route('vacancies.pdf', [$vacancy, 'resume']), false)
            ->assertSee(route('vacancies.documents.update', [$vacancy, 'resume']), false)
            ->assertSee('&lt;h1&gt;Jane Doe&lt;/h1&gt;', false)
            ->assertSee('h1{color:#c00}', false)
            // regenerating would overwrite manual edits, so it asks first
            ->assertSee('data-confirm="', false);
    }

    public function test_an_html_cover_letter_is_edited_as_html(): void
    {
        $vacancy = $this->vacancyWith(['cover_letter.html' => '<html><body><p>Dear Acme team</p></body></html>']);

        $this->get(route('vacancies.show', $vacancy))->assertOk()
            ->assertSee(route('vacancies.pdf', [$vacancy, 'cover_letter']), false)
            ->assertSee('&lt;p&gt;Dear Acme team&lt;/p&gt;', false);
    }

    public function test_a_legacy_markdown_cv_opens_in_the_editor_as_html(): void
    {
        $vacancy = $this->vacancyWith(['resume.md' => "# Jane Doe\n"]);

        $this->get(route('vacancies.show', $vacancy))->assertOk()
            ->assertSee('&lt;h1&gt;Jane Doe&lt;/h1&gt;', false);
    }
}
