<?php

namespace Tests\Feature;

use App\Models\Resume;
use App\Models\Vacancy;
use App\Services\ClaudeCli;
use App\Services\DocumentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DocumentGeneratorPromptTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    private string $prompt = '';

    /** @var string[] */
    private array $tools = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . '/jobchecker-test-' . uniqid();
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function generate(string $what, bool $withPdf): void
    {
        if ($withPdf) {
            File::ensureDirectoryExists(storage_path('app/private/resumes'));
            File::put(storage_path('app/private/resumes/cv.pdf'), '%PDF-1.4 stub');
        }
        $resume = Resume::create([
            'original_name' => 'cv.pdf',
            'path' => 'resumes/cv.pdf',
            'text' => 'EDITED: ten years of Go',
            'keywords' => [],
            'is_active' => true,
        ]);
        $vacancy = Vacancy::create([
            'source' => 'dou', 'external_id' => 'ext-1', 'title' => 'Go Developer',
            'company' => 'Acme', 'url' => 'https://example.test/1', 'status' => 'matched',
        ]);

        $claude = $this->createMock(ClaudeCli::class);
        $claude->expects($this->once())->method('run')->willReturnCallback(
            function (string $prompt, ?int $timeout = null, array $allowedTools = []) {
                $this->prompt = $prompt;
                $this->tools = $allowedTools;

                return "===RESUME_HTML===\n<html><body>cv</body></html>\n===COVER_LETTER===\nDear team";
            },
        );

        (new DocumentGenerator($claude))->generate($resume, $vacancy, $what);
    }

    public function test_the_cv_takes_its_content_from_the_text_and_only_its_design_from_the_pdf(): void
    {
        $this->generate('resume', withPdf: true);

        $this->assertStringContainsString("CANDIDATE RESUME:\nEDITED: ten years of Go", $this->prompt);
        $this->assertStringContainsString('original-resume.pdf', $this->prompt);
        $this->assertStringContainsString('ONLY as a design template', $this->prompt);
        $this->assertSame(['Read'], $this->tools);
    }

    public function test_without_a_pdf_the_cv_prompt_still_carries_the_text(): void
    {
        $this->generate('resume', withPdf: false);

        $this->assertStringContainsString("CANDIDATE RESUME:\nEDITED: ten years of Go", $this->prompt);
        $this->assertStringNotContainsString('original-resume.pdf', $this->prompt);
        $this->assertSame([], $this->tools);
    }

    public function test_a_cover_letter_alone_reads_the_text_and_not_the_pdf(): void
    {
        $this->generate('cover_letter', withPdf: true);

        $this->assertStringContainsString("CANDIDATE RESUME:\nEDITED: ten years of Go", $this->prompt);
        $this->assertStringNotContainsString('original-resume.pdf', $this->prompt);
        $this->assertSame([], $this->tools);
    }
}
