<?php

namespace App\Http\Controllers;

use App\Models\Resume;
use App\Models\Setting;
use App\Services\ClaudeCli;
use Illuminate\Http\Request;
use Smalot\PdfParser\Parser;

class ResumeController extends Controller
{
    public function store(Request $request, ClaudeCli $claude)
    {
        $request->validate(['resume' => ['required', 'file', 'mimes:pdf', 'max:10240']]);

        $file = $request->file('resume');
        $path = $file->store('resumes');

        try {
            $text = $this->extractText($file->getRealPath());
        } catch (\Throwable $e) {
            return back()->with('error', __('Could not extract text from the PDF: :error', ['error' => $e->getMessage()]));
        }
        if ($text === '') {
            return back()->with('error', __('The PDF has no extractable text (it may be a scan).'));
        }

        $keywords = [];
        $warning = null;
        try {
            $answer = $claude->json(
                "Here is a resume. Extract the candidate's tech stack as 5-8 short search keywords "
                . '(technologies/frameworks, e.g. "PHP", "Laravel", "Vue.js"), ordered by importance. '
                . "Respond with ONLY a JSON array of strings.\n\nRESUME:\n" . $text,
                timeout: 180,
            );
            $keywords = array_values(array_filter(array_map(
                fn ($kw) => is_string($kw) ? trim($kw) : null,
                is_array($answer) ? $answer : [],
            )));
        } catch (\Throwable $e) {
            $warning = __('Claude could not extract keywords: :error', ['error' => $e->getMessage()]);
        }

        Resume::query()->update(['is_active' => false]);
        Resume::query()->create([
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'text' => $text,
            'keywords' => $keywords,
            'is_active' => true,
        ]);

        if ($keywords !== []) {
            Setting::set('search_keywords', $keywords);
        }

        return back()->with(
            $warning ? 'error' : 'status',
            $warning ?? __('Resume uploaded. Keywords: :keywords', ['keywords' => implode(', ', $keywords)]),
        );
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:50000'],
            'keywords' => ['nullable', 'array', 'max:20'],
            // nullable: an empty tag arrives as null after ConvertEmptyStringsToNull
            'keywords.*' => ['nullable', 'string', 'max:60'],
        ]);

        $resume = Resume::active();
        if (! $resume) {
            return back()->with('error', __('Upload a resume first.'));
        }

        // The tags are the search keywords, so "Go" and " go " must not become two searches.
        $keywords = collect($data['keywords'] ?? [])
            ->map(fn ($keyword) => trim((string) $keyword))
            ->filter(fn (string $keyword) => $keyword !== '')
            ->unique(fn (string $keyword) => mb_strtolower($keyword))
            ->values()
            ->all();

        $resume->update(['text' => $data['text'], 'keywords' => $keywords]);
        Setting::set('search_keywords', $keywords);

        return back()->with('status', __('Resume saved.'));
    }

    /** Text of the stored PDF for the editor; nothing is saved until the form is. */
    public function restoreText()
    {
        $file = $this->activePdf();
        if (! $file) {
            return response()->json(['message' => __('The original PDF was not found.')], 404);
        }

        try {
            $text = $this->extractText($file);
        } catch (\Throwable $e) {
            return response()->json(['message' => __('Could not extract text from the PDF: :error', ['error' => $e->getMessage()])], 422);
        }
        if ($text === '') {
            return response()->json(['message' => __('The PDF has no extractable text (it may be a scan).')], 422);
        }

        return response()->json(['text' => $text]);
    }

    public function file()
    {
        $file = $this->activePdf();
        abort_unless($file, 404);

        return response()->file($file, ['Content-Type' => 'application/pdf']);
    }

    private function activePdf(): ?string
    {
        $resume = Resume::active();
        $file = $resume ? storage_path("app/private/{$resume->path}") : null;

        return $file && is_file($file) ? $file : null;
    }

    /** One normalization for an upload and for restoring the text from the stored file. */
    private function extractText(string $file): string
    {
        return trim(preg_replace('/[ \t]+/', ' ', (new Parser)->parseFile($file)->getText()));
    }
}
