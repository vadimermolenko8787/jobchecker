@extends('layouts.app')

@section('title', $vacancy->title)
@section('page-class', '-wide -wider')

@section('content')
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:16px">
        <a href="{{ route('vacancies.index') }}" class="muted" style="font-size:13px">← {{ __('back to vacancies') }}</a>
        <span class="mono faint" style="font-size:12px">#{{ $vacancy->id }}</span>
    </div>

    @if ($generationError)
        <div class="flash -err">{{ __('The last generation failed: :error', ['error' => $generationError]) }}</div>
    @endif

    <div class="vacancy-grid">
        <div class="vacancy-main">
            <section class="card">
                <div style="padding:22px 22px 18px;display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap">
                    <div style="min-width:0;flex:1">
                        <div class="stack" style="gap:8px;margin-bottom:10px">
                            <span class="badge -{{ $vacancy->status }}">{{ $vacancy->status }}</span>
                            <span class="tag">{{ $vacancy->source }}</span>
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
                        <h1 style="font-size:25px;line-height:1.25">{{ $vacancy->title }}</h1>
                        <div class="stack" style="gap:8px;margin-top:10px;color:var(--muted);font-size:14px">
                            <span style="color:var(--text);font-weight:600">{{ $vacancy->company ?? '—' }}</span>
                            <span class="faint">·</span>
                            <span>{{ $vacancy->location ?? '—' }}</span>
                            @if ($vacancy->salary)<span class="faint">·</span><span class="mono" style="color:var(--ok);font-size:13.5px">{{ $vacancy->salary }}</span>@endif
                        </div>
                    </div>
                    @if ($vacancy->score !== null)
                        @php($s = (int) $vacancy->score)
                        @php($scoreColor = $s >= 80 ? 'var(--ok)' : ($s >= 60 ? 'var(--signal)' : ($s >= 40 ? 'var(--info)' : 'var(--neutral)')))
                        <div style="flex:none;text-align:center;padding:10px 16px;border:1px solid var(--line-soft);border-radius:10px;background:var(--raised)">
                            <div class="eyebrow" style="margin-bottom:4px">{{ __('Match') }}</div>
                            <div class="mono" style="font-size:34px;font-weight:700;line-height:1;color:{{ $scoreColor }}">{{ $s }}</div>
                            <span style="display:block;width:110px;height:6px;margin-top:10px;border-radius:999px;background:var(--raised-2);overflow:hidden">
                                <span style="display:block;height:100%;width:{{ max(4, min(100, $s)) }}%;background:{{ $scoreColor }}"></span>
                            </span>
                        </div>
                    @endif
                </div>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:14px 22px;border-top:1px solid var(--line-soft)">
                    <a href="{{ $vacancy->url }}" target="_blank" rel="noopener" class="btn btn-primary" style="min-height:46px;padding:12px 20px;font-size:15px">
                        {{ __('Open on the site') }}
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7M7 7h10v10"/></svg>
                    </a>
                    <form method="post" action="{{ route('vacancies.applied', $vacancy) }}">
                        @csrf
                        <button type="submit" class="btn {{ $vacancy->applied_at ? 'btn-ghost' : 'btn-ok-line' }}">
                            {{ $vacancy->applied_at ? __('Clear the applied mark') : '✓ ' . __('Mark as applied') }}
                        </button>
                    </form>
                    <form method="post" action="{{ route('vacancies.muted', $vacancy) }}" style="margin-left:auto">
                        @csrf
                        <button type="submit" class="btn btn-ghost" title="{{ __('The vacancy and its copies from other sources will not be sent to Telegram') }}">
                            {{ $vacancy->muted_at ? '🔔 ' . __('Send to Telegram') : '🔕 ' . __('Do not send to Telegram') }}
                        </button>
                    </form>
                </div>
            </section>

            @php($bd = $vacancy->score_breakdown ?? [])
            @php($lang = $vacancy->analysis['language'] ?? null)
            @if ($vacancy->score_reason || is_array($bd['criteria'] ?? null) || ($vacancy->analysis['summary'] ?? null) || $lang)
                <section class="card">
                    <div class="card-head">
                        <h3>{{ __('Scoring') }}</h3>
                        @if ($bd['rechecked'] ?? false)
                            <span class="hint">{{ __('rechecked: :scores → final :score', ['scores' => implode(' / ', $bd['run_scores'] ?? []), 'score' => $vacancy->score]) }}</span>
                        @endif
                    </div>
                    <div class="card-body" style="display:flex;flex-direction:column;gap:18px">
                        @if ($vacancy->score_reason)
                            <div style="padding:14px 16px;border-radius:10px;background:var(--signal-dim);border:1px solid var(--signal-line);font-size:14px;line-height:1.6">
                                {{ $vacancy->score_reason }}
                            </div>
                        @endif

                        @if (is_array($bd['criteria'] ?? null))
                            @php($shares = app(\App\Services\VacancyScorer::class)->normalizeWeights($bd['weights'] ?? []))
                            <div class="rubric" style="margin-top:0">
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
                            @if ($notes)
                                <div class="faint mono" style="font-size:12px">{{ implode(' · ', $notes) }}</div>
                            @endif
                        @endif

                        @if ($vacancy->analysis['summary'] ?? null)
                            <div style="padding:14px 16px;border-radius:10px;background:var(--raised);border:1px solid var(--line-soft);font-size:14px;line-height:1.6;color:var(--muted)">
                                {{ $vacancy->analysis['summary'] }}
                            </div>
                        @endif

                        @if ($lang)
                            <div class="stack" style="flex-wrap:wrap">
                                @if ($lang['vacancy_language'] ?? null)
                                    <span class="tag">{{ __('Vacancy language: :language', ['language' => $lang['vacancy_language']]) }}</span>
                                @endif
                                @if (! empty($lang['required_languages']))
                                    <span class="tag">{{ __('Required: :languages', ['languages' => implode(', ', $lang['required_languages'])]) }}</span>
                                @endif
                            </div>
                            @if (in_array($lang['language_fit'] ?? null, ['warning', 'critical'], true) && ($lang['note'] ?? ''))
                                @php($critical = $lang['language_fit'] === 'critical')
                                <div style="padding:12px 16px;border-radius:8px;font-size:13.5px;line-height:1.55;border:1px solid {{ $critical ? 'var(--danger)' : 'var(--warn)' }};background:{{ $critical ? 'var(--danger-dim)' : 'var(--warn-dim)' }};color:{{ $critical ? 'var(--danger)' : 'var(--warn)' }}">
                                    {{ $critical ? '‼️' : '⚠️' }} {{ $lang['note'] }}
                                </div>
                            @endif
                        @endif
                    </div>
                </section>
            @endif

            <section class="card">
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
                            <div style="padding:14px 16px;border-radius:10px;background:var(--signal-dim);border:1px solid var(--signal-line);font-size:14px;line-height:1.6;margin-bottom:16px">
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
                                <button type="submit" class="btn btn-sm" @unless($vacancy->company) disabled @endunless>{{ __('Research the company') }}</button>
                                <span class="faint" style="font-size:12px;max-width:380px">
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
            </section>

            <section class="card">
                <div class="card-head"><h3>{{ __('Vacancy description') }}</h3></div>
                <div class="card-body">
                    <div class="markdown-box" style="background:none;border:0;padding:0">{!! Str::of((string) $vacancy->description)->stripTags('<p><br><ul><ol><li><b><strong><i><em><h1><h2><h3><h4><a>') !!}</div>
                </div>
            </section>
        </div>

        <aside class="card doc-panel" aria-label="{{ __('Application documents') }}">
            @include('partials.doc-pane', ['doc' => 'resume'])
            @include('partials.doc-pane', ['doc' => 'cover_letter'])
        </aside>
    </div>

    @if ($generating)
        <script>setInterval(function(){location.reload();}, 8000);</script>
    @endif
@endsection

@section('scripts')
<script>
    (function () {
        var panes = document.querySelectorAll('[data-pane]');
        var JODIT_JS = 'https://cdn.jsdelivr.net/npm/jodit@4.17.1/es2021/jodit.fat.min.js';
        var JODIT_CSS = 'https://cdn.jsdelivr.net/npm/jodit@4.17.1/es2021/jodit.fat.min.css';
        var joditReady = null;

        // The active tab lives in the hash, so a reload after generation reopens it.
        function showTab(hash) {
            panes.forEach(function (pane) { pane.hidden = pane.getAttribute('data-pane') !== hash; });
            document.querySelectorAll('[data-tab]').forEach(function (tab) {
                var on = tab.getAttribute('data-tab') === hash;
                tab.classList.toggle('is-active', on);
                tab.setAttribute('aria-selected', on ? 'true' : 'false');
            });
        }
        showTab(location.hash === '#cover' ? 'cover' : 'cv');
        document.querySelectorAll('[data-tab]').forEach(function (tab) {
            tab.addEventListener('click', function () {
                var hash = tab.getAttribute('data-tab');
                history.replaceState(null, '', '#' + hash);
                showTab(hash);
            });
        });

        // Jodit is loaded only when the editor is first opened.
        function loadJodit() {
            if (!joditReady) {
                joditReady = new Promise(function (resolve, reject) {
                    var css = document.createElement('link');
                    css.rel = 'stylesheet';
                    css.href = JODIT_CSS;
                    document.head.appendChild(css);
                    var script = document.createElement('script');
                    script.src = JODIT_JS;
                    script.onload = function () { resolve(window.Jodit); };
                    script.onerror = function () { joditReady = null; reject(new Error('jodit')); };
                    document.head.appendChild(script);
                });
            }
            return joditReady;
        }

        function setMode(pane, mode) {
            pane.querySelectorAll('.vbtn').forEach(function (b) { b.classList.toggle('is-active', b.getAttribute('data-mode') === mode); });
            pane.querySelectorAll('[data-view]').forEach(function (v) { v.hidden = v.getAttribute('data-view') !== mode; });
        }

        function openEditor(form) {
            if (form.__editor) return Promise.resolve(form.__editor);
            return loadJodit().then(function (Jodit) {
                var area = form.querySelector('.doc-editor-area');
                // The document's own styles go into the editor iframe, so it looks like the PDF page.
                var editor = Jodit.make(form.querySelector('[data-editor-target]'), {
                    iframe: true,
                    iframeStyle: 'html{background:#fff}body{margin:0;padding:28px 32px;background:#fff;color:#1a1a1a}\n'
                        + form.querySelector('[data-editor-style]').value,
                    height: area.clientHeight || 600,
                    toolbarAdaptive: false,
                    showCharsCounter: false,
                    showWordsCounter: false,
                    showXPathInStatusbar: false,
                    askBeforePasteHTML: false,
                    theme: document.documentElement.getAttribute('data-theme') === 'light' ? 'default' : 'dark',
                    buttons: ['bold', 'italic', 'underline', '|', 'paragraph', 'ul', 'ol', 'table', 'link', '|', 'undo', 'redo', 'source'],
                });
                editor.value = form.querySelector('[data-editor-source]').defaultValue;
                form.__editor = editor;
                return editor;
            });
        }

        panes.forEach(function (pane) {
            var form = pane.querySelector('[data-editor]');
            pane.addEventListener('click', function (e) {
                if (e.target.closest('[data-toggle-instructions]')) {
                    var box = pane.querySelector('[data-instructions]');
                    box.hidden = !box.hidden;
                    return;
                }
                var button = e.target.closest('[data-mode]');
                if (!button || !form) return;
                if (button.getAttribute('data-mode') === 'edit') {
                    // Shown first, so the editor can take the height of its area. The panel starts
                    // below the page header, so its Save row may sit under the fold until scrolled.
                    setMode(pane, 'edit');
                    form.querySelector('.doc-foot').scrollIntoView({ block: 'nearest' });
                    openEditor(form).catch(function () {
                        setMode(pane, 'pdf');
                        alert(@json(__('Could not load the editor. Check the internet connection.')));
                    });
                } else {
                    // Cancel drops the unsaved edits, the next visit starts from the saved document.
                    if (form.__editor) form.__editor.value = form.querySelector('[data-editor-source]').defaultValue;
                    setMode(pane, 'pdf');
                }
            });
            if (form) {
                form.addEventListener('submit', function () {
                    form.querySelector('[data-editor-source]').value = form.__editor.value;
                });
            }
        });

        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                if (!confirm(form.getAttribute('data-confirm'))) e.preventDefault();
            });
        });
    })();
</script>
@endsection
