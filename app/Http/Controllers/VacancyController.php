<?php

namespace App\Http\Controllers;

use App\Models\Company;
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
        'muted' => 'muted_at',
    ];

    public function index(Request $request)
    {
        $status = $request->query('status');
        $sortKey = $request->query('sort');
        $sort = is_string($sortKey) && array_key_exists($sortKey, self::SORTABLE) ? $sortKey : 'date';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $text = function (string $key) use ($request): string {
            $value = $request->query($key);

            return is_string($value) ? mb_substr(trim($value), 0, 200) : '';
        };
        $date = function (string $key) use ($request): string {
            $value = $request->query($key);

            return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
        };
        $list = function (string $key) use ($request): array {
            $value = $request->query($key);
            $value = is_array($value) ? array_filter($value, fn ($v) => is_string($v) && $v !== '') : [];

            return array_values(array_unique($value));
        };
        $filters = [
            'q' => $text('q'),
            'company' => $text('company'),
            'source' => $list('source'),
            'from' => $date('from'),
            'to' => $date('to'),
        ];
        // Search terms are substrings, so % and _ typed by the user must not act as wildcards.
        // "!" as the escape char keeps the clause identical on MariaDB and on SQLite (tests),
        // unlike a backslash, which each engine quotes differently.
        $contains = fn (string $column, string $value) => [
            "{$column} LIKE ? ESCAPE '!'",
            ['%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value) . '%'],
        ];
        $dateColumn = self::SORTABLE['date'];
        $lastRunId = Run::query()->latest('id')->value('id');

        $vacancies = Vacancy::query()
            // Scoring rewrites 'new' to matched/rejected inside the same run, so no row keeps that
            // status. The tab instead means "from the latest run", which is what the row badge marks.
            ->when($status === 'new', fn ($q) => $q->where('run_id', $lastRunId ?? 0))
            ->when($status && $status !== 'new', fn ($q) => $q->where('status', $status))
            ->when($filters['q'] !== '', fn ($q) => $q->whereRaw(...$contains('title', $filters['q'])))
            ->when($filters['company'] !== '', fn ($q) => $q->whereRaw(...$contains('company', $filters['company'])))
            ->when($filters['source'] !== [], fn ($q) => $q->whereIn('source', $filters['source']))
            ->when($filters['from'] !== '', fn ($q) => $q->whereRaw("{$dateColumn} >= ?", [$filters['from'] . ' 00:00:00']))
            ->when($filters['to'] !== '', fn ($q) => $q->whereRaw("{$dateColumn} <= ?", [$filters['to'] . ' 23:59:59']))
            ->orderByRaw(self::SORTABLE[$sort] . ' ' . $dir)
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('vacancies.index', [
            'vacancies' => $vacancies,
            'status' => $status,
            'sort' => $sort,
            'dir' => $dir,
            'filters' => $filters,
            'companies' => Vacancy::query()
                ->whereNotNull('company')->where('company', '!=', '')
                ->distinct()->orderBy('company')->limit(500)->pluck('company'),
            'sources' => Vacancy::query()
                ->where('source', '!=', '')
                ->distinct()->orderBy('source')->pluck('source'),
            'lastRunId' => $lastRunId,
        ]);
    }

    public function show(Vacancy $vacancy)
    {
        $readFile = function (?string $path): ?string {
            $full = $path ? storage_path("app/private/{$path}") : null;

            return $full && is_file($full) ? file_get_contents($full) : null;
        };
        $resumeIsHtml = str_ends_with((string) $vacancy->resume_path, '.html');
        $company = Company::forName($vacancy->company);

        return view('vacancies.show', [
            'vacancy' => $vacancy,
            'resumeIsHtml' => $resumeIsHtml,
            'resumeMd' => $resumeIsHtml ? ($readFile($vacancy->resume_path) ? '1' : null) : $readFile($vacancy->resume_path),
            'coverMd' => $readFile($vacancy->cover_letter_path),
            'generating' => Cache::get("vacancy-generating:{$vacancy->id}"),
            'generationError' => Cache::pull("vacancy-generating-error:{$vacancy->id}"),
            'hasResume' => Resume::active() !== null,
            'company' => $company,
            'companyResearching' => $company && Cache::has("company-researching:{$company->id}"),
        ]);
    }

    public function toggleApplied(Vacancy $vacancy)
    {
        $vacancy->update(['applied_at' => $vacancy->applied_at ? null : now()]);

        return back()->with('status', $vacancy->applied_at
            ? __('Marked as applied on :date.', ['date' => $vacancy->applied_at->format('d.m.Y H:i')])
            : __('Applied mark removed.'));
    }

    public function toggleMuted(Vacancy $vacancy)
    {
        $vacancy->muteJob($vacancy->muted_at === null);

        return back()->with('status', $vacancy->muted_at
            ? __('This vacancy will no longer be sent to Telegram.')
            : __('This vacancy can be sent to Telegram again.'));
    }

    public function generate(Request $request, Vacancy $vacancy, string $doc)
    {
        if (! Resume::active()) {
            return back()->with('error', __('Upload a resume on the dashboard first.'));
        }
        if (Cache::has("vacancy-generating:{$vacancy->id}")) {
            return back()->with('error', __('Generation for this vacancy is already running.'));
        }
        $lang = $request->input('lang');
        if (! array_key_exists((string) $lang, DocumentGenerator::LANGUAGES)) {
            $lang = null;
        }
        $instructions = trim((string) $request->input('extra_instructions'));
        $instructions = mb_substr($instructions, 0, 2000);

        Cache::put("vacancy-generating:{$vacancy->id}", $doc, now()->addMinutes(15));
        $php = escapeshellarg(PHP_BINARY);
        $artisan = escapeshellarg(base_path('artisan'));
        $id = (int) $vacancy->id;
        $docArg = escapeshellarg($doc);
        $langArg = $lang ? ' --lang=' . escapeshellarg($lang) : '';
        $instructionsArg = $instructions !== '' ? ' --instructions=' . escapeshellarg($instructions) : '';
        exec("nohup {$php} {$artisan} jobs:generate {$id} --doc={$docArg}{$langArg}{$instructionsArg} > /dev/null 2>&1 &");

        return back()->with('status', __('Generation started, it takes a minute or two. The page will refresh by itself.'));
    }

    public function researchCompany(Vacancy $vacancy)
    {
        if (! $vacancy->company) {
            return back()->with('error', __('The vacancy has no company.'));
        }
        $company = Company::firstOrCreateForName($vacancy->company);
        if (Cache::has("company-researching:{$company->id}")) {
            return back()->with('error', __('Research of this company is already running.'));
        }

        Cache::put("company-researching:{$company->id}", true, now()->addMinutes(20));
        $php = escapeshellarg(PHP_BINARY);
        $artisan = escapeshellarg(base_path('artisan'));
        $id = (int) $vacancy->id;
        exec("nohup {$php} {$artisan} company:research {$id} --force > /dev/null 2>&1 &");

        return back()->with('status', __('Company research started (1-3 minutes). The page will refresh by itself.'));
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
