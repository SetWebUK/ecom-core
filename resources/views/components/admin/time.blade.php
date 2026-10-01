{{--
    Dates are stored in UTC and always shown in UK time.
    <x-admin.time :value="$order->created_at" />                   smart: "Today at 10:32", "Yesterday at 09:15", "7 Mar at 06:51"
    <x-admin.time :value="$order->created_at" format="relative" /> "3 days ago"
    format: smart (default) | relative | date ("7 Mar 2026") | datetime ("7 Mar 2026, 06:51") | any PHP date() format
--}}
@props(['value' => null, 'format' => 'smart', 'empty' => '—'])
@php
    $date = \Pine\Commerce\View\Components\Admin\Ui::date($value);
@endphp
@if ($date)
<time datetime="{{ $date->toIso8601String() }}" title="{{ \Pine\Commerce\Services\Admin\LocalTime::format($date, 'l j F Y, H:i') }} (UK time)" {{ $attributes }}>{{ match ($format) {
    'smart' => \Pine\Commerce\View\Components\Admin\Ui::smartDate($date),
    'relative' => $date->diffForHumans(),
    'date' => \Pine\Commerce\Services\Admin\LocalTime::format($date, 'j M Y'),
    'datetime' => \Pine\Commerce\Services\Admin\LocalTime::format($date, 'j M Y, H:i'),
    default => \Pine\Commerce\Services\Admin\LocalTime::format($date, $format),
} }}</time>
@else
<span class="text-subtle" {{ $attributes }}>{{ $empty }}</span>
@endif
