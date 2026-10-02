@extends('layouts.app')

@section('title', 'JobChecker — ' . __('dashboard'))
@section('page-class', '-wide')

@section('content')
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Command center') }}</div>
            <h1>{{ __('Dashboard') }}</h1>
            <div class="sub">{{ __('Hunt status, resume and scan launch.') }}</div>
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

    <div class="dash-grid">
        {{-- Resume: the text and keywords here are what scoring and generation use --}}
        <div class="card">
            <div class="card-head">
                <div class="stack" style="gap:10px">
                    <h3>{{ __('Resume') }}</h3>
                    <span class="badge -warn" id="resume-dirty" hidden>{{ __('unsaved edits') }}</span>
                </div>
                @if ($resume)
                    <span class="hint">{{ __(':count chars', ['count' => mb_strlen((string) $resume->text)]) }} · {{ trans_choice(':count keyword|:count keywords', count($keywords)) }}</span>
                @endif
            </div>
            <div class="card-body">
                @if ($resume)
                    <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;padding:12px 14px;background:var(--raised);border:1px solid var(--line-soft);border-radius:10px">
                        <span style="width:38px;height:38px;border-radius:9px;background:var(--danger-dim);color:var(--danger);display:grid;place-items:center;flex:none">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v5h5M8 2h7l5 5v13a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Z"/></svg>
                        </span>
                        <div style="min-width:0;flex:1">
                            <div style="font-weight:600;word-break:break-word">{{ $resume->original_name }}</div>
                            <div class="faint" style="font-size:12.5px">{{ __('uploaded :date', ['date' => $resume->created_at->format('d.m.Y H:i')]) }} · {{ __('the CV design is taken from this file') }}</div>
                        </div>
                        <a href="{{ route('resume.file') }}" target="_blank" rel="noopener" class="btn btn-sm btn-ghost">{{ __('Open PDF') }}</a>
                        <form method="post" action="{{ route('resume.store') }}" enctype="multipart/form-data">
                            @csrf
                            <label class="btn btn-sm" title="{{ __('After upload Claude extracts your stack keywords (up to a minute).') }}">
                                {{ __('Upload a new PDF') }}
                                <input type="file" name="resume" accept="application/pdf" hidden onchange="this.form.submit()">
                            </label>
                        </form>
                    </div>

                    <form method="post" action="{{ route('resume.update') }}" data-dirty="resume-dirty" style="display:flex;flex-direction:column;gap:20px;margin-top:20px">
                        @csrf
                        @method('PUT')

                        <div class="field">
                            <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap">
                                <label class="lab" for="kw-add">{{ __('Keywords') }}</label>
                                <span class="help">{{ __('Used as search keywords on every source') }}</span>
                            </div>
                            <div class="kw-box" data-keywords>
                                @foreach (old('keywords', $keywords) as $keyword)
                                    <span class="kw">{{ $keyword }}<input type="hidden" name="keywords[]" value="{{ $keyword }}"><button type="button" aria-label="{{ __('Remove :keyword', ['keyword' => $keyword]) }}"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button></span>
                                @endforeach
                                <input type="text" id="kw-add" class="kw-add" maxlength="60" placeholder="{{ __('add and press Enter') }}">
                            </div>
                        </div>

                        <div class="field">
                            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px">
                                <label class="lab" for="resume-text">{{ __('Resume text') }}</label>
                                <button type="button" class="btn btn-sm btn-ghost" data-restore-text="{{ route('resume.restore-text') }}">{{ __('Restore text from PDF') }}</button>
                            </div>
                            <textarea id="resume-text" name="text" required maxlength="50000" style="height:420px;resize:vertical;font-size:13.5px;line-height:1.65">{{ old('text', $resume->text) }}</textarea>
                            <span class="help">{{ __('Claude scores vacancies and writes the CV and cover letter from this text. Edits do not change the uploaded PDF.') }}</span>
                        </div>

                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding-top:16px;border-top:1px solid var(--line-soft)">
                            <button type="submit" class="btn btn-primary">{{ __('Save resume') }}</button>
                            <button type="reset" class="btn btn-ghost">{{ __('Discard changes') }}</button>
                            <span class="faint" style="margin-left:auto;font-size:12.5px">{{ __('New vacancies are scored against the saved text') }}</span>
                        </div>
                    </form>
                @else
                    <div class="empty" style="padding:20px 0 24px">
                        <div style="font-weight:600;color:var(--text);margin-bottom:4px">{{ __('No resume uploaded yet') }}</div>
                        <div class="faint" style="font-size:13px">{{ __('Upload a PDF, Claude will extract your stack and fill in the keywords.') }}</div>
                    </div>
                    <form method="post" action="{{ route('resume.store') }}" enctype="multipart/form-data">
                        @csrf
                        <div class="field">
                            <input type="file" name="resume" accept="application/pdf" required>
                        </div>
                        <div class="stack" style="margin-top:12px">
                            <button type="submit" class="btn btn-primary">{{ __('Upload PDF') }}</button>
                            <span class="faint" style="font-size:12px;max-width:260px">{{ __('After upload Claude extracts your stack keywords (up to a minute).') }}</span>
                        </div>
                    </form>
                @endif
            </div>
        </div>

        <div class="dash-side">
            <div class="card">
                <div class="card-head">
                    <h3>{{ __('Scanning') }}</h3>
                    <span class="badge -neutral" id="run-badge" style="display:none"></span>
                </div>
                <div class="card-body">
                    <p class="muted" style="margin-top:0;font-size:14px">
                        {{ __('Crawls the selected sources and scores vacancies against your resume. Documents are generated only by hand from the vacancy page.') }}
                    </p>
                    <div style="display:flex;gap:10px">
                        <form method="post" action="{{ route('run.start') }}" style="flex:1">
                            @csrf
                            <button type="submit" class="btn btn-primary btn-block" id="start-btn" @unless($resume) disabled data-noresume @endunless>
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
                                {{ __('Start search') }}
                            </button>
                        </form>
                        <form method="post" action="{{ route('run.stop') }}" data-run-stop style="display:none;flex:1">
                            @csrf
                            <button type="submit" class="btn btn-danger btn-block" title="{{ __('The run stops after the current step, vacancies already scored are kept.') }}">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="5" y="5" width="14" height="14" rx="2"/></svg>
                                {{ __('Stop search') }}
                            </button>
                        </form>
                    </div>
                    @unless($resume)<div class="faint" style="font-size:12.5px;text-align:center;margin-top:8px">{{ __('Upload a resume first.') }}</div>@endunless
                    <div id="run-status" class="mono" style="font-size:13px;color:var(--muted);margin-top:14px"></div>
                    <pre class="log" id="run-log" style="display:none;margin-top:10px;height:200px;white-space:pre-wrap"></pre>
                </div>
            </div>

            <div class="card">
                <div class="card-head"><h3>{{ __('Recent runs') }}</h3></div>
                <div>
                    @forelse ($runs as $run)
                        <div class="run-row">
                            <a href="{{ route('runs.show', $run) }}" class="mono" style="font-weight:600">#{{ $run->id }}</a>
                            <span><span class="tag">{{ $run->trigger }}</span></span>
                            <span class="mono faint" style="font-size:12.5px">{{ $run->started_at?->setTimezone('Europe/Berlin')->format('d.m H:i') }}</span>
                            <span class="badge -{{ $run->status }}">{{ $run->status }}</span>
                        </div>
                    @empty
                        <div class="empty">{{ __('No runs yet. Press “:button”.', ['button' => __('Start search')]) }}</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- Per-source breakdown: same rows twice, once by volume and once by what actually matched --}}
    <div class="grid grid-2">
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

    // Keyword tags keep hidden keywords[] inputs, so the form posts them like any other field.
    (function () {
        var box = document.querySelector('[data-keywords]');
        if (!box) return;
        var form = box.closest('form');
        var add = box.querySelector('.kw-add');
        var max = 20;
        var removeLabel = @json(__('Remove :keyword'));
        var cross = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>';

        function values() {
            return Array.prototype.map.call(box.querySelectorAll('input[name="keywords[]"]'), function (i) { return i.value; });
        }
        var initial = values();

        function tag(value) {
            var span = document.createElement('span');
            span.className = 'kw';
            span.textContent = value;
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'keywords[]';
            hidden.value = value;
            var button = document.createElement('button');
            button.type = 'button';
            button.setAttribute('aria-label', removeLabel.replace(':keyword', value));
            button.innerHTML = cross;
            span.appendChild(hidden);
            span.appendChild(button);
            box.insertBefore(span, add);
        }

        function commit() {
            var value = add.value.trim();
            add.value = '';
            var taken = values().map(function (v) { return v.toLowerCase(); });
            if (value === '' || taken.indexOf(value.toLowerCase()) !== -1 || taken.length >= max) return;
            tag(value);
            form.dispatchEvent(new Event('change'));
        }

        add.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); commit(); }
        });
        box.addEventListener('click', function (e) {
            var button = e.target.closest('.kw button');
            if (!button) return;
            button.parentNode.remove();
            form.dispatchEvent(new Event('change'));
            add.focus();
        });
        // A word typed but not confirmed with Enter would otherwise be lost on save.
        form.addEventListener('submit', commit);
        // Form reset does not know about the generated tags, so they are rebuilt here.
        form.addEventListener('reset', function () {
            box.querySelectorAll('.kw').forEach(function (el) { el.remove(); });
            initial.forEach(tag);
        });
    })();

    // Puts the text of the stored PDF into the textarea; it is saved with the form.
    (function () {
        var button = document.querySelector('[data-restore-text]');
        if (!button) return;
        var area = document.getElementById('resume-text');
        button.addEventListener('click', function () {
            button.disabled = true;
            fetch(button.getAttribute('data-restore-text'), {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                },
            }).then(function (r) {
                return r.json().then(function (body) { return { ok: r.ok, body: body }; });
            }).then(function (res) {
                if (!res.ok) throw new Error(res.body.message || '');
                area.value = res.body.text;
                area.dispatchEvent(new Event('input', { bubbles: true }));
            }).catch(function (e) {
                alert(e.message || @json(__('Could not restore the text.')));
            }).finally(function () {
                button.disabled = false;
            });
        });
    })();
</script>
@endsection
