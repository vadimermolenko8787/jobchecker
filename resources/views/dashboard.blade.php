@extends('layouts.app')

@section('title', 'JobChecker — панель')

@section('content')
    <div class="page-head">
        <div>
            <div class="eyebrow">Командный пункт</div>
            <h1>Панель</h1>
            <div class="sub">Состояние охоты, запуск сканирования и настройки пайплайна.</div>
        </div>
        <a href="{{ route('vacancies.index', ['status' => 'matched']) }}" class="btn btn-ghost">
            Смотреть подходящие
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </a>
    </div>

    {{-- Hunt status strip --}}
    <div class="grid grid-4" style="margin-bottom:20px">
        <div class="stat">
            <div class="l">Вакансий в базе</div>
            <div class="n">{{ $counts['total'] }}</div>
        </div>
        <div class="stat -accent">
            <div class="l">Подходящих</div>
            <div class="n">{{ $counts['matched'] }}</div>
        </div>
        <div class="stat -ok">
            <div class="l">С документами</div>
            <div class="n">{{ $counts['done'] }}</div>
        </div>
        <div class="stat">
            <div class="l">Подано</div>
            <div class="n">{{ $globalCounts['applied'] }}</div>
        </div>
    </div>

    {{-- Resume + live run --}}
    <div class="grid grid-2">
        <div class="card">
            <div class="card-head">
                <h3>Резюме</h3>
                @if($resume)<span class="hint">{{ mb_strlen((string) $resume->text) }} симв.</span>@endif
            </div>
            <div class="card-body">
                @if ($resume)
                    <div style="display:flex;gap:12px;align-items:flex-start;margin-bottom:14px">
                        <span style="width:38px;height:38px;border-radius:9px;background:var(--danger-dim);color:var(--danger);display:grid;place-items:center;flex:none">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v5h5M8 2h7l5 5v13a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Z"/></svg>
                        </span>
                        <div style="min-width:0">
                            <div style="font-weight:600;word-break:break-word">{{ $resume->original_name }}</div>
                            <div class="faint" style="font-size:12.5px">загружено {{ $resume->created_at->format('d.m.Y H:i') }}</div>
                        </div>
                    </div>
                    <div class="chip-row" style="margin-bottom:14px">
                        @forelse ($resume->keywords ?? [] as $kw)
                            <span class="tag">{{ $kw }}</span>
                        @empty
                            <span class="faint" style="font-size:13px">ключевые слова не извлечены</span>
                        @endforelse
                    </div>
                    <details class="box">
                        <summary>Извлечённый текст</summary>
                        <div class="details-body"><pre class="log" style="max-height:360px;white-space:pre-wrap">{{ $resume->text }}</pre></div>
                    </details>
                @else
                    <div class="empty" style="padding:20px 0 24px">
                        <div style="font-weight:600;color:var(--text);margin-bottom:4px">Резюме ещё не загружено</div>
                        <div class="faint" style="font-size:13px">Загрузите PDF, Claude извлечёт стек и заполнит ключевые слова.</div>
                    </div>
                @endif
                <form method="post" action="{{ route('resume.store') }}" enctype="multipart/form-data" style="margin-top:16px">
                    @csrf
                    <div class="field">
                        <input type="file" name="resume" accept="application/pdf" required>
                    </div>
                    <div class="stack" style="margin-top:12px">
                        <button type="submit" class="btn btn-primary">Загрузить PDF</button>
                        <span class="faint" style="font-size:12px;max-width:260px">После загрузки Claude извлечёт ключевые слова стека (до минуты).</span>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <h3>Сканирование</h3>
                <span class="badge -neutral" id="run-badge" style="display:none"></span>
            </div>
            <div class="card-body">
                <p class="muted" style="margin-top:0">
                    Обход выбранных источников и скоринг вакансий по резюме. Документы генерируются только вручную со страницы вакансии.
                </p>
                <form method="post" action="{{ route('run.start') }}">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-block" id="start-btn" @unless($resume) disabled data-noresume @endunless>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
                        Запустить поиск
                    </button>
                    @unless($resume)<div class="faint" style="font-size:12.5px;text-align:center;margin-top:8px">Сначала загрузите резюме.</div>@endunless
                </form>
                <div id="run-status" class="mono" style="font-size:13px;color:var(--muted);margin-top:14px"></div>
                <pre class="log" id="run-log" style="display:none;margin-top:12px"></pre>
            </div>
        </div>
    </div>

    {{-- Settings --}}
    <div class="card">
        <div class="card-head">
            <h3>Настройки пайплайна</h3>
        </div>
        <div class="card-body">
            <form method="post" action="{{ route('settings.update') }}">
                @csrf

                <div class="section-label">Расписание</div>
                <div class="form-grid">
                    <div class="field">
                        <span class="lab">Cron-выражение</span>
                        <input type="text" name="cron_expression" value="{{ old('cron_expression', $settings['cron_expression']) }}" required>
                        <span class="help"><code>0 * * * *</code> — каждый час, <code>0 */6 * * *</code> — каждые 6 часов, <code>0 9 * * *</code> — ежедневно в 9:00</span>
                    </div>
                    <fieldset class="fieldset">
                        <legend>Автозапуск</legend>
                        <label class="check"><input type="checkbox" name="schedule_enabled" value="1" @checked($settings['schedule_enabled'])> Включить расписание</label>
                        <div class="help" style="margin-top:8px">Один раз добавьте в crontab:<br><code>* * * * * cd {{ base_path() }} && php artisan schedule:run >> /dev/null 2>&1</code></div>
                    </fieldset>
                </div>

                <div class="section-label">Источники</div>
                <div class="chip-row">
                    @foreach (['dou' => 'dou.ua', 'djinni' => 'djinni.co', 'justjoin' => 'justjoin.it', 'linkedin' => 'LinkedIn', 'indeed' => 'Indeed'] as $key => $label)
                        <label class="chip"><input type="checkbox" name="sources[{{ $key }}]" value="1" @checked($settings['sources'][$key] ?? false)><span class="dot"></span>{{ $label }}</label>
                    @endforeach
                </div>

                @php($locationLabels = \App\Services\Sources\LocationCatalog::labels())
                @php($pickedLocations = array_values(array_intersect($settings['locations'], array_keys($locationLabels))))
                @php($customLocations = array_values(array_diff($settings['locations'], array_keys($locationLabels))))
                <div class="section-label">Ключевые слова и фильтры</div>
                <div class="form-grid">
                    <div class="field">
                        <span class="lab">Ключевые слова поиска</span>
                        <input type="text" name="search_keywords" value="{{ implode(', ', $settings['search_keywords']) }}">
                        <span class="help">Заполняются автоматически из резюме, можно править. Через запятую.</span>
                    </div>
                    <div class="field">
                        <span class="lab">Локации <span class="faint">(LinkedIn / Indeed)</span></span>
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
                        <span class="help">Поиск идёт по каждой отмеченной локации.</span>
                    </div>
                    <div class="field">
                        <span class="lab">Другие локации <span class="faint">(через запятую)</span></span>
                        <input type="text" name="locations_custom" value="{{ implode(', ', $customLocations) }}">
                        <span class="help">Чего нет в списке. Для Indeed добавьте код страны через двоеточие — <code>Tbilisi:GE</code>, иначе локация ищется только на LinkedIn.</span>
                    </div>
                    <div class="field">
                        <span class="lab">Обязательные слова</span>
                        <input type="text" name="include_keywords" value="{{ implode(', ', $settings['include_keywords']) }}">
                        <span class="help">Вакансия сохраняется, только если содержит хотя бы одно.</span>
                    </div>
                    <div class="field">
                        <span class="lab">Стоп-слова</span>
                        <input type="text" name="exclude_keywords" value="{{ implode(', ', $settings['exclude_keywords']) }}">
                        <span class="help">Вакансии с этими словами отбрасываются.</span>
                    </div>
                </div>

                <div class="section-label">Скоринг и генерация</div>
                <div class="form-grid -tri">
                    <label class="check" style="align-self:center"><input type="checkbox" name="remote_only" value="1" @checked($settings['remote_only'])> Только remote</label>
                    <div class="field">
                        <span class="lab">Мин. score для документов</span>
                        <input type="number" name="min_score" min="0" max="100" value="{{ $settings['min_score'] }}" required>
                    </div>
                    <div class="field">
                        <span class="lab">Язык cover letter</span>
                        <select name="cover_letter_language">
                            @foreach (\App\Services\DocumentGenerator::LANGUAGE_LABELS as $code => $label)
                                <option value="{{ $code }}" @selected(($settings['cover_letter_language'] ?? 'en') === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <span class="help">Резюме всегда на английском.</span>
                    </div>
                    <div class="field">
                        <span class="lab">Известные языки</span>
                        <input type="text" name="known_languages" value="{{ implode(', ', $settings['known_languages']) }}">
                        <span class="help">Через запятую. Влияет на оценку: вакансии на неизвестных языках получают предупреждение и сниженный score.</span>
                    </div>
                </div>

                @php($weights = \App\Services\VacancyScorer::weights($settings))
                <div class="section-label">Веса критериев скоринга</div>
                <div class="form-grid -tri">
                    @foreach (\App\Services\VacancyScorer::LABELS as $key => $label)
                        <div class="field">
                            <span class="lab">{{ $label }}</span>
                            <input type="number" name="score_weights[{{ $key }}]" min="0" max="100" value="{{ $weights[$key] }}" required>
                        </div>
                    @endforeach
                </div>
                <div class="faint" style="font-size:12px;line-height:1.5;margin-top:8px">
                    Каждый критерий модель оценивает 0-10, итоговый score считается как взвешенная сумма.
                    Веса нормализуются автоматически, сумма не обязана быть 100.
                </div>

                <details class="box">
                    <summary>Параметры источников</summary>
                    <div class="details-body">
                        <div class="form-grid">
                            <div class="field"><span class="lab">Категория DOU</span><input type="text" name="dou_category" value="{{ $settings['dou_category'] }}"></div>
                            <div class="field"><span class="lab">Primary keyword Djinni</span><input type="text" name="djinni_primary_keyword" value="{{ $settings['djinni_primary_keyword'] }}"></div>
                            <div class="field"><span class="lab">Категория justjoin.it <span class="faint">(число, 3 = PHP)</span></span><input type="number" name="justjoin_category" value="{{ $settings['justjoin_category'] }}"></div>
                            <div class="field"><span class="lab">LinkedIn: локаций в пачке</span><input type="number" name="linkedin_batch_size" min="1" max="10" value="{{ $settings['linkedin_batch_size'] }}" required></div>
                            <div class="field">
                                <span class="lab">LinkedIn: пауза между пачками, сек</span>
                                <input type="number" name="linkedin_batch_pause" min="0" max="300" value="{{ $settings['linkedin_batch_pause'] }}" required>
                                <span class="help">Защита от 429 на гостевом endpoint.</span>
                            </div>
                        </div>
                    </div>
                </details>

                <details class="box">
                    <summary>Исследование компаний</summary>
                    <div class="details-body">
                        <label class="check" style="margin-bottom:12px"><input type="checkbox" name="company_research_enabled" value="1" @checked($settings['company_research_enabled'])> Автоматически исследовать компании подходящих вакансий</label>
                        <div class="help" style="margin-bottom:12px">Поиск отзывов сотрудников (Glassdoor, Indeed, DOU и др.) через веб-поиск. До 5 компаний за запуск, 1-3 минуты на компанию. Исследовать можно и вручную со страницы вакансии.</div>
                        <div class="form-grid">
                            <div class="field">
                                <span class="lab">Срок свежести кэша, дней</span>
                                <input type="number" name="company_research_ttl_days" min="1" max="365" value="{{ $settings['company_research_ttl_days'] }}" required>
                                <span class="help">Компания не исследуется повторно, пока результат моложе этого срока.</span>
                            </div>
                        </div>
                    </div>
                </details>

                <details class="box">
                    <summary>Telegram-уведомления</summary>
                    <div class="details-body">
                        <label class="check" style="margin-bottom:12px"><input type="checkbox" name="telegram_enabled" value="1" @checked($settings['telegram_enabled'])> Отправлять matched-вакансии в Telegram</label>
                        <div class="form-grid">
                            <div class="field">
                                <span class="lab">Bot token</span>
                                <input type="password" name="telegram_bot_token" value="{{ $settings['telegram_bot_token'] }}" autocomplete="off">
                                <span class="help">Получить у <code>@BotFather</code></span>
                            </div>
                            <div class="field">
                                <span class="lab">Chat ID</span>
                                <input type="text" name="telegram_chat_id" value="{{ $settings['telegram_chat_id'] }}">
                                <span class="help">Узнать у <code>@userinfobot</code>, боту нужно сначала написать /start</span>
                            </div>
                        </div>
                    </div>
                </details>

                <div class="stack" style="margin-top:22px">
                    <button type="submit" class="btn btn-primary">Сохранить настройки</button>
                </div>
            </form>
            <form method="post" action="{{ route('settings.telegram-test') }}" style="margin-top:12px">
                @csrf
                <div class="stack">
                    <button type="submit" class="btn btn-sm">Отправить тестовое сообщение в Telegram</button>
                    <span class="faint" style="font-size:12px">Использует сохранённые token и chat ID.</span>
                </div>
            </form>
        </div>
    </div>

    {{-- Recent runs --}}
    <div class="card">
        <div class="card-head">
            <h3>Последние запуски</h3>
        </div>
        <div class="card-body" style="padding:0">
            <div class="table-wrap" style="border:none;border-radius:0">
                <table class="data">
                    <thead><tr><th>#</th><th>Тип</th><th>Статус</th><th>Время запуска</th><th>Статистика</th></tr></thead>
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
                        <tr><td colspan="5"><div class="empty">Запусков ещё не было. Нажмите «Запустить поиск».</div></td></tr>
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
                statusEl.innerHTML = 'Запуск #' + run.id + ' · ' + (run.started_at || '');
                logEl.style.display = 'block';
                logEl.textContent = run.log || '';
                logEl.scrollTop = logEl.scrollHeight;
            }
        };
    })();
</script>
@endsection
