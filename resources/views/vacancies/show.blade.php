@extends('layouts.app')

@section('title', $vacancy->title)

@section('content')
    <div style="margin-bottom:18px">
        <a href="{{ route('vacancies.index') }}" class="muted" style="font-size:13px">← {{ __('back to vacancies') }}</a>
    </div>

    <div class="card">
        <div class="card-body">
            <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;justify-content:space-between">
                <div style="min-width:0;flex:1">
                    <div class="stack" style="margin-bottom:10px">
                        <span class="badge -{{ $vacancy->status }}">{{ $vacancy->status }}</span>
                        @if ($vacancy->bumped_at)
                            <span class="badge -bumped" title="{{ __('The source republished this vacancy, it was scored again') }}">↑ {{ __('bumped :time', ['time' => $vacancy->bumped_at->format('d.m.Y H:i')]) }}</span>
                        @endif
                        @if ($vacancy->applied_at)
                            <span class="badge -solid">✓ {{ __('applied :date', ['date' => $vacancy->applied_at->format('d.m.Y')]) }}</span>
                        @endif
                        @if ($vacancy->muted_at)
                            <span class="badge -neutral" title="{{ __('This vacancy is not sent to Telegram, nor are its copies from other sources') }}">🔕 {{ __('muted in Telegram') }}</span>
                        @endif
                    </div>
                    <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap">
                        <h1 style="font-size:24px;line-height:1.25;flex:1;min-width:240px">{{ $vacancy->title }}</h1>
                        <div class="stack" style="flex:none">
                            <form method="post" action="{{ route('vacancies.muted', $vacancy) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm {{ $vacancy->muted_at ? 'btn-ghost' : '' }}"
                                        title="{{ __('The vacancy and its copies from other sources will not be sent to Telegram') }}">
                                    {{ $vacancy->muted_at ? '🔔 ' . __('Send to Telegram') : '🔕 ' . __('Do not send to Telegram') }}
                                </button>
                            </form>
                            <form method="post" action="{{ route('vacancies.applied', $vacancy) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm {{ $vacancy->applied_at ? 'btn-ghost' : 'btn-ok' }}">
                                    {{ $vacancy->applied_at ? __('Clear the applied mark') : '✓ ' . __('Mark as applied') }}
                                </button>
                            </form>
                        </div>
                    </div>
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
                            {{ __('Open on the site') }}
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7M7 7h10v10"/></svg>
                        </a>
                    </div>
                </div>
                @if ($vacancy->score !== null)
                    <div style="text-align:center">
                        <div class="eyebrow" style="margin-bottom:8px">{{ __('Match') }}</div>
                        @include('partials.meter', ['score' => $vacancy->score, 'lg' => true])
                    </div>
                @endif
            </div>

            @php($bd = $vacancy->score_breakdown ?? [])
            @if (is_array($bd['criteria'] ?? null))
                @php($shares = app(\App\Services\VacancyScorer::class)->normalizeWeights($bd['weights'] ?? []))
                <div class="rubric">
                    @foreach (\App\Services\VacancyScorer::labels() as $key => $label)
                        @php($c = $bd['criteria'][$key] ?? ['score' => 0, 'matched' => [], 'missing' => []])
                        @php($n = max(0, min(10, (int) ($c['score'] ?? 0))))
                        @php($color = $n >= 8 ? 'var(--ok)' : ($n >= 6 ? 'var(--signal)' : ($n >= 4 ? 'var(--info)' : 'var(--neutral)')))
                        <div class="rubric-row">
                            <span class="rl">{{ $label }}<small>{{ __('weight :percent%', ['percent' => round($shares[$key] * 100)]) }}</small></span>
                            <span class="rv" style="color:{{ $color }}">{{ $n }}/10</span>
                            <span class="track"><span class="fill" style="width:{{ $n * 10 }}%;background:{{ $color }}"></span></span>
                            @if (! empty($c['matched']) || ! empty($c['missing']))
                                <span class="rubric-ev">
                                    @foreach ($c['matched'] ?? [] as $item)
                                        <span class="-yes">{{ $item }}</span>
                                    @endforeach
                                    @foreach ($c['missing'] ?? [] as $item)
                                        <span>{{ __('missing: :item', ['item' => $item]) }}</span>
                                    @endforeach
                                </span>
                            @endif
                        </div>
                    @endforeach
                </div>
                @php($notes = [])
                @if (($bd['language_fit'] ?? null) === 'critical')
                    @php($notes[] = __('critical language barrier: score capped at 40'))
                @elseif (($bd['language_fit'] ?? null) === 'warning')
                    @php($notes[] = __('language barrier: −15 from the weighted sum (:base)', ['base' => $bd['base_score'] ?? '?']))
                @endif
                @if ($bd['foreign_language'] ?? null)
                    @php($notes[] = __('rejected: the vacancy language :language is not among the known languages', ['language' => $bd['foreign_language']]))
                @endif
                @if ($bd['rechecked'] ?? false)
                    @php($notes[] = __('rechecked: :scores → final :score', ['scores' => implode(' / ', $bd['run_scores'] ?? []), 'score' => $vacancy->score]))
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
                        <span class="tag">{{ __('Vacancy language: :language', ['language' => $lang['vacancy_language']]) }}</span>
                    @endif
                    @if (! empty($lang['required_languages']))
                        <span class="tag">{{ __('Required: :languages', ['languages' => implode(', ', $lang['required_languages'])]) }}</span>
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
        <div class="flash -err" style="margin-top:20px">{{ __('The last generation failed: :error', ['error' => $generationError]) }}</div>
    @endif

    <div class="card">
        <div class="card-head"><h3>{{ __('Documents') }}</h3></div>
        <div class="card-body">
            @if ($generating)
                <div class="stack scanning" style="padding:16px;border-radius:8px;background:var(--raised)">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--signal)" stroke-width="2" style="animation:pulse 1.1s infinite"><circle cx="12" cy="12" r="9"/></svg>
                    <span>{{ __('Generating :what… the page will refresh by itself.', ['what' => $generating === 'both' ? __('resume and cover letter') : ($generating === 'resume' ? __('resume') : 'cover letter')]) }}</span>
                </div>
                <script>setInterval(function(){location.reload();}, 8000);</script>
            @else
                {{-- One column: each generator is its own row, and the language belongs to the
                     cover letter, so it sits on that button's line. --}}
                <div style="display:flex;flex-direction:column;gap:12px;align-items:flex-start;max-width:560px">
                    <form method="post" action="{{ route('vacancies.generate', [$vacancy, 'resume']) }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-info" @unless($hasResume) disabled @endunless>
                            {{ $vacancy->resume_path ? __('Regenerate resume') : __('Generate resume') }}
                        </button>
                    </form>
                    <form method="post" action="{{ route('vacancies.generate', [$vacancy, 'cover_letter']) }}"
                          style="display:flex;flex-direction:column;gap:8px;align-self:stretch">
                        @csrf
                        <div class="stack" style="gap:8px">
                            <button type="submit" class="btn btn-sm btn-primary" @unless($hasResume) disabled @endunless>
                                {{ $vacancy->cover_letter_path ? __('Regenerate cover letter') : __('Generate cover letter') }}
                            </button>
                            <select name="lang" style="width:auto;padding:6px 10px;font-size:12.5px">
                                @foreach (\App\Services\DocumentGenerator::languageLabels() as $code => $label)
                                    <option value="{{ $code }}" @selected(\App\Models\Setting::get('cover_letter_language') === $code)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <textarea name="extra_instructions" rows="2" maxlength="2000" style="padding:6px 10px;font-size:12.5px"
                                  placeholder="{{ __('Extra instructions (optional): what to emphasize, what to leave out…') }}">{{ old('extra_instructions') }}</textarea>
                    </form>
                </div>
                @unless($hasResume)<div class="faint" style="font-size:12.5px;margin-top:10px">{{ __('Upload a base resume on the dashboard to generate documents.') }}</div>@endunless
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <h3>{{ __('About the company') }}</h3>
            @if ($company?->researched_at)
                <span class="hint">{{ __('updated :time', ['time' => $company->researched_at->format('d.m.Y H:i')]) }}</span>
            @endif
        </div>
        <div class="card-body">
            @if ($company?->last_error && ! $companyResearching)
                <div class="flash -err">{{ __('The last research failed: :error', ['error' => $company->last_error]) }}</div>
            @endif

            @if ($companyResearching)
                <div class="stack scanning" style="padding:16px;border-radius:8px;background:var(--raised)">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--signal)" stroke-width="2" style="animation:pulse 1.1s infinite"><circle cx="12" cy="12" r="9"/></svg>
                    <span>{{ __('Researching the company (1-3 minutes)… the page will refresh by itself.') }}</span>
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
                    <div class="faint" style="font-size:13px;margin-bottom:14px">{{ __('No reliable information about the company was found.') }}</div>
                @endif

                @php($aspectMeta = [
                    'turnover' => ['label' => __('Turnover'), 'values' => ['low' => [__('low'), '-ok'], 'medium' => [__('medium'), '-neutral'], 'high' => [__('high'), '-failed']]],
                    'work_life_balance' => ['label' => 'Work-life balance', 'values' => ['good' => [__('good'), '-ok'], 'mixed' => [__('mixed'), '-neutral'], 'poor' => [__('poor'), '-failed']]],
                    'ceo' => ['label' => __('Attitude to management'), 'values' => ['positive' => [__('positive'), '-ok'], 'mixed' => [__('ambivalent'), '-neutral'], 'negative' => [__('negative'), '-failed']]],
                    'compensation' => ['label' => __('Compensation'), 'values' => ['above_market' => [__('above market'), '-ok'], 'market' => [__('at market'), '-neutral'], 'below_market' => [__('below market'), '-failed']]],
                ])
                <div style="display:grid;gap:10px;margin-bottom:16px">
                    @foreach ($aspectMeta as $key => $meta)
                        @php($aspect = $r['aspects'][$key] ?? [])
                        @php([$valueLabel, $badgeClass] = $meta['values'][$aspect['assessment'] ?? 'unknown'] ?? [__('unknown'), '-neutral'])
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
                        <summary>{{ __('Sources (:count)', ['count' => count($r['sources'])]) }}</summary>
                        <div class="details-body">
                            @foreach ($r['sources'] as $source)
                                <div style="margin-bottom:14px">
                                    <div class="stack" style="flex-wrap:wrap">
                                        <span style="font-weight:600;font-size:13.5px">{{ $source['name'] ?? '' }}</span>
                                        @if (($source['rating'] ?? null) !== null)<span class="mono" style="color:var(--signal);font-size:13px">{{ $source['rating'] }}/5</span>@endif
                                        @if (($source['reviews_count'] ?? null) !== null)<span class="faint" style="font-size:12.5px">{{ trans_choice(':count review|:count reviews', $source['reviews_count']) }}</span>@endif
                                        @if ($source['url'] ?? null)<a href="{{ $source['url'] }}" target="_blank" rel="noopener" style="font-size:12.5px">{{ __('open') }} ↗</a>@endif
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
                    <button type="submit" class="btn btn-sm">{{ __('Refresh research') }}</button>
                </form>
            @else
                <form method="post" action="{{ route('vacancies.research-company', $vacancy) }}">
                    @csrf
                    <div class="stack">
                        <button type="submit" class="btn btn-sm btn-primary" @unless($vacancy->company) disabled @endunless>{{ __('Research the company') }}</button>
                        <span class="faint" style="font-size:12px;max-width:340px">
                            @if ($vacancy->company)
                                {{ __('Looks for employee reviews on Glassdoor, Indeed, DOU and others. Takes 1-3 minutes.') }}
                            @else
                                {{ __('The vacancy has no company.') }}
                            @endif
                        </span>
                    </div>
                </form>
            @endif
        </div>
    </div>

    <details class="box" open style="margin-top:20px">
        <summary>{{ __('Vacancy description') }}</summary>
        <div class="details-body">
            <div class="markdown-box">{!! Str::of((string) $vacancy->description)->stripTags('<p><br><ul><ol><li><b><strong><i><em><h1><h2><h3><h4><a>') !!}</div>
        </div>
    </details>

    @if ($resumeMd)
        <details class="box" open>
            <summary>{{ __('Tailored resume') }}</summary>
            <div class="details-body">
                <div class="stack" style="margin-bottom:12px">
                    <a href="{{ route('vacancies.download', [$vacancy, 'resume', 'pdf']) }}" class="btn btn-sm">{{ __('Download :ext', ['ext' => '.pdf']) }}</a>
                    <a href="{{ route('vacancies.download', [$vacancy, 'resume']) }}" class="btn btn-sm btn-ghost">{{ __('Source (:ext)', ['ext' => $resumeIsHtml ? '.html' : '.md']) }}</a>
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
                    <a href="{{ route('vacancies.download', [$vacancy, 'cover_letter', 'pdf']) }}" class="btn btn-sm">{{ __('Download :ext', ['ext' => '.pdf']) }}</a>
                    <a href="{{ route('vacancies.download', [$vacancy, 'cover_letter']) }}" class="btn btn-sm btn-ghost">{{ __('Download :ext', ['ext' => '.md']) }}</a>
                </div>
                <div class="markdown-box">{!! Str::markdown($coverMd, ['html_input' => 'strip']) !!}</div>
            </div>
        </details>
    @endif
@endsection
