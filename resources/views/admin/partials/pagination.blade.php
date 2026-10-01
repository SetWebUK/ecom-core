{{-- Admin paginator view: $paginator->links('commerce::admin.partials.pagination') – usually via <x-admin.pagination :paginator="$rows" />. --}}
@if ($paginator->hasPages())
    <nav class="pagination__pages" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span class="btn btn--sm btn--icon is-disabled" aria-disabled="true" aria-label="Previous page"><x-admin.icon name="chevron-left" size="sm" /></span>
        @else
            <a class="btn btn--sm btn--icon" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Previous page"><x-admin.icon name="chevron-left" size="sm" /></a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="pagination__gap hidden-mobile">…</span>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="pagination__page is-current" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="pagination__page hidden-mobile" href="{{ $url }}" aria-label="Page {{ $page }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a class="btn btn--sm btn--icon" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next page"><x-admin.icon name="chevron-right" size="sm" /></a>
        @else
            <span class="btn btn--sm btn--icon is-disabled" aria-disabled="true" aria-label="Next page"><x-admin.icon name="chevron-right" size="sm" /></span>
        @endif
    </nav>
@endif
