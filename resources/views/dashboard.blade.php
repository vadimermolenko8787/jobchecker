@extends('layouts.app')

@section('title', 'JobChecker — ' . __('dashboard'))

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
