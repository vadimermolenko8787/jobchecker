@extends('layouts.app')

@section('title', __('Run #:id', ['id' => $run->id]))

@section('content')
    <div style="margin-bottom:18px">
        <a href="{{ route('dashboard') }}" class="muted" style="font-size:13px">← {{ __('back to dashboard') }}</a>
    </div>

    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Run') }}</div>
            <h1 style="display:flex;align-items:center;gap:12px">
                <span class="mono">#{{ $run->id }}</span>
                <span class="badge -{{ $run->status }}">{{ $run->status }}</span>
            </h1>
            <div class="sub stack" style="margin-top:8px">
                <span class="tag">{{ $run->trigger }}</span>
                <span>{{ __('started :time', ['time' => $run->started_at?->format('d.m.Y H:i:s')]) }}</span>
                <span class="faint">·</span>
                <span>{{ __('finished :time', ['time' => $run->finished_at?->format('d.m.Y H:i:s') ?? '—']) }}</span>
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
        <div class="card-head"><h3>{{ __('Pipeline log') }}</h3></div>
        <div class="card-body"><pre class="log" style="max-height:500px">{{ $run->log }}</pre></div>
    </div>

    <div class="card">
        <div class="card-head">
            <h3>{{ __('HTTP requests to sources') }}</h3>
            <span class="hint">{{ $fetchLogs->count() }}</span>
        </div>
        <div class="card-body" style="padding:0">
            <div class="table-wrap" style="border:none;border-radius:0">
                <table class="data">
                    <thead><tr><th>#</th><th>{{ __('Source') }}</th><th>{{ __('Request') }}</th><th>{{ __('Status') }}</th><th>{{ __('ms') }}</th></tr></thead>
                    <tbody>
                    @foreach ($fetchLogs as $log)
                        <tr>
                            <td class="mono faint">{{ $log->id }}</td>
                            <td><span class="tag">{{ $log->source }}</span></td>
                            <td style="max-width:520px">
                                <details class="box" style="margin:0;border:none">
                                    <summary style="padding:0;color:var(--text)"><span class="mono" style="font-size:12.5px">{{ $log->request['method'] ?? 'GET' }} {{ Str::limit($log->request['url'] ?? '', 78) }}</span></summary>
                                    <div class="details-body" style="padding:12px 0 0">
                                        <div class="faint" style="font-size:12px;margin-bottom:4px">{{ __('Request') }}</div>
                                        <pre class="log">{{ json_encode($log->request, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                        @if ($log->error)
                                            <div style="color:var(--danger);font-size:13px;margin-top:10px"><b>{{ __('Error:') }}</b> {{ $log->error }}</div>
                                        @endif
                                        <div class="faint" style="font-size:12px;margin:10px 0 4px">{{ __('Response (:bytes bytes)', ['bytes' => Str::length($log->response_body ?? '')]) }}</div>
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
