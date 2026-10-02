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
use Illuminate\Support\Facades\File;
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
        $docs = [];
        foreach (['resume', 'cover_letter'] as $doc) {
            $full = $this->documentFile($vacancy, $doc);
            $docs[$doc] = $full
                ? $this->editorParts($this->documentHtml($vacancy, $doc, $full)) + ['ext' => pathinfo($full, PATHINFO_EXTENSION)]
                : null;
        }
        $company = Company::forName($vacancy->company);

        return view('vacancies.show', [
            'vacancy' => $vacancy,
            'docs' => $docs,
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

    public function download(Vacancy $vacancy, string $doc, string $format = 'md')
    {
        $full = $this->documentFile($vacancy, $doc);
        abort_unless($full, 404);

        if ($format !== 'pdf') {
            $ext = pathinfo($full, PATHINFO_EXTENSION);

            return response()->download($full, "vacancy-{$vacancy->id}-{$doc}.{$ext}");
        }

        return $this->renderPdf($vacancy, $doc, $full)->download("vacancy-{$vacancy->id}-{$doc}.pdf");
    }

    /** The same PDF as the download, opened by the browser's own viewer. */
    public function pdf(Vacancy $vacancy, string $doc)
    {
        $full = $this->documentFile($vacancy, $doc);
        abort_unless($full, 404);

        return $this->renderPdf($vacancy, $doc, $full)->stream("vacancy-{$vacancy->id}-{$doc}.pdf");
    }

    public function updateDocument(Request $request, Vacancy $vacancy, string $doc)
    {
        $data = $request->validate(['html' => ['required', 'string', 'max:200000']]);
        $full = $this->documentFile($vacancy, $doc);
        abort_unless($full, 404);

        // The edited body goes back into the stored page, so its <head> and <style> survive.
        // Markdown has no page yet and gets the template its PDF was rendered with. A page
        // without a <body> element keeps its styles, which the editor already showed.
        $body = $this->sanitizeHtml($data['html']);
        $document = $this->documentHtml($vacancy, $doc, $full);
        $style = $this->editorParts($document)['style'];
        $html = $this->replaceBody($document, $body) ?? ($style !== ''
            ? "<!doctype html>\n<html><head><meta charset=\"utf-8\"><style>{$style}</style></head><body>{$body}</body></html>\n"
            : $this->wrapDocument($vacancy, $doc, $body));

        $column = $doc === 'resume' ? 'resume_path' : 'cover_letter_path';
        $name = $doc === 'resume' ? 'resume.html' : 'cover_letter.html';
        $target = dirname($full) . "/{$name}";
        File::put($target, $html);
        if ($target !== $full) {
            File::delete($full);
        }
        $vacancy->update([$column => dirname($vacancy->{$column}) . "/{$name}"]);

        return redirect(route('vacancies.show', $vacancy) . ($doc === 'resume' ? '#cv' : '#cover'))
            ->with('status', __('Document saved.'));
    }

    /** Absolute path of a generated document, or null when there is none on disk. */
    private function documentFile(Vacancy $vacancy, string $doc): ?string
    {
        $path = $doc === 'resume' ? $vacancy->resume_path : $vacancy->cover_letter_path;
        $full = $path ? storage_path("app/private/{$path}") : null;

        return $full && is_file($full) ? $full : null;
    }

    /** The document as a full HTML page: stored HTML as is, Markdown inside the PDF template. */
    private function documentHtml(Vacancy $vacancy, string $doc, string $full): string
    {
        $content = file_get_contents($full);

        return str_ends_with($full, '.html')
            ? $content
            : $this->wrapDocument($vacancy, $doc, Str::markdown($content, ['html_input' => 'strip']));
    }

    private function wrapDocument(Vacancy $vacancy, string $doc, string $body): string
    {
        return view('pdf.document', [
            'title' => ($doc === 'resume' ? 'Resume — ' : 'Cover letter — ') . $vacancy->title,
            'html' => $body,
        ])->render();
    }

    private function renderPdf(Vacancy $vacancy, string $doc, string $full): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadHTML($this->documentHtml($vacancy, $doc, $full))->setPaper('a4');
    }

    /**
     * What the editor edits (the body) and the styles it shows it with, so the
     * document looks in the editor the way it looks in the PDF.
     *
     * @return array{body: string, style: string}
     */
    private function editorParts(string $html): array
    {
        preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $styles);
        $body = preg_match('/<body\b[^>]*>(.*)<\/body>/is', $html, $m)
            ? $m[1]
            : preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);

        return ['body' => trim($body), 'style' => implode("\n", $styles[1])];
    }

    /** $document with $body inside its <body>, or null when it has no body element. */
    private function replaceBody(string $document, string $body): ?string
    {
        $result = preg_replace_callback(
            '/(<body\b[^>]*>).*(<\/body>)/is',
            fn (array $m) => $m[1] . $body . $m[2],
            $document,
            1,
            $count,
        );

        return $count === 1 ? $result : null;
    }

    /**
     * Drops what could run code from editor HTML. There is one user, but the HTML is
     * rendered by dompdf and opened in the browser from this origin.
     */
    private function sanitizeHtml(string $html): string
    {
        $dom = new \DOMDocument;
        // The XML declaration makes libxml read the fragment as UTF-8 instead of Latin-1. A real
        // <body> rather than a wrapper element, so a stray closing tag cannot end it early.
        $dom->loadHTML(
            '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>',
            LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        $xpath = new \DOMXPath($dom);
        foreach (iterator_to_array($xpath->query('//script|//iframe|//object|//embed')) as $node) {
            $node->parentNode->removeChild($node);
        }
        foreach (iterator_to_array($xpath->query('//@*')) as $attribute) {
            $name = strtolower($attribute->nodeName);
            $scriptUrl = in_array($name, ['href', 'src'], true) && preg_match('/^\s*javascript:/i', $attribute->nodeValue);
            if (str_starts_with($name, 'on') || $scriptUrl) {
                $attribute->ownerElement->removeAttribute($attribute->nodeName);
            }
        }

        $clean = '';
        foreach ($dom->getElementsByTagName('body')->item(0)->childNodes as $child) {
            $clean .= $dom->saveHTML($child);
        }

        return $clean;
    }
}
