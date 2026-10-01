{{--
    Date/time in UK time (values are stored in UTC). Convert the submitted value back with
    Pine\Commerce\Services\Admin\LocalTime::fromInput($request->input('starts_at'))  -> Carbon (UTC) or null.
    <x-admin.datetime name="starts_at" label="Starts" :value="$coupon->starts_at" />
    <x-admin.datetime name="published_on" label="Date" type="date" :value="$post->published_at" />
    Props: name, label, value (Carbon|string in UTC), type (datetime|date), help, required, optional, id, error, wrapper
--}}
@props(['name', 'label' => null, 'value' => null, 'type' => 'datetime', 'help' => null, 'required' => false, 'optional' => false, 'id' => null, 'error' => null, 'wrapper' => null])
@php
    $date = \Pine\Commerce\View\Components\Admin\Ui::date($value);
    $formatted = $date ? \Pine\Commerce\Services\Admin\LocalTime::format($date, $type === 'date' ? 'Y-m-d' : 'Y-m-d\TH:i') : null;
@endphp
<x-admin.input :name="$name" :label="$label" :value="$formatted" :type="$type === 'date' ? 'date' : 'datetime-local'" :help="$help ?? 'UK time'"
               :required="$required" :optional="$optional" :id="$id" :error="$error" :wrapper="$wrapper" {{ $attributes }} />
