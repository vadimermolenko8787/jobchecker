@extends('layouts.app')

@section('title', 'JobChecker — ' . __('dashboard'))

@section('content')
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Command center') }}</div>
            <h1>{{ __('Dashboard') }}</h1>
            <div class="sub">{{ __('Hunt status, scan launch and pipeline settings.') }}</div>
        </div>
        <a href="{{ route('vacancies.index', ['status' => 'matched']) }}" class="btn btn-ghost">
            {{ __('View matches') }}
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </a>
    </div>

    {{-- Hunt status strip --}}
    <div class="grid grid-4" style="margin-bottom:20px">
        <div class="stat">
            <div class="l">{{ __('Vacancies in database') }}</div>
            <div class="n">{{ $counts['total'] }}</div>
        </div>
        <div class="stat -accent">
            <div class="l">{{ __('Matching') }}</div>
            <div class="n">{{ $counts['matched'] }}</div>
        </div>
        <div class="stat -ok">
            <div class="l">{{ __('With documents') }}</div>
            <div class="n">{{ $counts['done'] }}</div>
        </div>
        <div class="stat">
            <div class="l">{{ __('Applied') }}</div>
            <div class="n">{{ $globalCounts['applied'] }}</div>
        </div>
    </div>

    {{-- Per-source breakdown: same rows twice, once by volume and once by what actually matched --}}
    <div class="grid grid-2" style="margin-bottom:20px">
        @foreach ([
            [__('Vacancies by source'), 'total', 'var(--info)', __('total in database'), []],
            [__('Matches by source'), 'matched', 'var(--signal)', __('status matched'), ['status' => 'matched']],
        ] as [$heading, $field, $color, $hint, $query])
            @php($max = max(1, (int) $bySource->max($field)))
            <div class="card">
                <div class="card-head">
                    <h3>{{ $heading }}</h3>
                    <span class="hint">{{ $hint }}</span>
                </div>
                <div class="card-body">
                    @forelse ($bySource as $row)
                        <div class="src-row">
                            <a href="{{ route('vacancies.index', $query + ['source' => [$row->source]]) }}" style="text-decoration:none">
                                <span class="tag">{{ $row->source }}</span>
                            </a>
                            <span class="track"><span class="fill" style="width:{{ round($row->$field / $max * 100) }}%;background:{{ $color }}"></span></span>
                            <span class="sv">{{ $row->$field }}</span>
                        </div>
                    @empty
                        <div class="empty">{{ __('No vacancies yet.') }}</div>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>

    {{-- Resume + live run --}}
    <div class="grid grid-2">
        <div class="card">
            <div class="card-head">
                <h3>{{ __('Resume') }}</h3>
                @if($resume)<span class="hint">{{ __(':count chars', ['count' => mb_strlen((string) $resume->text)]) }}</span>@endif
            </div>
            <div class="card-body">
                @if ($resume)
                    <div style="display:flex;gap:12px;align-items:flex-start;margin-bottom:14px">
                        <span style="width:38px;height:38px;border-radius:9px;background:var(--danger-dim);color:var(--danger);display:grid;place-items:center;flex:none">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v5h5M8 2h7l5 5v13a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Z"/></svg>
                        </span>
                        <div style="min-width:0">
                            <div style="font-weight:600;word-break:break-word">{{ $resume->original_name }}</div>
                            <div class="faint" style="font-size:12.5px">{{ __('uploaded :date', ['date' => $resume->created_at->format('d.m.Y H:i')]) }}</div>
                        </div>
                    </div>
                    <div class="chip-row" style="margin-bottom:14px">
                        @forelse ($resume->keywords ?? [] as $kw)
                            <span class="tag">{{ $kw }}</span>
                        @empty
                            <span class="faint" style="font-size:13px">{{ __('no keywords extracted') }}</span>
                        @endforelse
                    </div>
                    <details class="box">
                        <summary>{{ __('Extracted text') }}</summary>
                        <div class="details-body"><pre class="log" style="max-height:360px;white-space:pre-wrap">{{ $resume->text }}</pre></div>
                    </details>
                @else
                    <div class="empty" style="padding:20px 0 24px">
                        <div style="font-weight:600;color:var(--text);margin-bottom:4px">{{ __('No resume uploaded yet') }}</div>
                        <div class="faint" style="font-size:13px">{{ __('Upload a PDF, Claude will extract your stack and fill in the keywords.') }}</div>
                    </div>
                @endif
                <form method="post" action="{{ route('resume.store') }}" enctype="multipart/form-data" style="margin-top:16px">
                    @csrf
                    <div class="field">
                        <input type="file" name="resume" accept="application/pdf" required>
                    </div>
                    <div class="stack" style="margin-top:12px">
                        <button type="submit" class="btn btn-primary">{{ __('Upload PDF') }}</button>
                        <span class="faint" style="font-size:12px;max-width:260px">{{ __('After upload Claude extracts your stack keywords (up to a minute).') }}</span>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <h3>{{ __('Scanning') }}</h3>
                <span class="badge -neutral" id="run-badge" style="display:none"></span>
            </div>
            <div class="card-body">
                <p class="muted" style="margin-top:0">
                    {{ __('Crawls the selected sources and scores vacancies against your resume. Documents are generated only by hand from the vacancy page.') }}
                </p>
                <form method="post" action="{{ route('run.start') }}">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-block" id="start-btn" @unless($resume) disabled data-noresume @endunless>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
                        {{ __('Start search') }}
                    </button>
                    @unless($resume)<div class="faint" style="font-size:12.5px;text-align:center;margin-top:8px">{{ __('Upload a resume first.') }}</div>@endunless
                </form>
                <form method="post" action="{{ route('run.stop') }}" data-run-stop style="display:none;margin-top:10px">
                    @csrf
                    <button type="submit" class="btn btn-danger btn-block">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="5" y="5" width="14" height="14" rx="2"/></svg>
                        {{ __('Stop search') }}
                    </button>
                    <div class="faint" style="font-size:12.5px;text-align:center;margin-top:8px">{{ __('The run stops after the current step, vacancies already scored are kept.') }}</div>
                </form>
                <div id="run-status" class="mono" style="font-size:13px;color:var(--muted);margin-top:14px"></div>
                <pre class="log" id="run-log" style="display:none;margin-top:12px"></pre>
            </div>
        </div>
    </div>

    {{-- Settings --}}
    <div class="card">
        <div class="card-head">
            <h3>{{ __('Pipeline settings') }}</h3>
        </div>
        <div class="card-body">
            <form method="post" action="{{ route('settings.update') }}">
                @csrf

                <div class="section-label">{{ __('Schedule') }}</div>
                <div class="form-grid">
                    <div class="field">
                        <span class="lab">{{ __('Cron expression') }}</span>
                        <input type="text" name="cron_expression" value="{{ old('cron_expression', $settings['cron_expression']) }}" required>
                        <span class="help">{!! __(':hourly every hour, :six every 6 hours, :daily daily at 9:00', ['hourly' => '<code>0 * * * *</code>', 'six' => '<code>0 */6 * * *</code>', 'daily' => '<code>0 9 * * *</code>']) !!}</span>
                    </div>
                    <fieldset class="fieldset">
                        <legend>{{ __('Autorun') }}</legend>
                        <label class="check"><input type="checkbox" name="schedule_enabled" value="1" @checked($settings['schedule_enabled'])>{{ __('Enable schedule') }}</label>
                        <div class="help" style="margin-top:8px">{{ __('Add to crontab once:') }}<br><code>* * * * * cd {{ base_path() }} && php artisan schedule:run >> /dev/null 2>&1</code></div>
                    </fieldset>
                </div>

                <div class="section-label">{{ __('Sources') }}</div>
                <div class="chip-row">
                    @foreach (['dou' => 'dou.ua', 'djinni' => 'djinni.co', 'justjoin' => 'justjoin.it', 'pracuj' => 'it.pracuj.pl', 'jobico' => 'jobico.io', 'jooble' => 'jooble', 'linkedin' => 'LinkedIn', 'indeed' => 'Indeed'] as $key => $label)
                        <label class="chip"><input type="checkbox" name="sources[{{ $key }}]" value="1" @checked($settings['sources'][$key] ?? false)><span class="dot"></span>{{ $label }}</label>
                    @endforeach
                </div>

                @php($locationLabels = \App\Services\Sources\LocationCatalog::labels())
                @php($pickedLocations = array_values(array_intersect($settings['locations'], array_keys($locationLabels))))
                @php($customLocations = array_values(array_diff($settings['locations'], array_keys($locationLabels))))
                <div class="section-label">{{ __('Keywords and filters') }}</div>
                <div class="form-grid">
                    <div class="field">
                        <span class="lab">{{ __('Search keywords') }}</span>
                        <input type="text" name="search_keywords" value="{{ implode(', ', $settings['search_keywords']) }}">
                        <span class="help">{{ __('Filled in from the resume automatically, editable. Comma-separated.') }}</span>
                    </div>
                    <div class="field">
                        <span class="lab">{{ __('Locations') }} <span class="faint">(LinkedIn / Indeed)</span></span>
                        <div class="multiselect" data-multiselect>
                            <button type="button" class="ms-toggle" aria-expanded="false" aria-haspopup="true">
                                <span class="ms-summary"></span><span class="ms-caret">▼</span>
                            </button>
                            <div class="ms-panel chip-row">
                                @foreach ($locationLabels as $value => $label)
                                    <label class="chip"><input type="checkbox" name="locations[]" value="{{ $value }}" @checked(in_array($value, $pickedLocations, true))><span class="dot"></span>{{ $label }}</label>
                                @endforeach
                            </div>
                        </div>
                        <span class="help">{{ __('Each selected location is searched separately.') }}</span>
                    </div>
                    <div class="field">
                        <span class="lab">{{ __('Other locations') }} <span class="faint">{{ __('(comma-separated)') }}</span></span>
                        <input type="text" name="locations_custom" value="{{ implode(', ', $customLocations) }}">
                        <span class="help">{!! __('Anything missing from the list. For Indeed add the country code after a colon, :example, otherwise the location is searched on LinkedIn only.', ['example' => '<code>Tbilisi:GE</code>']) !!}</span>
                    </div>
                    <div class="field">
                        <span class="lab">{{ __('Required words') }}</span>
                        <input type="text" name="include_keywords" value="{{ implode(', ', $settings['include_keywords']) }}">
                        <span class="help">{{ __('A vacancy is kept only if it contains at least one of them.') }}</span>
                    </div>
                    <div class="field">
                        <span class="lab">{{ __('Stop words') }}</span>
                        <input type="text" name="exclude_keywords" value="{{ implode(', ', $settings['exclude_keywords']) }}">
                        <span class="help">{{ __('Vacancies containing these words are dropped.') }}</span>
                    </div>
                </div>

                <div class="section-label">{{ __('Scoring and generation') }}</div>
                <div class="form-grid -tri">
                    <label class="check" style="align-self:center"><input type="checkbox" name="remote_only" value="1" @checked($settings['remote_only'])>{{ __('Remote only') }}</label>
                    <div class="field">
                        <span class="lab">{{ __('Min. score for documents') }}</span>
                        <input type="number" name="min_score" min="0" max="100" value="{{ $settings['min_score'] }}" required>
                    </div>
                    <div class="field">
                        <span class="lab">{{ __('Cover letter language') }}</span>
                        <select name="cover_letter_language">
                            @foreach (\App\Services\DocumentGenerator::languageLabels() as $code => $label)
                                <option value="{{ $code }}" @selected(($settings['cover_letter_language'] ?? 'en') === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <span class="help">{{ __('The resume is always in English.') }}</span>
                    </div>
                    <div class="field">
                        <span class="lab">{{ __('Known languages') }}</span>
                        <input type="text" name="known_languages" value="{{ implode(', ', $settings['known_languages']) }}">
                        <span class="help">{{ __('Comma-separated. Vacancies written in other languages are rejected after scoring. If the role requires an unknown language, the score is lowered.') }}</span>
                    </div>
                </div>

                @php($weights = \App\Services\VacancyScorer::weights($settings))
                <div class="section-label">{{ __('Scoring criteria weights') }}</div>
                <div class="form-grid -tri">
                    @foreach (\App\Services\VacancyScorer::labels() as $key => $label)
                        <div class="field">
                            <span class="lab">{{ $label }}</span>
                            <input type="number" name="score_weights[{{ $key }}]" min="0" max="100" value="{{ $weights[$key] }}" required>
                        </div>
                    @endforeach
                </div>
                <div class="faint" style="font-size:12px;line-height:1.5;margin-top:8px">
                    {{ __('The model rates each criterion 0-10, the final score is their weighted sum.') }}
                    {{ __('Weights are normalized automatically, they do not have to add up to 100.') }}
                </div>

                <details class="box">
                    <summary>{{ __('Source parameters') }}</summary>
                    <div class="details-body">
                        <div class="form-grid">
                            <div class="field"><span class="lab">{{ __('DOU category') }}</span><input type="text" name="dou_category" value="{{ $settings['dou_category'] }}"></div>
                            <div class="field"><span class="lab">Primary keyword Djinni</span><input type="text" name="djinni_primary_keyword" value="{{ $settings['djinni_primary_keyword'] }}"></div>
                            <div class="field"><span class="lab">{{ __('justjoin.it category') }} <span class="faint">{{ __('(number, 3 = PHP)') }}</span></span><input type="number" name="justjoin_category" value="{{ $settings['justjoin_category'] }}"></div>
                            <div class="field">
                                <span class="lab">{{ __('it.pracuj.pl categories') }}</span>
                                <div class="multiselect" data-multiselect data-empty="{{ __('Both IT sections') }}">
                                    <button type="button" class="ms-toggle" aria-expanded="false" aria-haspopup="true">
                                        <span class="ms-summary"></span><span class="ms-caret">▼</span>
                                    </button>
                                    <div class="ms-panel chip-row">
                                        @foreach (\App\Services\Sources\PracujSource::categoryLabels() as $code => $label)
                                            <label class="chip"><input type="checkbox" name="pracuj_categories[]" value="{{ $code }}" @checked(in_array((string) $code, array_map('strval', $settings['pracuj_categories'] ?? []), true))><span class="dot"></span>{{ $label }}</label>
                                        @endforeach
                                    </div>
                                </div>
                                <span class="help">{{ __('Each selected category is searched separately. With nothing selected, both IT sections are searched in full.') }}</span>
                            </div>
                            <div class="field">
                                <span class="lab">{{ __('jooble API keys') }} <span class="faint">{{ __('(comma-separated)') }}</span></span>
                                <input type="text" name="jooble_keys" value="{{ collect($settings['jooble_keys'] ?? [])->map(fn ($key, $country) => $country . ':' . $key)->implode(', ') }}" autocomplete="off">
                                <span class="help">{!! __('Country site code and key separated by a colon: :example. A key is issued at :url and works only on its own site. Every country with a key is searched, locations do not affect this source.', ['example' => '<code>' . e(__('de:key, pl:key')) . '</code>', 'url' => '<code>&lt;' . e(__('code')) . '&gt;.jooble.org/api/about</code>']) !!}</span>
                            </div>
                            <div class="field">
                                <span class="lab">{{ __('Indeed API key') }}</span>
                                <input type="password" name="indeed_api_key" value="{{ $settings['indeed_api_key'] }}" autocomplete="off">
                                <span class="help">{!! __('The key of the Indeed mobile app, published by the :project project. If Indeed rotates it, take the current one from there.', ['project' => '<code>github.com/speedyapply/JobSpy</code>']) !!}</span>
                            </div>
                            <div class="field"><span class="lab">{{ __('LinkedIn: locations per batch') }}</span><input type="number" name="linkedin_batch_size" min="1" max="10" value="{{ $settings['linkedin_batch_size'] }}" required></div>
                            <div class="field">
                                <span class="lab">{{ __('LinkedIn: pause between batches, sec') }}</span>
                                <input type="number" name="linkedin_batch_pause" min="0" max="300" value="{{ $settings['linkedin_batch_pause'] }}" required>
                                <span class="help">{{ __('Guards against 429 on the guest endpoint.') }}</span>
                            </div>
                        </div>
                    </div>
                </details>

                <details class="box">
                    <summary>{{ __('Company research') }}</summary>
                    <div class="details-body">
                        <label class="check" style="margin-bottom:12px"><input type="checkbox" name="company_research_enabled" value="1" @checked($settings['company_research_enabled'])>{{ __('Automatically research companies of matching vacancies') }}</label>
                        <div class="help" style="margin-bottom:12px">{{ __('Looks up employee reviews (Glassdoor, Indeed, DOU and others) via web search. Up to 5 companies per run, 1-3 minutes per company. You can also research a company by hand from the vacancy page.') }}</div>
                        <div class="form-grid">
                            <div class="field">
                                <span class="lab">{{ __('Cache freshness, days') }}</span>
                                <input type="number" name="company_research_ttl_days" min="1" max="365" value="{{ $settings['company_research_ttl_days'] }}" required>
                                <span class="help">{{ __('A company is not researched again while its result is younger than this.') }}</span>
                            </div>
                        </div>
                    </div>
                </details>

                <details class="box">
                    <summary>{{ __('Telegram notifications') }}</summary>
                    <div class="details-body">
                        <label class="check" style="margin-bottom:12px"><input type="checkbox" name="telegram_enabled" value="1" @checked($settings['telegram_enabled'])>{{ __('Send matched vacancies to Telegram') }}</label>
                        <div class="form-grid">
                            <div class="field">
                                <span class="lab">Bot token</span>
                                <input type="password" name="telegram_bot_token" value="{{ $settings['telegram_bot_token'] }}" autocomplete="off">
                                <span class="help">{!! __('Get it from :bot', ['bot' => '<code>@BotFather</code>']) !!}</span>
                            </div>
                            <div class="field">
                                <span class="lab">Chat ID</span>
                                <input type="text" name="telegram_chat_id" value="{{ $settings['telegram_chat_id'] }}">
                                <span class="help">{!! __('Ask :bot, send the bot /start first', ['bot' => '<code>@userinfobot</code>']) !!}</span>
                            </div>
                        </div>
                    </div>
                </details>

                <div class="stack" style="margin-top:22px">
                    <button type="submit" class="btn btn-primary">{{ __('Save settings') }}</button>
                </div>
            </form>
            <form method="post" action="{{ route('settings.telegram-test') }}" style="margin-top:12px">
                @csrf
                <div class="stack">
                    <button type="submit" class="btn btn-sm">{{ __('Send a test message to Telegram') }}</button>
                    <span class="faint" style="font-size:12px">{{ __('Uses the saved token and chat ID.') }}</span>
                </div>
            </form>
        </div>
    </div>

    {{-- Recent runs --}}
    <div class="card">
        <div class="card-head">
            <h3>{{ __('Recent runs') }}</h3>
        </div>
        <div class="card-body" style="padding:0">
            <div class="table-wrap" style="border:none;border-radius:0">
                <table class="data">
                    <thead><tr><th>#</th><th>{{ __('Type') }}</th><th>{{ __('Status') }}</th><th>{{ __('Started at') }}</th><th>{{ __('Statistics') }}</th></tr></thead>
                    <tbody>
                    @forelse ($runs as $run)
                        <tr>
                            <td><a href="{{ route('runs.show', $run) }}" class="mono" style="font-weight:600">#{{ $run->id }}</a></td>
                            <td><span class="tag">{{ $run->trigger }}</span></td>
                            <td><span class="badge -{{ $run->status }}">{{ $run->status }}</span></td>
                            <td class="mono faint nowrap" style="font-size:12.5px">{{ $run->started_at?->setTimezone('Europe/Berlin')->format('d.m H:i:s') }}</td>
                            <td class="mono muted" style="font-size:12px">{{ $run->stats ? json_encode($run->stats, JSON_UNESCAPED_UNICODE) : '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><div class="empty">{{ __('No runs yet. Press “:button”.', ['button' => __('Start search')]) }}</div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
<script>
    (function () {
        var statusEl = document.getElementById('run-status');
        var logEl = document.getElementById('run-log');
        var badge = document.getElementById('run-badge');
        window.__jcOnRun = function (run, running, wasRunning) {
            if (badge) {
                badge.style.display = 'inline-flex';
                badge.className = 'badge -' + run.status;
                badge.textContent = run.status;
            }
            if (running || wasRunning) {
                statusEl.innerHTML = @json(__('Run #:id · :started')).replace(':id', run.id).replace(':started', run.started_at || '');
                logEl.style.display = 'block';
                logEl.textContent = run.log || '';
                logEl.scrollTop = logEl.scrollHeight;
            }
        };
    })();
</script>
@endsection
