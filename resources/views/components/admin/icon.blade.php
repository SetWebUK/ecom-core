{{--
    <x-admin.icon name="pencil-square" />                       Heroicon (outline 24px) – any name from heroicons.com
    <x-admin.icon name="check" variant="mini" size="sm" />      mini = 20px solid set
    <x-admin.icon name="trash" label="Delete" />                label -> announced to screen readers (otherwise aria-hidden)
    Props: name, variant (outline|mini), size (xs|sm|md|lg|xl), label
--}}
@props(['name', 'variant' => 'outline', 'size' => null, 'label' => null])
@php
    $inner = \Pine\Commerce\View\Components\Admin\IconSet::svg($name, $variant) ?? \Pine\Commerce\View\Components\Admin\IconSet::svg('question-mark-circle', $variant);
    $mini = $variant === 'mini' || $variant === 'solid';
@endphp
<svg {{ $attributes->class(['icon', 'icon--'.$size => $size && $size !== 'md']) }} xmlns="http://www.w3.org/2000/svg" @if ($mini) viewBox="0 0 20 20" fill="currentColor" @else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" @endif @if ($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" focusable="false" @endif>{!! $inner !!}</svg>
