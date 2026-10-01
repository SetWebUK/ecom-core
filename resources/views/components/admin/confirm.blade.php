{{--
    A button that submits a small form after a confirmation dialog – use for every destructive action.
    <x-admin.confirm :action="route('admin.coupons.destroy', $coupon)" title="Delete this discount?"
                     message="Customers will no longer be able to use SAVE10. This can’t be undone." confirm-label="Delete discount"
                     variant="ghost-danger" icon="trash">Delete discount</x-admin.confirm>
    Inside <x-admin.dropdown>: add as="menu-item".
    Props: action, method (DELETE default | POST | PUT | PATCH), title, message, confirm-label, danger (bool, default true),
           variant (button look, default secondary), size, icon, label (aria-label for icon-only), as (button|menu-item)
    Slot "fields": extra hidden inputs.
--}}
@props(['action', 'method' => 'DELETE', 'title' => 'Are you sure?', 'message' => null, 'confirmLabel' => 'Delete', 'danger' => true,
        'variant' => 'secondary', 'size' => null, 'icon' => null, 'label' => null, 'as' => 'button'])
@php $method = strtoupper($method); @endphp
<form method="POST" action="{{ $action }}" style="display:contents"
      data-confirm="{{ $message ?? $title }}" @if ($message) data-confirm-title="{{ $title }}" @endif
      data-confirm-button="{{ $confirmLabel }}" data-confirm-danger="{{ $danger ? 'true' : 'false' }}">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif
    {{ $fields ?? '' }}
    @if ($as === 'menu-item')
        <button type="submit" {{ $attributes->class(['dropdown__item', 'dropdown__item--danger' => $danger]) }} role="menuitem">
            @if ($icon)<x-admin.icon :name="$icon" />@endif{{ $slot }}
        </button>
    @else
        <x-admin.button type="submit" :variant="$variant" :size="$size" :icon="$icon" :label="$label" {{ $attributes }}>{{ $slot }}</x-admin.button>
    @endif
</form>
