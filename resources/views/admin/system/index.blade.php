{{-- Settings › System (administrators only): platform version, theme, feature switches, health checks, last import. Read-only. --}}
@extends('commerce::admin.layouts.app')

@section('title', 'System · Settings')

@php
    $badge = ['pass' => ['success', 'OK'], 'warn' => ['warning', 'Check'], 'fail' => ['danger', 'Problem'], 'info' => ['gray', 'Info']];
@endphp

@section('content')
    <x-admin.page-header title="System" subtitle="Platform version, storefront theme, feature switches and health checks. Only administrators can see this page." :back="route('admin.settings.index')" back-label="All settings" />

    <div class="settings-layout">
        @include('commerce::admin.settings._nav', ['active' => 'system'])
        <div class="stack">
            <x-admin.card title="Health checks" subtitle="The same checks as `php artisan commerce:doctor`. Reload the page to run them again.">
                <x-slot:actions>
                    @if ($counts['fail'])
                        <x-admin.badge color="danger" dot>{{ $counts['fail'] }} {{ Str::plural('problem', $counts['fail']) }}</x-admin.badge>
                    @endif
                    @if ($counts['warn'])
                        <x-admin.badge color="warning" dot>{{ $counts['warn'] }} to check</x-admin.badge>
                    @endif
                    @if (! $counts['fail'] && ! $counts['warn'])
                        <x-admin.badge color="success" dot>All good</x-admin.badge>
                    @endif
                </x-slot:actions>

                <div class="stack">
                    @foreach ($checks as $group => $rows)
                        <div>
                            <h3 class="text-sm" style="margin:0 0 6px;font-weight:600">{{ $group }}</h3>
                            <dl class="kv">
                                @foreach ($rows as $check)
                                    <dt><x-admin.badge :color="$badge[$check['status']][0]" size="sm">{{ $badge[$check['status']][1] }}</x-admin.badge> {{ $check['title'] }}</dt>
                                    <dd>
                                        {{ $check['message'] }}
                                        @if ($check['fix'])<span class="text-xs text-muted" style="display:block">Fix: {{ $check['fix'] }}</span>@endif
                                    </dd>
                                @endforeach
                            </dl>
                        </div>
                    @endforeach
                </div>
            </x-admin.card>

            <div class="grid-2">
                <x-admin.card title="Platform">
                    <dl class="kv">
                        @foreach ($platform as $label => $value)
                            <dt>{{ $label }}</dt><dd>{{ $value }}</dd>
                        @endforeach
                    </dl>
                </x-admin.card>

                <x-admin.card title="Storefront theme">
                    <dl class="kv">
                        <dt>Active theme</dt><dd>{{ $theme['name'] ? $theme['name'].' ('.$theme['slug'].')' : $theme['slug'] }}</dd>
                        @if ($theme['version'])<dt>Version</dt><dd>{{ $theme['version'] }}</dd>@endif
                        @if (count($theme['chain']) > 1)<dt>Inherits from</dt><dd>{{ implode(' → ', array_slice($theme['chain'], 1)) }}</dd>@endif
                        @if ($theme['error'])<dt>Error</dt><dd class="text-danger">{{ $theme['error'] }}</dd>@endif
                    </dl>
                    <p class="text-xs text-muted" style="margin:12px 0 0">Chosen with <code>COMMERCE_THEME</code> in <code>.env</code>. Theme files live in <code>themes/{{ $theme['slug'] }}/</code>.</p>
                </x-admin.card>
            </div>

            <x-admin.card flush title="Feature switches" subtitle="Read-only. Set per site in config/commerce.php (features) – a client may read them from .env. A storefront feature the theme does not support stays off.">
                <x-admin.table compact stack>
                    <x-slot:head>
                        <th scope="col">Switch</th><th scope="col">State</th><th scope="col">What it controls</th>
                    </x-slot:head>
                        @foreach ($features as $feature)
                            <tr>
                                <td class="stack-title"><code>{{ $feature['key'] }}</code></td>
                                <td data-label="State" style="white-space:nowrap">
                                    @if ($feature['on'] && $feature['effective'])
                                        <x-admin.badge color="success" size="sm">On</x-admin.badge>
                                    @elseif ($feature['on'])
                                        <x-admin.badge color="warning" size="sm">On, but not supported by the theme</x-admin.badge>
                                    @else
                                        <x-admin.badge color="gray" size="sm">Off</x-admin.badge>
                                    @endif
                                    @if ($feature['default'] !== null && $feature['default'] !== $feature['on'])
                                        <span class="text-xs text-muted" style="display:block">package default: {{ $feature['default'] ? 'on' : 'off' }}</span>
                                    @endif
                                </td>
                                <td data-label="Controls" class="text-sm">{{ $feature['description'] ?? 'Client switch' }}</td>
                            </tr>
                        @endforeach
                </x-admin.table>
            </x-admin.card>

            <x-admin.card title="Last WordPress import" subtitle="Written by `php artisan commerce:import-wordpress` to storage/logs/import-wordpress.log.">
                @if (! $import)
                    <p class="text-sm text-muted" style="margin:0">No import has been run on this site.</p>
                @else
                    <dl class="kv">
                        <dt>Finished</dt><dd>{{ $import['date'] ?? 'Unknown' }}</dd>
                        <dt>Warnings</dt><dd>{{ count($import['warnings']) }}</dd>
                    </dl>
                    @if ($import['summary'])
                        <div class="table-wrap mt-4">
                            <table class="table">
                                <thead><tr><th>What</th><th class="num">WordPress</th><th class="num">Here</th><th>Notes</th></tr></thead>
                                <tbody>
                                    @foreach ($import['summary'] as $label => $row)
                                        <tr>
                                            <td>{{ $label }}</td>
                                            <td class="num">{{ is_array($row) ? ($row['wp'] ?? '') : '' }}</td>
                                            <td class="num">{{ is_array($row) ? ($row['laravel'] ?? '') : $row }}</td>
                                            <td class="text-muted text-sm">{{ is_array($row) ? ($row['note'] ?? '') : '' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    @if ($import['warnings'])
                        <details class="mt-4">
                            <summary class="text-sm">Show the first {{ min(50, count($import['warnings'])) }} warning(s)</summary>
                            <ul class="text-sm" style="margin:8px 0 0;padding-left:18px">
                                @foreach (array_slice($import['warnings'], 0, 50) as $warning)
                                    <li>{{ is_string($warning) ? $warning : json_encode($warning) }}</li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                @endif
            </x-admin.card>
        </div>
    </div>
@endsection
