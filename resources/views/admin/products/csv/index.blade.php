{{-- Products › Import / Export: full product CSV download, upload (→ mapping → dry run → import) and recent imports. --}}
@extends('commerce::admin.layouts.app')

@php
    use Pine\Commerce\Services\Admin\Catalogue\ProductCsv\Columns;
    $phaseLabels = ['mapping' => ['Columns not mapped yet', 'gray'], 'preview' => ['Dry run in progress', 'info'], 'previewed' => ['Dry run checked – not imported', 'attention'],
        'importing' => ['Import paused', 'warning'], 'done' => ['Imported', 'success']];
@endphp

@section('title', 'Import / Export · Products')

@section('content')
    <x-admin.page-header title="Import / Export" :back="route('admin.products.index')" back-label="Back to products"
                         subtitle="Download every product as a spreadsheet, or add and update products from a CSV file – including a WooCommerce product export." />

    <div class="grid-2">
        <div>
            <x-admin.card title="Export products" subtitle="One row per product, followed by one row per variant.">
                <form method="GET" action="{{ route('admin.products.csv.export') }}" data-no-loading class="stack-fields">
                    @foreach ($filterQuery as $key => $value)
                        @if (is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
                    @endforeach
                    <x-admin.radio-cards name="scope" label="Which products" :value="$filtered ? 'filtered' : 'all'" :options="array_filter([
                        'all' => ['label' => 'All products', 'help' => number_format($total).' products', 'icon' => 'squares-2x2'],
                        'filtered' => $filtered ? ['label' => 'Current list filters', 'help' => number_format($filteredCount).' products · '.implode(', ', array_values($filter->chips()) ?: ['search / tab']), 'icon' => 'funnel'] : null,
                    ])" />
                    <x-admin.toggle name="variations" label="Include variants" help="A row per variant (type “variation”) under its product." :checked="true" />
                    <div>
                        <x-admin.button type="submit" variant="primary" icon="arrow-down-tray">Download CSV</x-admin.button>
                    </div>
                    <p class="text-xs text-muted">UTF-8 with BOM, so Excel opens it with £ signs and accents intact. Columns: <span class="mono">{{ implode(', ', $columns) }}</span>.</p>
                </form>
            </x-admin.card>
        </div>

        <div>
            <x-admin.card title="Import products" subtitle="Add new products and update existing ones.">
                <form method="POST" action="{{ route('admin.products.csv.upload') }}" enctype="multipart/form-data" class="stack-fields" id="product-import-form">
                    @csrf
                    <x-admin.field label="CSV file" for="f-file" error="file" :help="'Up to '.number_format($maxRows).' rows and '.round($maxKb / 1024).' MB. Excel: File › Save As › “CSV UTF-8”.'">
                        <input type="file" name="file" id="f-file" accept=".csv,text/csv" required @class(['input', 'is-invalid' => $errors->has('file')])>
                    </x-admin.field>
                    <div>
                        <x-admin.button type="submit" variant="primary" icon="arrow-up-tray">Upload and map columns</x-admin.button>
                    </div>
                </form>
                <x-slot:footer>
                    <ol class="text-sm text-muted" style="margin:0;padding-left:1.2em">
                        <li>Match the file’s columns to product fields (done for you when the headers are recognised).</li>
                        <li>A dry run shows what would be created, updated, skipped or rejected – nothing is saved yet.</li>
                        <li>Run the import. It works through the file in small batches; keep the page open (you can resume it later).</li>
                    </ol>
                </x-slot:footer>
            </x-admin.card>
        </div>
    </div>

    <x-admin.card title="Recent imports" flush class="mt-6">
        @if (! $imports)
            <x-admin.empty icon="document-arrow-up" title="No imports yet" :description="'Files you upload here are kept for '.(int) config('commerce.product_csv.keep_days', 14).' days with their reports.'" size="sm" />
        @else
            <x-admin.table compact stack>
                <x-slot:head>
                    <x-admin.th>File</x-admin.th>
                    <x-admin.th>Uploaded</x-admin.th>
                    <x-admin.th align="right">Rows</x-admin.th>
                    <x-admin.th>Status</x-admin.th>
                    <x-admin.th align="right"><span class="sr-only">Actions</span></x-admin.th>
                </x-slot:head>
                @foreach ($imports as $import)
                    @php
                        [$label, $color] = $phaseLabels[$import->phase()] ?? [$import->phase(), 'gray'];
                        if ($import->phase() === 'importing' && ! $import->isRunning()) { [$label, $color] = ['Imported', 'success']; }
                        $counts = $import->state['result']['counts'] ?? null;
                    @endphp
                    <tr>
                        <td class="stack-title">
                            <a class="row-link" href="{{ route($import->phase() === 'mapping' ? 'admin.products.csv.mapping' : 'admin.products.csv.run', $import->token) }}">{{ $import->state['filename'] }}</a>
                            @if ($import->state['woocommerce'] ?? false)<x-admin.badge size="sm" color="outline">WooCommerce</x-admin.badge>@endif
                        </td>
                        <td data-label="Uploaded"><x-admin.time :value="\Illuminate\Support\Carbon::parse($import->state['created_at'])" /></td>
                        <td class="num" data-label="Rows">{{ number_format($import->state['total']) }}</td>
                        <td data-label="Status">
                            <x-admin.badge :color="$color">{{ $label }}</x-admin.badge>
                            @if ($counts)
                                <span class="text-xs text-muted">{{ $counts['create'] }} new · {{ $counts['update'] }} updated · {{ $counts['error'] }} errors</span>
                            @endif
                        </td>
                        <td class="table__actions">
                            <x-admin.confirm :action="route('admin.products.csv.destroy', $import->token)" method="DELETE" title="Remove this import?"
                                             message="The uploaded file and its reports are deleted. Products that were imported stay." confirm-label="Remove"
                                             variant="ghost-danger" icon="trash" size="sm" label="Remove">Remove</x-admin.confirm>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
        @endif
    </x-admin.card>

    <x-admin.callout type="neutral" title="File format" class="mt-6">
        <p class="text-sm">The first row holds the column names; the export’s own names work, and so do WooCommerce’s (Products › Export in WooCommerce), so a WooCommerce store can be imported directly.
            Lists in one cell are separated with “ | ”: categories as <span class="mono">Kitchen &gt; Mugs | Offers</span> (first = main), images as URLs (first = main image),
            attributes as <span class="mono">Memory: 8GB, 16GB | Colour: Silver</span>, specifications as <span class="mono">Material: Enamel | Capacity: 350ml</span>.
            Variants are rows with type <span class="mono">variation</span>, the parent’s SKU (or <span class="mono">id:123</span>) in <span class="mono">parent_sku</span> and their options in <span class="mono">variation_options</span>.</p>
    </x-admin.callout>
@endsection
