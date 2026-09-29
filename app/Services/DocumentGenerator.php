<?php

namespace App\Services;

use App\Models\Resume;
use App\Models\Setting;
use App\Models\Vacancy;
use Illuminate\Support\Facades\File;

class DocumentGenerator
{
    // code => language name used in the prompt ('auto' is special-cased)
    public const LANGUAGES = [
        'en' => 'English',
        'uk' => 'Ukrainian',
        'de' => 'German',
        'pl' => 'Polish',
        'ru' => 'Russian',
        'auto' => 'vacancy language',
    ];

    // code => label shown in the UI, translated by languageLabels()
    public const LANGUAGE_LABELS = [
        'en' => 'English',
        'uk' => 'Ukrainian',
        'de' => 'German',
        'pl' => 'Polish',
        'ru' => 'Russian',
        'auto' => 'Vacancy language',
    ];

    /** @return array<string, string> code => label in the current locale */
    public static function languageLabels(): array
    {
        return array_map(fn (string $label) => __($label), self::LANGUAGE_LABELS);
    }

    public function __construct(private ClaudeCli $claude) {}

    /**
     * Generate application documents for a vacancy.
     *
     * @param  string  $what  'resume' | 'cover_letter' | 'both'
     * @param  string|null  $coverLanguage  language code from self::LANGUAGES; null = global setting
     * @param  string|null  $extraInstructions  free-form user instructions for the cover letter
     */
    public function generate(Resume $resume, Vacancy $vacancy, string $what = 'both', ?string $coverLanguage = null, ?string $extraInstructions = null): void
    {
        $coverLanguage ??= (string) Setting::get('cover_letter_language');
        if (! isset(self::LANGUAGES[$coverLanguage])) {
            $coverLanguage = 'en';
        }
        $wantResume = in_array($what, ['resume', 'both'], true);
        $wantCover = in_array($what, ['cover_letter', 'both'], true);

        // Give claude the original PDF so it can replicate the resume's visual
        // layout, not just its content.
        $workDir = storage_path("app/claude-work/vacancy-{$vacancy->id}");
        File::ensureDirectoryExists($workDir);
        $originalPdf = storage_path("app/private/{$resume->path}");
        $hasPdf = is_file($originalPdf);
        if ($hasPdf) {
            File::copy($originalPdf, "{$workDir}/original-resume.pdf");
        }

        $tasks = [];
        $format = [];
        if ($wantResume) {
            $tasks[] = $hasPdf
                ? '- Read the file original-resume.pdf in the current directory — study BOTH its content and its visual '
                  . 'design (layout, fonts, colors, spacing, section order, dividers). Then produce a tailored version of '
                  . 'the resume as a COMPLETE standalone HTML document that replicates the original design as closely as '
                  . 'possible. Keep the same sections, the same order and the same overall look; only adapt wording and '
                  . "emphasis to this vacancy. Do not invent facts that are not in the original resume.\n"
                  . '  HTML constraints (it will be rendered to PDF by dompdf): one self-contained file with an inline '
                  . '<style> block; NO external resources, images, web fonts or JavaScript; NO flexbox or CSS grid — use '
                  . "simple block elements and tables for multi-column areas; fonts limited to 'DejaVu Sans', 'DejaVu Serif' "
                  . "or 'DejaVu Sans Mono'; set @page margins to roughly match the original."
                : '- Produce a tailored version of the resume below as a COMPLETE standalone HTML document with a clean, '
                  . 'professional single-column design (self-contained, inline <style>, no external resources, no '
                  . "flexbox/grid, DejaVu fonts only). Do not invent facts that are not in the original resume.\n"
                  . "CANDIDATE RESUME:\n" . $resume->text;
            $format[] = "===RESUME_HTML===\n<complete html document>";
        }
        if ($wantCover) {
            if (! $wantResume || ! $hasPdf) {
                $tasks[] = "Base the cover letter on this resume:\nCANDIDATE RESUME:\n" . $resume->text;
            }
            $coverLangInstruction = $coverLanguage === 'auto'
                ? 'written in the same language as the vacancy description'
                : 'written in ' . self::LANGUAGES[$coverLanguage];
            $tasks[] = "- Write a concise, specific cover letter in Markdown (max ~300 words) for this vacancy, {$coverLangInstruction}.";
            if ($extraInstructions !== null && trim($extraInstructions) !== '') {
                $tasks[] = '- Additional instructions from the candidate for the cover letter, follow them: ' . trim($extraInstructions);
            }
            $format[] = "===COVER_LETTER===\n<cover letter markdown>";
        }
        if ($wantCover && ! $wantResume && $hasPdf) {
            array_unshift($tasks, '- Read the file original-resume.pdf in the current directory to learn the candidate\'s background.');
        }

        $prompt = 'You are helping a candidate apply for a job. The resume must be in English; '
            . "the cover letter language is specified in its task.\n\n"
            . "VACANCY:\n"
            . "Title: {$vacancy->title}\nCompany: {$vacancy->company}\nLocation: {$vacancy->location}\n"
            . "Description:\n" . mb_substr(strip_tags((string) $vacancy->description), 0, 6000) . "\n\n"
            . "TASKS:\n" . implode("\n", $tasks) . "\n\n"
            . "Respond in EXACTLY this format with these delimiters and nothing else:\n"
            . implode("\n", $format);

        try {
            $text = $this->claude->run($prompt, allowedTools: $hasPdf ? ['Read'] : [], workDir: $workDir);
        } finally {
            File::deleteDirectory($workDir);
        }

        $dir = "output/{$vacancy->id}";
        File::ensureDirectoryExists(storage_path("app/private/{$dir}"));

        if ($wantResume) {
            $pattern = $wantCover
                ? '/===RESUME_HTML===\s*(.*?)\s*===COVER_LETTER===/s'
                : '/===RESUME_HTML===\s*(.*)$/s';
            if (! preg_match($pattern, $text, $m)) {
                throw new \RuntimeException('unexpected generation output format (resume)');
            }
            $html = preg_replace('/^```(?:html)?\s*|\s*```$/', '', trim($m[1]));
            $vacancy->resume_path = "{$dir}/resume.html";
            File::put(storage_path("app/private/{$vacancy->resume_path}"), $html . "\n");
        }

        if ($wantCover) {
            if (! preg_match('/===COVER_LETTER===\s*(.*)$/s', $text, $m)) {
                throw new \RuntimeException('unexpected generation output format (cover letter)');
            }
            $vacancy->cover_letter_path = "{$dir}/cover_letter.md";
            File::put(storage_path("app/private/{$vacancy->cover_letter_path}"), trim($m[1]) . "\n");
        }

        if ($vacancy->resume_path && $vacancy->cover_letter_path) {
            $vacancy->status = 'done';
        }
        $vacancy->save();
    }
}
