@extends('layouts.app')

@section('title', 'JobChecker — ' . __('Settings'))
@section('page-class', '-wide')

@section('content')
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Pipeline') }}</div>
            <h1>{{ __('Settings') }}</h1>
            <div class="sub">{{ __('Schedule, sources, filters, scoring and notifications.') }}</div>
        </div>
    </div>

    @php($sections = [
        's-schedule' => __('Schedule'),
        's-sources' => __('Sources'),
        's-filters' => __('Keywords and filters'),
        's-scoring' => __('Scoring and generation'),
        's-weights' => __('Scoring criteria weights'),
        's-params' => __('Source parameters'),
        's-company' => __('Company research'),
        's-telegram' => 'Telegram',
    ])
    @php($locationLabels = \App\Services\Sources\LocationCatalog::labels())
    @php($pickedLocations = array_values(array_intersect($settings['locations'], array_keys($locationLabels))))
    @php($customLocations = array_values(array_diff($settings['locations'], array_keys($locationLabels))))
    @php($weights = \App\Services\VacancyScorer::weights($settings))
    @php($shares = app(\App\Services\VacancyScorer::class)->normalizeWeights($weights))

    <div class="settings">
        <nav class="settings-nav" aria-label="{{ __('Settings sections') }}">
            @foreach ($sections as $id => $label)
                <a href="#{{ $id }}" @class(['sub-a', 'is-active' => $loop->first])>{{ $label }}</a>
            @endforeach
        </nav>

        <form method="post" action="{{ route('settings.update') }}" class="settings-cards" data-dirty="settings-dirty">
            @csrf

            <section id="s-schedule" class="card">
                <div class="card-head"><h3>{{ __('Schedule') }}</h3></div>
                <div class="card-body form-grid">
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
            </section>

            <section id="s-sources" class="card">
                <div class="card-head"><h3>{{ __('Sources') }}</h3></div>
                <div class="card-body chip-row">
                    @foreach (['dou' => 'dou.ua', 'djinni' => 'djinni.co', 'justjoin' => 'justjoin.it', 'pracuj' => 'it.pracuj.pl', 'jobico' => 'jobico.io', 'jooble' => 'jooble', 'linkedin' => 'LinkedIn', 'indeed' => 'Indeed'] as $key => $label)
                        <label class="chip"><input type="checkbox" name="sources[{{ $key }}]" value="1" @checked($settings['sources'][$key] ?? false)><span class="dot"></span>{{ $label }}</label>
                    @endforeach
                </div>
            </section>

            <section id="s-filters" class="card">
                <div class="card-head"><h3>{{ __('Keywords and filters') }}</h3></div>
                <div class="card-body form-grid">
                    <div class="field">
                        <span class="lab">{{ __('Search keywords') }}</span>
                        <div class="chip-row" style="gap:6px;padding:9px 11px;border:1px dashed var(--line);border-radius:var(--radius-sm)">
                            @forelse ($settings['search_keywords'] as $keyword)
                                <span class="tag">{{ $keyword }}</span>
                            @empty
                                <span class="faint" style="font-size:13px">{{ __('No keywords yet.') }}</span>
                            @endforelse
                        </div>
                        <span class="help">{{ __('Taken from the resume.') }} <a href="{{ route('dashboard') }}">{{ __('Edit on the dashboard') }}</a></span>
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
            </section>

            <section id="s-scoring" class="card">
                <div class="card-head"><h3>{{ __('Scoring and generation') }}</h3></div>
                <div class="card-body form-grid">
                    <div class="field">
                        <span class="lab">{{ __('Min. score for documents') }}</span>
                        <input type="number" name="min_score" min="0" max="100" value="{{ $settings['min_score'] }}" required style="max-width:140px">
                    </div>
                    <label class="check" style="align-self:center"><input type="checkbox" name="remote_only" value="1" @checked($settings['remote_only'])>{{ __('Remote only') }}</label>
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
            </section>

            <section id="s-weights" class="card">
                <div class="card-head"><h3>{{ __('Scoring criteria weights') }}</h3></div>
                <div class="card-body">
                    <div class="form-grid -quad" data-weights>
                        @foreach (\App\Services\VacancyScorer::labels() as $key => $label)
                            <div class="field">
                                <span class="lab">{{ $label }}</span>
                                <input type="number" name="score_weights[{{ $key }}]" min="0" max="100" value="{{ $weights[$key] }}" required>
                                <span class="weight-track"><span class="fill" style="width:{{ round($shares[$key] * 100) }}%"></span></span>
                                <span class="help mono" data-share>{{ round($shares[$key] * 100) }}%</span>
                            </div>
                        @endforeach
                    </div>
                    <div class="faint" style="font-size:12px;line-height:1.5;margin-top:12px">
                        {{ __('The model rates each criterion 0-10, the final score is their weighted sum.') }}
                        {{ __('Weights are normalized automatically, they do not have to add up to 100.') }}
                    </div>
                </div>
            </section>

            <section id="s-params" class="card">
                <div class="card-head"><h3>{{ __('Source parameters') }}</h3></div>
                <div class="card-body form-grid">
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
            </section>

            <section id="s-company" class="card">
                <div class="card-head"><h3>{{ __('Company research') }}</h3></div>
                <div class="card-body form-grid">
                    <div class="field">
                        <label class="check"><input type="checkbox" name="company_research_enabled" value="1" @checked($settings['company_research_enabled'])>{{ __('Automatically research companies of matching vacancies') }}</label>
                        <span class="help">{{ __('Looks up employee reviews (Glassdoor, Indeed, DOU and others) via web search. Up to 5 companies per run, 1-3 minutes per company. You can also research a company by hand from the vacancy page.') }}</span>
                    </div>
                    <div class="field">
                        <span class="lab">{{ __('Cache freshness, days') }}</span>
                        <input type="number" name="company_research_ttl_days" min="1" max="365" value="{{ $settings['company_research_ttl_days'] }}" required style="max-width:140px">
                        <span class="help">{{ __('A company is not researched again while its result is younger than this.') }}</span>
                    </div>
                </div>
            </section>

            <section id="s-telegram" class="card">
                <div class="card-head"><h3>{{ __('Telegram notifications') }}</h3></div>
                <div class="card-body" style="display:flex;flex-direction:column;gap:18px">
                    <label class="check"><input type="checkbox" name="telegram_enabled" value="1" @checked($settings['telegram_enabled'])>{{ __('Send matched vacancies to Telegram') }}</label>
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
                    <div class="stack">
                        {{-- Belongs to the separate form below: forms cannot nest. --}}
                        <button type="submit" form="telegram-test" class="btn btn-sm">{{ __('Send a test message to Telegram') }}</button>
                        <span class="faint" style="font-size:12px">{{ __('Uses the saved token and chat ID.') }}</span>
                    </div>
                </div>
            </section>

            <div class="savebar">
                <button type="submit" class="btn btn-primary">{{ __('Save settings') }}</button>
                <button type="reset" class="btn btn-ghost">{{ __('Cancel') }}</button>
                <span id="settings-dirty" style="color:var(--warn);font-size:12.5px" hidden>{{ __('There are unsaved changes') }}</span>
            </div>
        </form>
    </div>

    <form id="telegram-test" method="post" action="{{ route('settings.telegram-test') }}">@csrf</form>
@endsection

@section('scripts')
<script>
    (function () {
        var form = document.querySelector('.settings-cards');
        var weights = document.querySelector('[data-weights]');
        var links = document.querySelectorAll('.settings-nav .sub-a');

        function updateShares() {
            var inputs = weights.querySelectorAll('input');
            var values = Array.prototype.map.call(inputs, function (i) { return Math.max(0, parseInt(i.value, 10) || 0); });
            var sum = values.reduce(function (a, b) { return a + b; }, 0);
            inputs.forEach(function (input, n) {
                var share = sum ? Math.round(values[n] / sum * 100) : 0;
                var field = input.closest('.field');
                field.querySelector('.fill').style.width = share + '%';
                field.querySelector('[data-share]').textContent = share + '%';
            });
        }
        weights.addEventListener('input', updateShares);

        // Reset restores the fields, but the dropdown summaries and the share bars only
        // redraw on their own events.
        form.addEventListener('reset', function () {
            setTimeout(function () {
                form.querySelectorAll('[data-multiselect]').forEach(function (root) { root.dispatchEvent(new Event('change')); });
                updateShares();
            }, 0);
        });

        // Highlight the section currently at the top of the viewport.
        if ('IntersectionObserver' in window) {
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;
                    links.forEach(function (a) { a.classList.toggle('is-active', a.getAttribute('href') === '#' + entry.target.id); });
                });
            }, { rootMargin: '-10% 0px -80% 0px' });
            form.querySelectorAll('section[id]').forEach(function (s) { observer.observe(s); });
        }
    })();
</script>
@endsection
