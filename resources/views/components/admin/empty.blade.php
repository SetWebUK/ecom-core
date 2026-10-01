{{--
    <x-admin.empty icon="receipt-percent" title="No discounts yet" description="Create a code customers can use at checkout.">
        <x-admin.button variant="primary" :href="…">Create discount</x-admin.button>
    </x-admin.empty>
    Props: icon, title, description, size (sm). Slot: action buttons
--}}
@props(['icon' => 'inbox', 'title', 'description' => null, 'size' => null])
<div {{ $attributes->class(['empty', 'empty--sm' => $size === 'sm']) }}>
    <div class="empty__icon"><x-admin.icon :name="$icon" /></div>
    <h3 class="empty__title">{{ $title }}</h3>
    @if ($description)<p class="empty__text">{{ $description }}</p>@endif
    @if ($slot->isNotEmpty())<div class="empty__actions">{{ $slot }}</div>@endif
</div>
