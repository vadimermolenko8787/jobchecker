<?php

namespace App\Http\Controllers;

use App\Models\Resume;
use App\Models\Run;
use App\Models\Vacancy;
use App\Services\DocumentGenerator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class VacancyController extends Controller
{
    private const SORTABLE = [
        'score' => 'score',
        'title' => 'title',
        'company' => 'company',
        'source' => 'source',
        'location' => 'location',
        'status' => 'status',
        'date' => 'COALESCE(published_at, created_at)',
        'applied' => 'applied_at',
    ];

    public function index(Request $request)
    {
        $status = $request->query('status');
        $sort = array_key_exists($request->query('sort'), self::SORTABLE) ? $request->query('sort') : 'date';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $vacancies = Vacancy::query()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByRaw(self::SORTABLE[$sort] . ' ' . $dir)
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('vacancies.index', [
            'vacancies' => $vacancies,
            'status' => $status,
            'sort' => $sort,
            'dir' => $dir,
            'lastRunId' => Run::query()->latest('id')->value('id'),
        ]);
    }

    public function show(Vacancy $vacancy)
    {
        $readFile = function (?string $path): ?string {
            $full = $path ? storage_path("app/private/{$path}") : null;

            return $full && is_file($full) ? file_get_contents($full) : null;
        };
        $resumeIsHtml = str_ends_with((string) $vacancy->resume_path, '.html');

        return view('vacancies.show', [
            'vacancy' => $vacancy,
            'resumeIsHtml' => $resumeIsHtml,
            'resumeMd' => $resumeIsHtml ? ($readFile($vacancy->resume_path) ? '1' : null) : $readFile($vacancy->resume_path),
            'coverMd' => $readFile($vacancy->cover_letter_path),
            'generating' => Cache::get("vacancy-generating:{$vacancy->id}"),
            'generationError' => Cache::pull("vacancy-generating-error:{$vacancy->id}"),
            'hasResume' => Resume::active() !== null,
        ]);
    }

    public function toggleApplied(Vacancy $vacancy)
    {
        $vacancy->update(['applied_at' => $vacancy->applied_at ? null : now()]);

        return back()->with('status', $vacancy->applied_at
            ? "Отмечено: подано {$vacancy->applied_at->format('d.m.Y H:i')}."
            : 'Отметка о подаче снята.');
    }

    public function generate(Request $request, Vacancy $vacancy, string $doc)
    {
        if (! Resume::active()) {
            return back()->with('error', 'Сначала загрузите резюме на панели.');
        }
        if (Cache::has("vacancy-generating:{$vacancy->id}")) {
            return back()->with('error', 'Генерация для этой вакансии уже идёт.');
        }
        $lang = $request->input('lang');
        if (! array_key_exists((string) $lang, DocumentGenerator::LANGUAGES)) {
            $lang = null;
        }

        Cache::put("vacancy-generating:{$vacancy->id}", $doc, now()->addMinutes(15));
        $php = escapeshellarg(PHP_BINARY);
        $artisan = escapeshellarg(base_path('artisan'));
        $id = (int) $vacancy->id;
        $docArg = escapeshellarg($doc);
        $langArg = $lang ? ' --lang=' . escapeshellarg($lang) : '';
        exec("nohup {$php} {$artisan} jobs:generate {$id} --doc={$docArg}{$langArg} > /dev/null 2>&1 &");

        return back()->with('status', 'Генерация запущена, займёт минуту-две. Страница обновится сама.');
    }

    public function previewResume(Vacancy $vacancy)
    {
        $full = $vacancy->resume_path ? storage_path("app/private/{$vacancy->resume_path}") : null;
        abort_unless($full && is_file($full) && str_ends_with($full, '.html'), 404);

        return response(file_get_contents($full))->header('Content-Type', 'text/html; charset=utf-8');
    }

    public function download(Vacancy $vacancy, string $doc, string $format = 'md')
    {
        $path = $doc === 'resume' ? $vacancy->resume_path : $vacancy->cover_letter_path;
        abort_unless($path && is_file(storage_path("app/private/{$path}")), 404);
        $full = storage_path("app/private/{$path}");
        $isHtml = str_ends_with($path, '.html');

        if ($format !== 'pdf') {
            $ext = $isHtml ? 'html' : 'md';

            return response()->download($full, "vacancy-{$vacancy->id}-{$doc}.{$ext}");
        }

        $html = $isHtml
            ? file_get_contents($full)
            : view('pdf.document', [
                'title' => ($doc === 'resume' ? 'Resume — ' : 'Cover letter — ') . $vacancy->title,
                'html' => Str::markdown(file_get_contents($full), ['html_input' => 'strip']),
            ])->render();

        return Pdf::loadHTML($html)->setPaper('a4')->download("vacancy-{$vacancy->id}-{$doc}.pdf");
    }
}
