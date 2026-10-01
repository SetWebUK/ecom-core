{{--
    Table header cell; with sort="column" it becomes a link that toggles ?sort=column&direction=asc|desc (page reset).
    <x-admin.th sort="created_at" first="desc">Created</x-admin.th>
    <x-admin.th align="right">Total</x-admin.th>
    Controller side: [$sort, $direction] = $this->sorting($request, ['code', 'created_at'], 'created_at', 'desc');  (trait AdminIndex)
    Props: sort, align (right), first (direction on first click, default asc), class
--}}
@props(['sort' => null, 'align' => null, 'first' => 'asc'])
@php
    $active = $sort && request('sort') === $sort;
    $dir = request('direction') === 'desc' ? 'desc' : 'asc';
    $next = $active ? ($dir === 'asc' ? 'desc' : 'asc') : $first;
@endphp
<th scope="col" {{ $attributes->class(['num' => $align === 'right', 'text-center' => $align === 'center']) }} @if ($sort) aria-sort="{{ $active ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none' }}" @endif>
    @if ($sort)
        <a href="{{ request()->fullUrlWithQuery(['sort' => $sort, 'direction' => $next, 'page' => null]) }}" @class(['th-sort', 'is-sorted' => $active])>
            <span>{{ $slot }}</span>
            <x-admin.icon :name="$active ? ($dir === 'asc' ? 'chevron-up' : 'chevron-down') : 'chevron-up-down'" variant="mini" />
        </a>
    @else
        {{ $slot }}
    @endif
</th>
