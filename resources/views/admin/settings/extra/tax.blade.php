{{-- Settings › Tax, below the options form: tax classes and the rate table of the chosen class (TaxController). --}}
@php
    use Pine\Commerce\Models\TaxClass;
    use Pine\Commerce\Models\TaxRate;
    $classes = TaxClass::options();
    $current = is_string(request('class')) && array_key_exists(request('class'), $classes) ? request('class') : TaxClass::STANDARD;
    $classModels = TaxClass::query()->orderBy('sort_order')->orderBy('id')->get()->keyBy('slug');
    try {
        $counts = TaxRate::query()->selectRaw('tax_class, count(*) as c')->groupBy('tax_class')->pluck('c', 'tax_class');
        $rates = TaxRate::query()->where('tax_class', $current)->orderBy('sort_order')->orderBy('id')->get();
    } catch (\Throwable) {
        $counts = collect();
        $rates = collect();
    }
    $rows = old('rates', $rates->map(fn (TaxRate $r) => [
        'id' => $r->id, 'country' => $r->country, 'state' => $r->state, 'postcodes' => implode('; ', $r->postcodeList()),
        'cities' => implode('; ', $r->cityList()), 'rate' => TaxRate::formatRate($r->rate), 'name' => $r->name,
        'priority' => $r->priority, 'compound' => $r->compound, 'shipping' => $r->shipping,
    ])->all());
    $tabs = collect($classes)->map(fn ($name, $slug) => ['label' => $name, 'count' => (int) ($counts[$slug] ?? 0)])->all();
@endphp

<div class="stack mt-4" id="tax-rates">
    <x-admin.card flush title="Tax rates" subtitle="One row per country (or county/postcode area). Blank country = every country. The first matching row of each priority applies; rows with different priorities are added together.">
        <x-slot:actions>
            <x-admin.button size="sm" variant="plain" icon="arrow-down-tray" :href="route('admin.tax.export', ['class' => $current])">Export CSV</x-admin.button>
            <x-admin.button size="sm" variant="plain" icon="arrow-up-tray" x-data x-on:click="$dispatch('open-modal', 'tax-import')">Import CSV</x-admin.button>
        </x-slot:actions>
        <x-admin.status-tabs :tabs="$tabs" :current="$current" param="class" :default="TaxClass::STANDARD" />

        <form method="POST" action="{{ route('admin.tax.rates.save', $current) }}" x-data="taxRates(@js(array_values($rows)))" id="tax-rates-form">
            @csrf
            @method('PUT')
            <div class="table-wrap" style="overflow-x:auto">
                <table class="table table--compact tax-rates-table">
                    <thead>
                        <tr>
                            <th scope="col" style="width:78px">Country</th>
                            <th scope="col">County / state</th>
                            <th scope="col">Postcodes</th>
                            <th scope="col">Cities</th>
                            <th scope="col" style="width:90px">Rate %</th>
                            <th scope="col">Name</th>
                            <th scope="col" style="width:70px">Priority</th>
                            <th scope="col" class="text-center">Compound</th>
                            <th scope="col" class="text-center">Shipping</th>
                            <th scope="col"><span class="sr-only">Remove</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(row, i) in rows" :key="row._k">
                            <tr>
                                <td>
                                    <input type="hidden" :name="'rates[' + i + '][id]'" :value="row.id || ''">
                                    <input class="input input--sm mono" :name="'rates[' + i + '][country]'" x-model="row.country" maxlength="2" placeholder="*" aria-label="Country code" style="text-transform:uppercase">
                                </td>
                                <td><input class="input input--sm" :name="'rates[' + i + '][state]'" x-model="row.state" maxlength="100" placeholder="*" aria-label="County or state"></td>
                                <td><input class="input input--sm mono" :name="'rates[' + i + '][postcodes]'" x-model="row.postcodes" placeholder="*" aria-label="Postcodes (separate with ;)"></td>
                                <td><input class="input input--sm" :name="'rates[' + i + '][cities]'" x-model="row.cities" placeholder="*" aria-label="Cities (separate with ;)"></td>
                                <td><input class="input input--sm" type="number" step="any" min="0" max="100" :name="'rates[' + i + '][rate]'" x-model="row.rate" required aria-label="Rate %"></td>
                                <td><input class="input input--sm" :name="'rates[' + i + '][name]'" x-model="row.name" maxlength="120" placeholder="VAT" aria-label="Name on receipts"></td>
                                <td><input class="input input--sm" type="number" min="1" max="99" :name="'rates[' + i + '][priority]'" x-model="row.priority" aria-label="Priority"></td>
                                <td class="text-center"><input type="checkbox" class="checkbox" value="1" :name="'rates[' + i + '][compound]'" x-model="row.compound" aria-label="Compound"></td>
                                <td class="text-center"><input type="checkbox" class="checkbox" value="1" :name="'rates[' + i + '][shipping]'" x-model="row.shipping" aria-label="Also charged on shipping"></td>
                                <td><button type="button" class="btn btn--ghost-danger btn--icon btn--sm" @click="remove(i)" aria-label="Remove rate"><x-admin.icon name="trash" /></button></td>
                            </tr>
                        </template>
                        <tr x-show="rows.length === 0">
                            <td colspan="10" class="text-muted text-sm">No rates in this class – nothing is charged for products using it.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            @if ($errors->has('rates.*') || $errors->has('rates'))
                <div class="p-4"><x-admin.callout type="danger" title="Some rates need fixing">{{ collect($errors->get('rates*'))->flatten()->unique()->implode(' ') }}</x-admin.callout></div>
            @endif
            <div class="row row--between p-4">
                <div class="row">
                    <x-admin.button size="sm" icon="plus" x-on:click="add()">Add rate</x-admin.button>
                    <x-admin.button size="sm" variant="plain" x-on:click="add({ country: 'GB', rate: '20', name: 'VAT' })">+ UK VAT 20%</x-admin.button>
                </div>
                <x-admin.button type="submit" variant="primary" size="sm">Save {{ $classes[$current] }} rates</x-admin.button>
            </div>
        </form>
        <div class="px-4 pb-4 text-xs text-muted">
            Postcodes: separate with “;”. Use <span class="mono">BT*</span> for a whole area, <span class="mono">HS1-HS9</span> for UK districts or
            <span class="mono">10000...19999</span> for number ranges. Compound rates are charged on top of the other rates.
        </div>
    </x-admin.card>

    <x-admin.card title="Tax classes" subtitle="Give products a class (e.g. Reduced rate for children’s car seats) on the product page. Every product without one uses the standard rate.">
        <ul class="stack stack--sm" style="list-style:none;margin:0;padding:0">
            @foreach ($classes as $slug => $name)
                <li class="row row--between">
                    <span><span class="fw-600">{{ $name }}</span> <span class="text-muted text-xs mono">{{ $slug }}</span> · <a href="{{ route('admin.settings.edit', 'tax') }}?class={{ $slug }}#tax-rates">{{ (int) ($counts[$slug] ?? 0) }} {{ Str::plural('rate', (int) ($counts[$slug] ?? 0)) }}</a></span>
                    @if ($slug !== TaxClass::STANDARD && $classModels->has($slug))
                        <x-admin.confirm :action="route('admin.tax.classes.destroy', $classModels[$slug])" method="DELETE" variant="ghost-danger" size="sm" icon="trash"
                                         :title="'Delete “'.$name.'”?'" message="Its rates are deleted too, and products using it fall back to the standard rate." confirm-label="Delete class">Delete</x-admin.confirm>
                    @endif
                </li>
            @endforeach
        </ul>
        <div class="divider"></div>
        <form method="POST" action="{{ route('admin.tax.classes.store') }}" class="row" style="align-items:flex-end">
            @csrf
            <div class="flex-1"><x-admin.input name="name" label="New tax class" bag="taxClass" maxlength="120" placeholder="e.g. Children’s clothing" /></div>
            <x-admin.button type="submit" icon="plus">Add class</x-admin.button>
        </form>
    </x-admin.card>
</div>

<x-admin.modal name="tax-import" title="Import tax rates" size="md">
    <form method="POST" action="{{ route('admin.tax.import') }}" enctype="multipart/form-data" id="tax-import-form" class="stack-fields">
        @csrf
        <p class="text-sm">CSV columns (WooCommerce’s format): <span class="mono text-xs">{{ implode(', ', \Pine\Commerce\Http\Controllers\Admin\TaxController::CSV_HEADINGS) }}</span>. Separate several postcodes or cities with “;”; blank tax class = standard.</p>
        <x-admin.field label="CSV file" for="tax-import-file" error="file">
            <input type="file" name="file" id="tax-import-file" accept=".csv,text/csv" required>
        </x-admin.field>
        <x-admin.radio-cards name="mode" value="append" :options="[
            'append' => ['label' => 'Add to the existing rates'],
            'replace' => ['label' => 'Replace the rates of the classes in the file'],
        ]" />
    </form>
    <x-slot:footer>
        <x-admin.button x-on:click="$dispatch('close-modal', 'tax-import')">Cancel</x-admin.button>
        <x-admin.button type="submit" form="tax-import-form" variant="primary" icon="arrow-up-tray">Import</x-admin.button>
    </x-slot:footer>
</x-admin.modal>

@push('scripts')
    <script>
        document.addEventListener('alpine:init', function () {
            var seq = 0;
            window.Alpine.data('taxRates', function (initial) {
                var norm = function (r) {
                    r = r || {};
                    return { _k: ++seq, id: r.id || null, country: r.country || '', state: r.state || '', postcodes: r.postcodes || '', cities: r.cities || '',
                        rate: r.rate === undefined || r.rate === null ? '' : String(r.rate), name: r.name || '', priority: r.priority || 1,
                        compound: r.compound === true || r.compound === '1' || r.compound === 1, shipping: r.shipping === undefined ? true : (r.shipping === true || r.shipping === '1' || r.shipping === 1) };
                };
                return {
                    rows: (initial || []).map(norm),
                    add: function (preset) { this.rows.push(norm(preset)); },
                    remove: function (i) { this.rows.splice(i, 1); }
                };
            });
        });
    </script>
@endpush
