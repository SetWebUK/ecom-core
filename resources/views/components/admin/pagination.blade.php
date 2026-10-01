{{--
    <x-admin.pagination :paginator="$coupons" />      "Showing 1–25 of 312", page links, per-page picker (?per_page=25|50|100)
    Use ->paginate($this->perPage($request))->withQueryString() in the controller (trait AdminIndex).
    Props: paginator, per-page (bool, default true)
--}}
@props(['paginator', 'perPage' => true])
@php
    $isLengthAware = $paginator instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator;
@endphp
@if ($paginator->count() > 0 && ($paginator->hasPages() || $isLengthAware))
    <div {{ $attributes->class(['pagination']) }}>
        <div class="pagination__info">
            @if ($isLengthAware)
                Showing {{ number_format($paginator->firstItem()) }}–{{ number_format($paginator->lastItem()) }} of {{ number_format($paginator->total()) }}
            @else
                Page {{ $paginator->currentPage() }}
            @endif
        </div>
        {{ $paginator->onEachSide(1)->links('commerce::admin.partials.pagination') }}
        @if ($perPage && $isLengthAware && $paginator->total() > 25)
            <form method="GET" class="per-page hidden-mobile" x-data @change="$el.requestSubmit()" data-no-loading>
                @foreach (\Pine\Commerce\View\Components\Admin\Ui::queryPairs(['page', 'per_page']) as [$qName, $qValue])
                    <input type="hidden" name="{{ $qName }}" value="{{ $qValue }}">
                @endforeach
                <label for="per-page">Per page</label>
                <select name="per_page" id="per-page" class="select">
                    @foreach ([25, 50, 100] as $size)
                        <option value="{{ $size }}" @selected($paginator->perPage() === $size)>{{ $size }}</option>
                    @endforeach
                </select>
                <noscript><button type="submit" class="btn btn--sm">Go</button></noscript>
            </form>
        @endif
    </div>
@endif
