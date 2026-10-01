{{-- Product import step 1: match the file's columns to product fields and choose the options, then dry run. --}}
@extends('commerce::admin.layouts.app', ['width' => 'wide'])

@php
    use Pine\Commerce\Services\Admin\Catalogue\ProductCsv\Columns;
    $headers = $session->state['headers'];
    $samples = $session->state['samples'];
    $mapping = $session->state['mapping'];
    $options = $session->state['options'];
    $mappedCount = count(array_filter($mapping));
@endphp

@section('title', 'Map columns · Import products')

@section('content')
    <x-admin.page-header title="Match the columns" :back="route('admin.products.csv')" back-label="Import / Export"
                         :subtitle="$session->state['filename'].' · '.number_format($session->state['total']).' rows – nothing is saved until you run the import.'" />

    @if ($session->state['woocommerce'] ?? false)
        <x-admin.callout type="info" title="WooCommerce export recognised" class="mb-4">
            Its columns have been matched for you, including the “Attribute 1 name / value(s)” groups. Variations are linked to their parent product by the Parent column.
        </x-admin.callout>
    @endif
    @error('mapping')<x-admin.callout type="danger" class="mb-4">{{ $message }}</x-admin.callout>@enderror

    <form method="POST" action="{{ route('admin.products.csv.map', $session->token) }}" id="mapping-form">
        @csrf
        <div class="layout">
            <div class="layout__main">
                <x-admin.card flush :title="'Columns ('.$mappedCount.' of '.count($headers).' matched)'" subtitle="Columns set to “Don’t import” are ignored.">
                    <x-admin.table compact stack>
                        <x-slot:head>
                            <x-admin.th>Column in your file</x-admin.th>
                            <x-admin.th>Example values</x-admin.th>
                            <x-admin.th>Import as</x-admin.th>
                        </x-slot:head>
                        @foreach ($headers as $i => $header)
                            @php $current = old("mapping.$i", $mapping[$i] ?? ''); @endphp
                            <tr>
                                <td class="stack-title"><span class="fw-600">{{ $header !== '' ? $header : '(no name)' }}</span></td>
                                <td data-label="Examples" class="text-sm text-muted" style="max-width:320px">
                                    @foreach ($samples as $sample)
                                        @if (($sample[$i] ?? '') !== '')<div class="truncate">{{ Str::limit(strip_tags($sample[$i]), 80) }}</div>@endif
                                    @endforeach
                                </td>
                                <td data-label="Import as">
                                    <label class="sr-only" for="map-{{ $i }}">Import “{{ $header }}” as</label>
                                    <select name="mapping[{{ $i }}]" id="map-{{ $i }}" @class(['select', 'is-invalid' => $errors->has("mapping.$i")]) style="min-width:220px">
                                        <option value="">— Don’t import —</option>
                                        @foreach ($targets as $key => $label)
                                            <option value="{{ $key }}" @selected((string) $current === (string) $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error("mapping.$i")<p class="field__error">{{ $message }}</p>@enderror
                                </td>
                            </tr>
                        @endforeach
                    </x-admin.table>
                </x-admin.card>
            </div>

            <div class="layout__aside layout__aside--sticky">
                <x-admin.card title="Options">
                    <div class="stack-fields">
                        <x-admin.toggle name="update_existing" label="Update existing products" help="Rows that match a product already in the shop update it. Off: those rows are skipped." :checked="$options['update_existing']" />
                        <x-admin.select name="match_by" label="Match existing products by" :value="$options['match_by']"
                                        :options="['sku' => 'SKU', 'id' => 'ID (this shop’s product / variant ID)']"
                                        help="Variants are also matched by their options within the parent product." />
                        <x-admin.toggle name="create_missing" label="Create missing categories and attributes" help="Off: unknown categories, attributes and values are skipped (with a warning)." :checked="$options['create_missing']" />
                        <x-admin.toggle name="download_images" label="Download images into the media library" help="Image URLs from other sites are downloaded once (and reused on the next import). Off: only this shop’s own images are used." :checked="$options['download_images']" />
                        <x-admin.radio-cards name="empty_cells" label="Empty cells" :value="$options['empty_cells']" :options="[
                            'skip' => ['label' => 'Keep current value', 'help' => 'An empty cell changes nothing'],
                            'overwrite' => ['label' => 'Clear the value', 'help' => 'An empty cell removes it (e.g. sale price, images)'],
                        ]" />
                    </div>
                    <x-slot:footer>
                        <x-admin.button type="submit" variant="primary" icon="eye" block>Check with a dry run</x-admin.button>
                    </x-slot:footer>
                </x-admin.card>
                <p class="text-xs text-muted mt-2">The dry run reads every row and lists what would be created, updated, skipped or rejected. Nothing is saved until you confirm the import.</p>
            </div>
        </div>
    </form>
@endsection
