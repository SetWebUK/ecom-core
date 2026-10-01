{{--
    Default paginator view (Paginator::defaultView('partials.pagination')): WordPress-style links – page 1 is the base
    URL, page N is {base}/page/N/, other query parameters are kept.
--}}
@if ($paginator->hasPages())
    @php
        $S = \Pine\Commerce\Theme\Storefront::class;
        $base = preg_replace('#/page/\d+/?$#', '', rtrim($paginator->path(), '/'));
        $legacy = (array) config('commerce.catalog.legacy_page_params', []);
        $query = collect(request()->query())->except(array_merge([$paginator->getPageName()], $legacy))->all();
        $qs = $query ? '?'.http_build_query($query) : '';
        $pageUrl = fn (int $page) => ($page <= 1 ? $base.'/' : $base.'/page/'.$page.'/').$qs;
    @endphp
    <nav class="pagination" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span class="pagination__link pagination__link--edge is-disabled" aria-hidden="true">{!! $S::icon('chevron-left', 18) !!}</span>
        @else
            <a class="pagination__link pagination__link--edge" href="{{ $pageUrl($paginator->currentPage() - 1) }}" rel="prev" aria-label="Previous page">{!! $S::icon('chevron-left', 18) !!}</a>
        @endif
        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="pagination__dots">&hellip;</span>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="pagination__link is-current" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="pagination__link" href="{{ $pageUrl((int) $page) }}" aria-label="Page {{ $page }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach
        @if ($paginator->hasMorePages())
            <a class="pagination__link pagination__link--edge" href="{{ $pageUrl($paginator->currentPage() + 1) }}" rel="next" aria-label="Next page">{!! $S::icon('chevron-right', 18) !!}</a>
        @else
            <span class="pagination__link pagination__link--edge is-disabled" aria-hidden="true">{!! $S::icon('chevron-right', 18) !!}</span>
        @endif
    </nav>
@endif
