{{--
    <x-admin.form :action="route('admin.coupons.update', $coupon)" method="PUT" dirty> … </x-admin.form>
    - adds @csrf and the method spoof (PUT/PATCH/DELETE)
    - dirty: warns before leaving with unsaved changes and shows the Shopify-style "Unsaved changes" bar
      (Discard / Save) at the top of the screen. Set :savebar="false" to keep the warning without the bar.
    - files: multipart/form-data
    Props: action, method, dirty, savebar, files, save-label, id
--}}
@props(['action', 'method' => 'POST', 'dirty' => false, 'savebar' => true, 'files' => false, 'saveLabel' => 'Save', 'id' => null])
@php
    $method = strtoupper($method);
    $formMethod = $method === 'GET' ? 'GET' : 'POST';
    $id ??= 'form-'.substr(md5($action.$method), 0, 8);
@endphp
<form {{ $attributes->merge(['id' => $id, 'method' => $formMethod, 'action' => $action]) }} @if ($files) enctype="multipart/form-data" @endif @if ($dirty) x-data="dirtyForm" @endif>
    @if ($formMethod === 'POST')
        @csrf
    @endif
    @if (in_array($method, ['PUT', 'PATCH', 'DELETE'], true))
        @method($method)
    @endif
    {{ $slot }}
    @if ($dirty && $savebar)
        <div class="savebar" x-show="dirty" x-cloak x-transition.opacity.duration.150ms role="region" aria-label="Unsaved changes">
            <div class="savebar__msg"><x-admin.icon name="exclamation-triangle" size="sm" /> Unsaved <span>changes</span></div>
            <div class="savebar__actions">
                <button type="button" class="btn btn--ghost" @click="discard()">Discard</button>
                <button type="submit" class="btn btn--primary"><span>{{ $saveLabel }}</span></button>
            </div>
        </div>
    @endif
</form>
