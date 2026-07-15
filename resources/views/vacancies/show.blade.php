@extends('layouts.app')

@section('title', $vacancy->title)

@section('content')
    <div style="margin-bottom:18px">
        <a href="{{ route('vacancies.index') }}" class="muted" style="font-size:13px">← к списку вакансий</a>
    </div>

    <div class="card">
        <div class="card-body">
            <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;justify-content:space-between">
                <div style="min-width:0;flex:1">
                    <div class="stack" style="margin-bottom:10px">
                        <span class="badge -{{ $vacancy->status }}">{{ $vacancy->status }}</span>
                        @if ($vacancy->applied_at)
                            <span class="badge -solid">✓ подано {{ $vacancy->applied_at->format('d.m.Y') }}</span>
                        @endif
                    </div>
                    <h1 style="font-size:24px;line-height:1.25">{{ $vacancy->title }}</h1>
                    <div class="stack" style="margin-top:12px;color:var(--muted);font-size:14px">
                        <span>{{ $vacancy->company ?? '—' }}</span>
                        <span class="faint">·</span>
                        <span>{{ $vacancy->location ?? '—' }}</span>
                        <span class="faint">·</span>
                        <span class="tag">{{ $vacancy->source }}</span>
                        @if ($vacancy->salary)<span class="faint">·</span><span class="mono" style="color:var(--ok)">{{ $vacancy->salary }}</span>@endif
                    </div>
                    <div style="margin-top:16px">
                        <a href="{{ $vacancy->url }}" target="_blank" rel="noopener" class="btn btn-sm">
                            Открыть на сайте
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7M7 7h10v10"/></svg>
                        </a>
                    </div>
                </div>
                @if ($vacancy->score !== null)
                    <div style="text-align:center">
                        <div class="eyebrow" style="margin-bottom:8px">Совпадение</div>
                        @include('partials.meter', ['score' => $vacancy->score, 'lg' => true])
                    </div>
                @endif
            </div>

            @if ($vacancy->score_reason)
                <div style="margin-top:20px;padding:14px 16px;border-left:2px solid var(--signal-line);background:var(--signal-dim);border-radius:0 8px 8px 0;font-size:14px;line-height:1.6">
                    {{ $vacancy->score_reason }}
                </div>
            @endif
        </div>
    </div>

    @if ($generationError)
        <div class="flash -err" style="margin-top:20px">Последняя генерация не удалась: {{ $generationError }}</div>
    @endif

    <div class="card">
        <div class="card-head"><h3>Документы</h3></div>
        <div class="card-body">
            @if ($generating)
                <div class="stack scanning" style="padding:16px;border-radius:8px;background:var(--raised)">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--signal)" stroke-width="2" style="animation:pulse 1.1s infinite"><circle cx="12" cy="12" r="9"/></svg>
                    <span>Идёт генерация ({{ $generating === 'both' ? 'резюме и cover letter' : ($generating === 'resume' ? 'резюме' : 'cover letter') }})… страница обновится сама.</span>
                </div>
                <script>setInterval(function(){location.reload();}, 8000);</script>
            @else
                <div class="stack">
                    <form method="post" action="{{ route('vacancies.generate', [$vacancy, 'resume']) }}">
                        @csrf
                        <button type="submit" class="btn btn-sm" @unless($hasResume) disabled @endunless>
                            {{ $vacancy->resume_path ? 'Перегенерировать резюме' : 'Сгенерировать резюме' }}
                        </button>
                    </form>
                    <form method="post" action="{{ route('vacancies.generate', [$vacancy, 'cover_letter']) }}" class="stack" style="gap:8px">
                        @csrf
                        <button type="submit" class="btn btn-sm" @unless($hasResume) disabled @endunless>
                            {{ $vacancy->cover_letter_path ? 'Перегенерировать cover letter' : 'Сгенерировать cover letter' }}
                        </button>
                        <select name="lang" style="width:auto;padding:6px 10px;font-size:12.5px">
                            @foreach (\App\Services\DocumentGenerator::LANGUAGE_LABELS as $code => $label)
                                <option value="{{ $code }}" @selected(\App\Models\Setting::get('cover_letter_language') === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </form>
                    <form method="post" action="{{ route('vacancies.applied', $vacancy) }}">
                        @csrf
                        <button type="submit" class="btn btn-sm {{ $vacancy->applied_at ? '' : 'btn-primary' }}">
                            {{ $vacancy->applied_at ? 'Снять отметку о подаче' : 'Отметить: подал' }}
                        </button>
                    </form>
                </div>
                @unless($hasResume)<div class="faint" style="font-size:12.5px;margin-top:10px">Загрузите базовое резюме на панели, чтобы генерировать документы.</div>@endunless
            @endif
        </div>
    </div>

    <details class="box" open style="margin-top:20px">
        <summary>Описание вакансии</summary>
        <div class="details-body">
            <div class="markdown-box">{!! Str::of((string) $vacancy->description)->stripTags('<p><br><ul><ol><li><b><strong><i><em><h1><h2><h3><h4><a>') !!}</div>
        </div>
    </details>

    @if ($resumeMd)
        <details class="box" open>
            <summary>Адаптированное резюме</summary>
            <div class="details-body">
                <div class="stack" style="margin-bottom:12px">
                    <a href="{{ route('vacancies.download', [$vacancy, 'resume', 'pdf']) }}" class="btn btn-sm">Скачать .pdf</a>
                    <a href="{{ route('vacancies.download', [$vacancy, 'resume']) }}" class="btn btn-sm btn-ghost">Исходник ({{ $resumeIsHtml ? '.html' : '.md' }})</a>
                </div>
                @if ($resumeIsHtml)
                    <iframe src="{{ route('vacancies.preview-resume', $vacancy) }}" style="width:100%;height:700px;border:1px solid var(--line);border-radius:8px;background:#fff"></iframe>
                @else
                    <div class="markdown-box">{!! Str::markdown($resumeMd, ['html_input' => 'strip']) !!}</div>
                @endif
            </div>
        </details>
    @endif

    @if ($coverMd)
        <details class="box" open>
            <summary>Cover letter</summary>
            <div class="details-body">
                <div class="stack" style="margin-bottom:12px">
                    <a href="{{ route('vacancies.download', [$vacancy, 'cover_letter', 'pdf']) }}" class="btn btn-sm">Скачать .pdf</a>
                    <a href="{{ route('vacancies.download', [$vacancy, 'cover_letter']) }}" class="btn btn-sm btn-ghost">Скачать .md</a>
                </div>
                <div class="markdown-box">{!! Str::markdown($coverMd, ['html_input' => 'strip']) !!}</div>
            </div>
        </details>
    @endif
@endsection
