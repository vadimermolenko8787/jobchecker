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

            @php($bd = $vacancy->score_breakdown ?? [])
            @if (is_array($bd['criteria'] ?? null))
                @php($shares = app(\App\Services\VacancyScorer::class)->normalizeWeights($bd['weights'] ?? []))
                <div class="rubric">
                    @foreach (\App\Services\VacancyScorer::LABELS as $key => $label)
                        @php($c = $bd['criteria'][$key] ?? ['score' => 0, 'matched' => [], 'missing' => []])
                        @php($n = max(0, min(10, (int) ($c['score'] ?? 0))))
                        @php($color = $n >= 8 ? 'var(--ok)' : ($n >= 6 ? 'var(--signal)' : ($n >= 4 ? 'var(--info)' : 'var(--neutral)')))
                        <div class="rubric-row">
                            <span class="rl">{{ $label }}<small>вес {{ round($shares[$key] * 100) }}%</small></span>
                            <span class="rv" style="color:{{ $color }}">{{ $n }}/10</span>
                            <span class="track"><span class="fill" style="width:{{ $n * 10 }}%;background:{{ $color }}"></span></span>
                            @if (! empty($c['matched']) || ! empty($c['missing']))
                                <span class="rubric-ev">
                                    @foreach ($c['matched'] ?? [] as $item)
                                        <span class="-yes">{{ $item }}</span>
                                    @endforeach
                                    @foreach ($c['missing'] ?? [] as $item)
                                        <span>нет: {{ $item }}</span>
                                    @endforeach
                                </span>
                            @endif
                        </div>
                    @endforeach
                </div>
                @php($notes = [])
                @if (($bd['language_fit'] ?? null) === 'critical')
                    @php($notes[] = 'языковой барьер критичен: score ограничен 40')
                @elseif (($bd['language_fit'] ?? null) === 'warning')
                    @php($notes[] = 'языковой барьер: −15 к взвешенной сумме (' . ($bd['base_score'] ?? '?') . ')')
                @endif
                @if ($bd['rechecked'] ?? false)
                    @php($notes[] = 'перепроверено: ' . implode(' / ', $bd['run_scores'] ?? []) . ' → итог ' . $vacancy->score)
                @endif
                @if ($notes)
                    <div class="faint mono" style="font-size:12px;margin-top:12px">{{ implode(' · ', $notes) }}</div>
                @endif
            @endif

            @if ($vacancy->score_reason)
                <div style="margin-top:20px;padding:14px 16px;border-left:2px solid var(--signal-line);background:var(--signal-dim);border-radius:0 8px 8px 0;font-size:14px;line-height:1.6">
                    {{ $vacancy->score_reason }}
                </div>
            @endif

            @if ($vacancy->analysis['summary'] ?? null)
                <div style="margin-top:12px;padding:14px 16px;border-left:2px solid var(--line);background:var(--raised);border-radius:0 8px 8px 0;font-size:14px;line-height:1.6">
                    {{ $vacancy->analysis['summary'] }}
                </div>
            @endif

            @php($lang = $vacancy->analysis['language'] ?? null)
            @if ($lang)
                <div class="stack" style="margin-top:12px;flex-wrap:wrap">
                    @if ($lang['vacancy_language'] ?? null)
                        <span class="tag">Язык вакансии: {{ $lang['vacancy_language'] }}</span>
                    @endif
                    @if (! empty($lang['required_languages']))
                        <span class="tag">Требуются: {{ implode(', ', $lang['required_languages']) }}</span>
                    @endif
                </div>
                @if (in_array($lang['language_fit'] ?? null, ['warning', 'critical'], true) && ($lang['note'] ?? ''))
                    @php($critical = $lang['language_fit'] === 'critical')
                    <div style="margin-top:12px;padding:12px 16px;border-radius:8px;font-size:13.5px;line-height:1.55;border:1px solid {{ $critical ? 'var(--danger)' : 'var(--signal-line)' }};background:{{ $critical ? 'var(--danger-dim)' : 'var(--signal-dim)' }};color:{{ $critical ? 'var(--danger)' : 'var(--signal)' }}">
                        {{ $critical ? '‼️' : '⚠️' }} {{ $lang['note'] }}
                    </div>
                @endif
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
                        <textarea name="extra_instructions" rows="2" maxlength="2000" style="padding:6px 10px;font-size:12.5px"
                                  placeholder="Дополнительные инструкции (необязательно): на что сделать упор, что не упоминать…">{{ old('extra_instructions') }}</textarea>
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

    <div class="card">
        <div class="card-head">
            <h3>О компании</h3>
            @if ($company?->researched_at)
                <span class="hint">обновлено {{ $company->researched_at->format('d.m.Y H:i') }}</span>
            @endif
        </div>
        <div class="card-body">
            @if ($company?->last_error && ! $companyResearching)
                <div class="flash -err">Последнее исследование не удалось: {{ $company->last_error }}</div>
            @endif

            @if ($companyResearching)
                <div class="stack scanning" style="padding:16px;border-radius:8px;background:var(--raised)">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--signal)" stroke-width="2" style="animation:pulse 1.1s infinite"><circle cx="12" cy="12" r="9"/></svg>
                    <span>Идёт исследование компании (1-3 минуты)… страница обновится сама.</span>
                </div>
                @unless ($generating)
                    <script>setInterval(function(){location.reload();}, 8000);</script>
                @endunless
            @elseif ($company?->research)
                @php($r = $company->research)
                @if ($r['company_info'] ?? '')
                    <p style="margin-top:0;font-size:14px;line-height:1.6">{{ $r['company_info'] }}</p>
                @endif
                @if (($r['found'] ?? false) === false)
                    <div class="faint" style="font-size:13px;margin-bottom:14px">Достоверной информации о компании найти не удалось.</div>
                @endif

                @php($aspectMeta = [
                    'turnover' => ['label' => 'Текучка', 'values' => ['low' => ['низкая', '-ok'], 'medium' => ['средняя', '-neutral'], 'high' => ['высокая', '-failed']]],
                    'work_life_balance' => ['label' => 'Work-life balance', 'values' => ['good' => ['хороший', '-ok'], 'mixed' => ['смешанный', '-neutral'], 'poor' => ['плохой', '-failed']]],
                    'ceo' => ['label' => 'Отношение к руководству', 'values' => ['positive' => ['позитивное', '-ok'], 'mixed' => ['смешанное', '-neutral'], 'negative' => ['негативное', '-failed']]],
                    'compensation' => ['label' => 'Компенсация', 'values' => ['above_market' => ['выше рынка', '-ok'], 'market' => ['в рынке', '-neutral'], 'below_market' => ['ниже рынка', '-failed']]],
                ])
                <div style="display:grid;gap:10px;margin-bottom:16px">
                    @foreach ($aspectMeta as $key => $meta)
                        @php($aspect = $r['aspects'][$key] ?? [])
                        @php([$valueLabel, $badgeClass] = $meta['values'][$aspect['assessment'] ?? 'unknown'] ?? ['неизвестно', '-neutral'])
                        <div style="display:flex;gap:12px;align-items:baseline;flex-wrap:wrap">
                            <span style="font-weight:600;font-size:13.5px;min-width:190px">{{ $meta['label'] }}</span>
                            <span class="badge {{ $badgeClass }}">{{ $valueLabel }}@if ($key === 'ceo' && ($aspect['approval_percent'] ?? null) !== null) · {{ $aspect['approval_percent'] }}%@endif</span>
                            @if ($aspect['note'] ?? '')
                                <span class="muted" style="font-size:13px;flex:1;min-width:220px">{{ $aspect['note'] }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>

                @if (! empty($r['sources']))
                    <details class="box" style="margin-bottom:16px">
                        <summary>Источники ({{ count($r['sources']) }})</summary>
                        <div class="details-body">
                            @foreach ($r['sources'] as $source)
                                <div style="margin-bottom:14px">
                                    <div class="stack" style="flex-wrap:wrap">
                                        <span style="font-weight:600;font-size:13.5px">{{ $source['name'] ?? '' }}</span>
                                        @if (($source['rating'] ?? null) !== null)<span class="mono" style="color:var(--signal);font-size:13px">{{ $source['rating'] }}/5</span>@endif
                                        @if (($source['reviews_count'] ?? null) !== null)<span class="faint" style="font-size:12.5px">{{ $source['reviews_count'] }} отзывов</span>@endif
                                        @if ($source['url'] ?? null)<a href="{{ $source['url'] }}" target="_blank" rel="noopener" style="font-size:12.5px">открыть ↗</a>@endif
                                    </div>
                                    @if (! empty($source['key_points']))
                                        <ul style="margin:6px 0 0;padding-left:18px;font-size:13px;line-height:1.6;color:var(--muted)">
                                            @foreach ($source['key_points'] as $point)
                                                <li>{{ $point }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endif

                @if ($r['verdict'] ?? '')
                    <div style="padding:14px 16px;border-left:2px solid var(--signal-line);background:var(--signal-dim);border-radius:0 8px 8px 0;font-size:14px;line-height:1.6;margin-bottom:16px">
                        {{ $r['verdict'] }}
                    </div>
                @endif

                <form method="post" action="{{ route('vacancies.research-company', $vacancy) }}">
                    @csrf
                    <button type="submit" class="btn btn-sm">Обновить исследование</button>
                </form>
            @else
                <form method="post" action="{{ route('vacancies.research-company', $vacancy) }}">
                    @csrf
                    <div class="stack">
                        <button type="submit" class="btn btn-sm btn-primary" @unless($vacancy->company) disabled @endunless>Исследовать компанию</button>
                        <span class="faint" style="font-size:12px;max-width:340px">
                            @if ($vacancy->company)
                                Поиск отзывов сотрудников на Glassdoor, Indeed, DOU и др. Займёт 1-3 минуты.
                            @else
                                У вакансии не указана компания.
                            @endif
                        </span>
                    </div>
                </form>
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
