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
            $text = (new Parser)->parseFile($file->getRealPath())->getText();
        } catch (\Throwable $e) {
            return back()->with('error', 'Не удалось извлечь текст из PDF: ' . $e->getMessage());
        }
        $text = trim(preg_replace('/[ \t]+/', ' ', $text));
        if ($text === '') {
            return back()->with('error', 'PDF не содержит извлекаемого текста (возможно, это скан).');
        }

        $keywords = [];
        $warning = null;
        try {
            $answer = $claude->json(
                "Here is a resume. Extract the candidate's tech stack as 5-8 short search keywords "
                . "(technologies/frameworks, e.g. \"PHP\", \"Laravel\", \"Vue.js\"), ordered by importance. "
                . "Respond with ONLY a JSON array of strings.\n\nRESUME:\n" . $text,
                timeout: 180,
            );
            $keywords = array_values(array_filter(array_map(
                fn ($kw) => is_string($kw) ? trim($kw) : null,
                is_array($answer) ? $answer : [],
            )));
        } catch (\Throwable $e) {
            $warning = 'Claude не смог извлечь ключевые слова: ' . $e->getMessage();
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
            $warning ?? 'Резюме загружено. Ключевые слова: ' . implode(', ', $keywords),
        );
    }
}
