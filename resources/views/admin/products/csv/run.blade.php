{{-- Product import steps 2 + 3: dry run / import progress (batches driven by this page), then the preview or the report. --}}
@extends('commerce::admin.layouts.app', ['width' => 'wide'])

@php
    $phase = $session->phase();
    $dry = $session->isDryRun();
    $counts = $status['counts'];
    $warnings = $status['warnings'];
    $willWrite = $counts['create'] + $counts['update'];
    $badges = ['create' => ['New', 'success'], 'update' => ['Update', 'info'], 'skip' => ['Skipped', 'gray'], 'error' => ['Error', 'danger']];
    if (! $dry) {
        $badges['create'][0] = 'Created';
        $badges['update'][0] = 'Updated';
    }
    $title = match (true) {
        $status['running'] && $dry => 'Checking the file…',
        $status['running'] => 'Importing…',
        $dry => 'Check the dry run',
        default => 'Import finished',
    };
@endphp

@section('title', $title.' · Import products')

@section('content')
    <x-admin.page-header :title="$title" :back="route('admin.products.csv')" back-label="Import / Export"
                         :subtitle="$session->state['filename'].' · '.number_format($status['total']).' rows'.($dry ? ' – nothing has been saved yet.' : '')" />

    @if ($status['running'])
        <div x-data="productImport({{ Js::from(['url' => route('admin.products.csv.step', $session->token), 'status' => $status]) }})">
        <x-admin.card>
            <div class="stack">
                <div class="row row--between">
                    <strong x-text="label()"></strong>
                    <span class="text-sm text-muted"><span x-text="status.processed.toLocaleString()"></span> of <span x-text="status.total.toLocaleString()"></span> rows · <span x-text="status.percent"></span>%</span>
                </div>
                <div class="progress" role="progressbar" aria-label="Progress" :aria-valuenow="status.percent" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress__bar" :style="`width: ${status.percent}%`"></div>
                </div>
                <p class="text-sm text-muted">
                    <span x-text="status.counts.create"></span> {{ $dry ? 'new' : 'created' }} ·
                    <span x-text="status.counts.update"></span> {{ $dry ? 'to update' : 'updated' }} ·
                    <span x-text="status.counts.skip"></span> skipped ·
                    <span x-text="status.counts.error"></span> with errors
                </p>
                <template x-if="error"><x-admin.callout type="danger"><span x-text="error"></span></x-admin.callout></template>
                <div class="row">
                    <x-admin.button x-show="!paused" x-on:click="pause()" icon="pause">Pause</x-admin.button>
                    <x-admin.button x-show="paused" x-cloak x-on:click="resume()" variant="primary" icon="play">Continue</x-admin.button>
                    <span class="text-xs text-muted">Keep this page open. If it closes, come back to Products › Import / Export to continue where it stopped.</span>
                </div>
            </div>
        </x-admin.card>
        </div>
    @else
        <div class="stats mb-4">
            <x-admin.stat :label="$dry ? 'New products / variants' : 'Created'" :value="number_format($counts['create'])" icon="plus-circle" />
            <x-admin.stat :label="$dry ? 'Will be updated' : 'Updated'" :value="number_format($counts['update'])" icon="arrow-path" />
            <x-admin.stat label="Skipped" :value="number_format($counts['skip'])" icon="minus-circle" />
            <x-admin.stat label="Rows with errors" :value="number_format($counts['error'])" icon="exclamation-triangle" />
        </div>

        @if ($dry && $counts['error'] > 0)
            <x-admin.callout type="warning" class="mb-4">Rows with errors are not imported. Fix them in the file and upload it again, or import the rest now.</x-admin.callout>
        @endif

        <x-admin.card flush>
            <x-admin.status-tabs param="show" default="all" :current="$show" :tabs="[
                'all' => ['label' => 'All rows', 'count' => array_sum($counts)],
                'create' => ['label' => $dry ? 'New' : 'Created', 'count' => $counts['create']],
                'update' => ['label' => $dry ? 'Updates' : 'Updated', 'count' => $counts['update']],
                'skip' => ['label' => 'Skipped', 'count' => $counts['skip']],
                'error' => ['label' => 'Errors', 'count' => $counts['error']],
                'warnings' => ['label' => 'Warnings', 'count' => $warnings],
            ]" />
            @if (! $rows)
                <x-admin.empty icon="check-circle" title="Nothing to show" description="No rows in this group." size="sm" />
            @else
                <x-admin.table stack compact>
                    <x-slot:head>
                        <x-admin.th>Line</x-admin.th>
                        <x-admin.th>Result</x-admin.th>
                        <x-admin.th>SKU</x-admin.th>
                        <x-admin.th>Product</x-admin.th>
                        <x-admin.th>Notes</x-admin.th>
                    </x-slot:head>
                    @foreach ($rows as $row)
                        @php [$badge, $color] = $badges[$row['action']] ?? [$row['action'], 'gray']; @endphp
                        <tr>
                            <td class="text-muted" data-label="Line">{{ $row['line'] }}</td>
                            <td data-label="Result"><x-admin.badge :color="$color">{{ $badge }}</x-admin.badge></td>
                            <td class="mono stack-title">{{ $row['sku'] !== '' ? $row['sku'] : '—' }}</td>
                            <td data-label="Product">
                                @if (! $dry && ($row['kind'] ?? '') === 'product' && ! empty($row['id']) && in_array($row['action'], ['create', 'update'], true))
                                    <a href="{{ route('admin.products.edit', $row['id']) }}">{{ $row['name'] ?: '#'.$row['id'] }}</a>
                                @else
                                    {{ $row['name'] ?: '—' }}
                                @endif
                                @if (($row['kind'] ?? '') === 'variation')<x-admin.badge size="sm" color="outline">Variant</x-admin.badge>@endif
                            </td>
                            <td data-label="Notes" class="text-sm">
                                @foreach ((array) ($row['messages'] ?? []) as $message)
                                    <div @class(['text-danger' => $row['action'] === 'error', 'text-muted' => $row['action'] !== 'error'])>{{ $message }}</div>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
                @if ($hidden)<p class="text-xs text-muted" style="padding:12px 16px">…and {{ number_format($hidden) }} more rows – download the report to see them all.</p>@endif
            @endif
        </x-admin.card>

        <div class="form-actions">
            <x-admin.button :href="route('admin.products.csv.report', [$session->token, 'pass' => $dry ? 'preview' : 'import'])" icon="arrow-down-tray" data-no-loading>
                Download {{ $dry ? 'dry-run' : 'import' }} report
            </x-admin.button>
            <span class="flex-1"></span>
            @if ($dry)
                <x-admin.button :href="route('admin.products.csv.mapping', $session->token)" icon="adjustments-horizontal">Change columns / options</x-admin.button>
                @if ($willWrite > 0)
                    <form method="POST" action="{{ route('admin.products.csv.start', $session->token) }}"
                          data-confirm-title="Import {{ number_format($willWrite) }} {{ Str::plural('row', $willWrite) }}?"
                          data-confirm="{{ $counts['create'] }} new and {{ $counts['update'] }} updated products/variants are saved in the shop straight away. Rows with errors are skipped."
                          data-confirm-button="Import now" data-confirm-danger="false">
                        @csrf
                        <x-admin.button type="submit" variant="primary" icon="arrow-up-tray">Import {{ number_format($willWrite) }} {{ Str::plural('row', $willWrite) }}</x-admin.button>
                    </form>
                @else
                    <x-admin.button variant="primary" disabled>Nothing to import</x-admin.button>
                @endif
            @else
                <x-admin.button variant="primary" :href="route('admin.products.index')" icon="tag">View products</x-admin.button>
            @endif
        </div>
    @endif
@endsection

@push('scripts')
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('productImport', ({ url, status }) => ({
                status, paused: false, error: null, busy: false,
                init() { this.loop() },
                label() {
                    return this.status.phase === 'preview' ? 'Dry run – checking every row' : 'Importing products'
                },
                pause() { this.paused = true },
                resume() { this.paused = false; this.error = null; this.loop() },
                async loop() {
                    if (this.busy) return
                    this.busy = true
                    try {
                        while (!this.paused && this.status.running) {
                            const next = await Admin.fetch(url, { method: 'POST', data: {} })
                            if (next.busy) { await new Promise(r => setTimeout(r, 1500)); continue }
                            this.status = next
                        }
                        if (!this.status.running) window.location.reload()
                    } catch (e) {
                        this.error = (e.message || 'The batch failed.') + ' Press Continue to try again.'
                        this.paused = true
                    } finally {
                        this.busy = false
                    }
                },
            }))
        })
    </script>
@endpush
