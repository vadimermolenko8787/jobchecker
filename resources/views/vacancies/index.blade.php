@extends('layouts.app')

@section('title', 'Вакансии')
@section('page-class', '-wide')

@php
    $activeFilters = array_filter($filters);
    // Every link on the page has to carry the filters, or one click would silently drop them.
    $filterQuery = array_filter(['status' => $status] + $filters);
    $sortLink = fn (string $col) => route('vacancies.index', $filterQuery + [
        'sort' => $col,
        'dir' => $sort === $col && $dir === 'desc' ? 'asc' : 'desc',
    ]);
    // What survives a filter reset. Default sort/dir are dropped so URLs keep only
    // what the user actually chose.
    $carried = array_filter([
        'status' => $status,
        'sort' => $sort === 'date' ? null : $sort,
        'dir' => $dir === 'desc' ? null : $dir,
    ]);
@endphp

@section('content')
    <div class="page-head">
        <div>
            <div class="eyebrow">
                {{ $vacancies->total() }} записей
                @if ($activeFilters)<span style="color:var(--signal)">· по фильтру</span>@endif
            </div>
            <h1>Вакансии</h1>
            <div class="sub">Отсортированы по совпадению с вашим резюме.</div>
        </div>
    </div>

    <div class="toolbar">
        <div class="segment">
            @php
                $tabs = [
                    '' => ['все', 'Все вакансии'],
                    'new' => ['new', 'Из последнего запуска'],
                    'matched' => ['matched', 'Прошли порог совпадения'],
                    'done' => ['done', 'С готовыми документами'],
                    'rejected' => ['rejected', 'Ниже порога совпадения'],
                ];
            @endphp
            @foreach ($tabs as $key => [$label, $hint])
                <a href="{{ route('vacancies.index', array_filter(['status' => $key] + $filters)) }}"
                   title="{{ $hint }}"
                   @class(['is-active' => $status === ($key ?: null)])>{{ $label }}</a>
            @endforeach
        </div>
        @if ($activeFilters)
            <a class="btn btn-sm" href="{{ route('vacancies.index', $carried) }}">Сбросить фильтры</a>
        @endif
    </div>

    @php
        $columns = [
            'score' => 'Score', 'title' => 'Вакансия', 'company' => 'Компания',
            'source' => 'Источник', 'location' => 'Локация', 'status' => 'Статус',
            'date' => 'Дата', 'applied' => 'Подача',
        ];
    @endphp

    {{-- The inputs live in the <thead> cells and reach this form by id: a <form> cannot wrap table rows. --}}
    <form method="get" action="{{ route('vacancies.index') }}" id="vac-filters" data-vac-filters>
        @foreach ($carried as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
    </form>

    <div class="table-wrap">
        <table class="data -filterable">
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
            <tr class="-filters">
                <th></th>
                <th>
                    <input type="text" class="f-in" form="vac-filters" name="q" value="{{ $filters['q'] }}" size="1"
                           placeholder="название…" aria-label="Фильтр по названию вакансии" autocomplete="off">
                </th>
                <th>
                    <input type="text" class="f-in" form="vac-filters" name="company" value="{{ $filters['company'] }}" size="1"
                           placeholder="компания…" aria-label="Фильтр по компании" autocomplete="off" list="company-options">
                    <datalist id="company-options">
                        @foreach ($companies as $companyName)
                            <option value="{{ $companyName }}"></option>
                        @endforeach
                    </datalist>
                </th>
                <th>
                    {{-- No sources yet means an empty table, and an empty dropdown to open. --}}
                    @if (count($sources))
                        {{-- The button is named by its own summary text, so no aria-label here:
                             it would hide the current selection from screen readers. --}}
                        <div class="multiselect -sm -fixed" data-multiselect data-empty="источник…">
                            <button type="button" class="ms-toggle" aria-expanded="false" aria-haspopup="true">
                                <span class="ms-summary"></span><span class="ms-caret">▼</span>
                            </button>
                            <div class="ms-panel chip-row">
                                @foreach ($sources as $sourceName)
                                    <label class="chip"><input type="checkbox" form="vac-filters" name="source[]" value="{{ $sourceName }}"
                                                               @checked(in_array($sourceName, $filters['source'], true))><span class="dot"></span>{{ $sourceName }}</label>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </th>
                <th></th>
                <th></th>
                <th>
                    <div class="f-dates">
                        {{-- No min/max between the two: it would block moving the window forward,
                             and an inverted range already explains itself via the empty state. --}}
                        <input type="date" class="f-in" form="vac-filters" name="from" value="{{ $filters['from'] }}"
                               title="Дата от" aria-label="Дата от">
                        <input type="date" class="f-in" form="vac-filters" name="to" value="{{ $filters['to'] }}"
                               title="Дата до" aria-label="Дата до">
                    </div>
                </th>
                <th></th>
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
                <tr><td colspan="8"><div class="empty">
                    @if ($activeFilters)
                        Ничего не найдено по заданным фильтрам.
                    @else
                        Пока пусто. Запустите поиск на панели.
                    @endif
                </div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $vacancies->links('partials.pagination') }}
@endsection

@section('scripts')
    <script>
        // Auto-applies the inline table filters; without JS the form still submits on Enter.
        (function () {
            var form = document.querySelector('[data-vac-filters]');
            if (!form) return;
            var fields = Array.prototype.slice.call(document.querySelectorAll('[form="' + form.id + '"]'));
            var key = 'jc-vac-filter-focus';
            var timer = null;

            function submit(field) {
                clearTimeout(timer);
                try {
                    sessionStorage.setItem(key, JSON.stringify({
                        name: field.name,
                        caret: field.type === 'text' ? field.value.length : null,
                    }));
                } catch (e) {}
                // Disabled fields are left out of the query string, so empty ones
                // do not pile up in the URL as "company=&from=&to=".
                fields.forEach(function (f) { if (!f.value) f.disabled = true; });
                form.requestSubmit ? form.requestSubmit() : form.submit();
            }

            fields.forEach(function (field) {
                var initial = field.value;
                if (field.type === 'checkbox') {
                    field.addEventListener('change', function () {
                        clearTimeout(timer);
                        // Debounced too: ticking two sources costs one reload, not two.
                        timer = setTimeout(function () { submit(field); }, 600);
                    });
                    return;
                }
                if (field.type === 'date') {
                    field.addEventListener('change', function () {
                        if (field.value !== initial) submit(field);
                    });
                    return;
                }
                field.addEventListener('input', function () {
                    clearTimeout(timer);
                    // Debounced so a page load does not fire on every keystroke.
                    timer = setTimeout(function () {
                        if (field.value !== initial) submit(field);
                    }, 450);
                });
            });

            // Reloading throws away focus, so put the caret back where the user left it.
            var saved = null;
            try {
                saved = JSON.parse(sessionStorage.getItem(key) || 'null');
                sessionStorage.removeItem(key);
            } catch (e) {}
            if (saved && saved.name) {
                var target = fields.filter(function (f) { return f.name === saved.name; })[0];
                // The checkbox itself is invisible inside its chip, and a closed panel would mean
                // reopening the dropdown for every next source, so restore the open panel instead.
                var dropdown = target && target.closest('[data-multiselect]');
                if (dropdown) {
                    var msToggle = dropdown.querySelector('.ms-toggle');
                    msToggle.focus();
                    if (!dropdown.classList.contains('-open')) msToggle.click();
                } else if (target) {
                    target.focus();
                    if (saved.caret !== null && target.setSelectionRange) {
                        try { target.setSelectionRange(saved.caret, saved.caret); } catch (e) {}
                    }
                }
            }
        })();
    </script>
@endsection
