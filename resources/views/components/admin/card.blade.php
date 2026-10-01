{{--
    <x-admin.card title="Customer" subtitle="optional">
        <x-slot:actions><x-admin.button size="sm" variant="plain">Edit</x-admin.button></x-slot:actions>
        …body…
        <x-slot:footer>…buttons…</x-slot:footer>
    </x-admin.card>
    <x-admin.card flush> … </x-admin.card>      no body padding (tables, lists, tabs + filters)
    Props: title, subtitle, flush, subdued, sectioned (header underline). Slots: actions, header (replaces title block), footer
--}}
@props(['title' => null, 'subtitle' => null, 'flush' => false, 'subdued' => false, 'sectioned' => false])
<section {{ $attributes->class(['card', 'card--subdued' => $subdued, 'card--flush' => $flush]) }}>
    @if ($title || isset($header) || isset($actions))
        <header @class(['card__header', 'card__header--sep' => $sectioned])>
            @isset($header)
                {{ $header }}
            @else
                <div class="flex-1">
                    <h2 class="card__title">{{ $title }}</h2>
                    @if ($subtitle)<p class="card__subtitle">{{ $subtitle }}</p>@endif
                </div>
            @endisset
            @isset($actions)<div class="card__actions">{{ $actions }}</div>@endisset
        </header>
    @endif
    @if ($flush)
        {{ $slot }}
    @else
        <div class="card__body">{{ $slot }}</div>
    @endif
    @isset($footer)<footer {{ $footer->attributes->class(['card__footer']) }}>{{ $footer }}</footer>@endisset
</section>
