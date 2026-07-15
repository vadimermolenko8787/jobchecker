@php
    $s = $score;
    $lg = $lg ?? false;
    $color = $s === null ? 'var(--faint)'
        : ($s >= 80 ? 'var(--ok)' : ($s >= 60 ? 'var(--signal)' : ($s >= 40 ? 'var(--info)' : 'var(--neutral)')));
    $w = $s === null ? 0 : max(4, min(100, (int) $s));
@endphp
<span class="meter {{ $lg ? '-lg' : '' }} {{ $s === null ? '-none' : '' }}" title="Match score">
    <span class="val" style="color: {{ $color }}">{{ $s ?? '—' }}</span>
    <span class="track"><span class="fill" style="width: {{ $w }}%; background: {{ $color }}"></span></span>
</span>
