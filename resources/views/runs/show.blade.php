@extends('layouts.app')

@section('title', 'Запуск #' . $run->id)

@section('content')
    <div style="margin-bottom:18px">
        <a href="{{ route('dashboard') }}" class="muted" style="font-size:13px">← на панель</a>
    </div>

    <div class="page-head">
        <div>
            <div class="eyebrow">Запуск</div>
            <h1 style="display:flex;align-items:center;gap:12px">
                <span class="mono">#{{ $run->id }}</span>
                <span class="badge -{{ $run->status }}">{{ $run->status }}</span>
            </h1>
            <div class="sub stack" style="margin-top:8px">
                <span class="tag">{{ $run->trigger }}</span>
                <span>начало {{ $run->started_at?->format('d.m.Y H:i:s') }}</span>
                <span class="faint">·</span>
                <span>конец {{ $run->finished_at?->format('d.m.Y H:i:s') ?? '—' }}</span>
            </div>
        </div>
    </div>

    @if ($run->stats)
        <div class="grid grid-4" style="margin-bottom:20px">
            @foreach ($run->stats as $k => $v)
                @if (!is_array($v))
                    <div class="stat"><div class="l">{{ $k }}</div><div class="n">{{ $v }}</div></div>
                @endif
            @endforeach
        </div>
    @endif

    <div class="card">
        <div class="card-head"><h3>Лог пайплайна</h3></div>
        <div class="card-body"><pre class="log" style="max-height:500px">{{ $run->log }}</pre></div>
    </div>

    <div class="card">
        <div class="card-head">
            <h3>HTTP-запросы к источникам</h3>
            <span class="hint">{{ $fetchLogs->count() }}</span>
        </div>
        <div class="card-body" style="padding:0">
            <div class="table-wrap" style="border:none;border-radius:0">
                <table class="data">
                    <thead><tr><th>#</th><th>Источник</th><th>Запрос</th><th>Статус</th><th>мс</th></tr></thead>
                    <tbody>
                    @foreach ($fetchLogs as $log)
                        <tr>
                            <td class="mono faint">{{ $log->id }}</td>
                            <td><span class="tag">{{ $log->source }}</span></td>
                            <td style="max-width:520px">
                                <details class="box" style="margin:0;border:none">
                                    <summary style="padding:0;color:var(--text)"><span class="mono" style="font-size:12.5px">{{ $log->request['method'] ?? 'GET' }} {{ Str::limit($log->request['url'] ?? '', 78) }}</span></summary>
                                    <div class="details-body" style="padding:12px 0 0">
                                        <div class="faint" style="font-size:12px;margin-bottom:4px">Запрос</div>
                                        <pre class="log">{{ json_encode($log->request, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                        @if ($log->error)
                                            <div style="color:var(--danger);font-size:13px;margin-top:10px"><b>Ошибка:</b> {{ $log->error }}</div>
                                        @endif
                                        <div class="faint" style="font-size:12px;margin:10px 0 4px">Ответ ({{ Str::length($log->response_body ?? '') }} байт)</div>
                                        <pre class="log">{{ Str::limit($log->response_body ?? '', 20000) }}</pre>
                                    </div>
                                </details>
                            </td>
                            <td>
                                @php $st = $log->response_status; @endphp
                                <span class="badge {{ $st === null ? '-neutral' : ($st < 300 ? '-ok' : ($st < 400 ? '-running' : '-failed')) }}">{{ $st ?? '—' }}</span>
                            </td>
                            <td class="mono faint">{{ $log->duration_ms }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
