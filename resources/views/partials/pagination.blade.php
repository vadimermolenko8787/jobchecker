@if ($paginator->hasPages())
    <nav class="pagination" role="navigation" aria-label="{{ __('Page navigation') }}">
        @if ($paginator->onFirstPage())
            <span aria-disabled="true" style="opacity:.4">‹</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('Previous') }}">‹</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span aria-disabled="true">{{ $element }}</span>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span aria-current="page"><span>{{ $page }}</span></span>
                    @else
                        <a href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('Next') }}">›</a>
        @else
            <span aria-disabled="true" style="opacity:.4">›</span>
        @endif
    </nav>
@endif
