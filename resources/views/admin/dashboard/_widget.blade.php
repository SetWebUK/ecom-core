{{-- One client dashboard card (Commerce::dashboardWidget()). $key, $widget = [title, subtitle, view, data, wide] --}}
@if ($widget['title'] !== null)
    <x-admin.card :title="$widget['title']" :subtitle="$widget['subtitle']" data-dashboard-widget="{{ $key }}">
        @include($widget['view'], $widget['data'])
    </x-admin.card>
@else
    <div data-dashboard-widget="{{ $key }}">@include($widget['view'], $widget['data'])</div>
@endif
