@extends('layouts.app')

@section('title', 'Вакансии')

@section('content')
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ $vacancies->total() }} записей</div>
            <h1>Вакансии</h1>
            <div class="sub">Отсортированы по совпадению с вашим резюме.</div>
        </div>
    </div>

    <div class="toolbar">
        <div class="segment">
            @foreach (['' => 'все', 'new' => 'new', 'matched' => 'matched', 'done' => 'done', 'rejected' => 'rejected'] as $key => $label)
                <a href="{{ route('vacancies.index', $key ? ['status' => $key] : []) }}"
                   @class(['is-active' => $status === ($key ?: null)])>{{ $label }}</a>
            @endforeach
        </div>
    </div>

    @php
        $columns = [
            'score' => 'Score', 'title' => 'Вакансия', 'company' => 'Компания',
            'source' => 'Источник', 'location' => 'Локация', 'status' => 'Статус',
            'date' => 'Дата', 'applied' => 'Подача',
        ];
        $sortLink = fn (string $col) => route('vacancies.index', array_filter([
            'status' => $status,
            'sort' => $col,
            'dir' => $sort === $col && $dir === 'desc' ? 'asc' : 'desc',
        ]));
    @endphp

    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr>
                @foreach ($columns as $col => $label)
                    <th>
                        <a href="{{ $sortLink($col) }}">
                            {{ $label }}
                            @if ($sort === $col)<span style="color:var(--signal)">{!! $dir === 'desc' ? '↓' : '↑' !!}</span>@endif
                        </a>
                    </th>
                @endforeach
            </tr>
            </thead>
            <tbody>
            @forelse ($vacancies as $vacancy)
                <tr @class(['-new' => $lastRunId && $vacancy->run_id === $lastRunId])>
                    <td>@include('partials.meter', ['score' => $vacancy->score])</td>
                    <td style="max-width:320px">
                        <a href="{{ route('vacancies.show', $vacancy) }}" class="v-title">{{ Str::limit($vacancy->title, 72) }}</a>
                        @if ($lastRunId && $vacancy->run_id === $lastRunId)<span class="badge -new" style="margin-left:8px">new</span>@endif
                    </td>
                    <td class="muted">{{ Str::limit($vacancy->company, 30) ?: '—' }}</td>
                    <td><span class="tag">{{ $vacancy->source }}</span></td>
                    <td class="faint" style="font-size:13px;max-width:180px">{{ Str::limit($vacancy->location, 34) ?: '—' }}</td>
                    <td><span class="badge -{{ $vacancy->status }}">{{ $vacancy->status }}</span></td>
                    <td class="mono faint nowrap" style="font-size:12.5px">{{ ($vacancy->published_at ?? $vacancy->created_at)?->format('d.m.Y') }}</td>
                    <td class="nowrap">
                        @if ($vacancy->applied_at)
                            <span class="badge -solid" title="Снять отметку можно на странице вакансии">✓ {{ $vacancy->applied_at->format('d.m.Y') }}</span>
                        @else
                            <form method="post" action="{{ route('vacancies.applied', $vacancy) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm">Подал</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8"><div class="empty">Пока пусто. Запустите поиск на панели.</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $vacancies->links('partials.pagination') }}
@endsection
