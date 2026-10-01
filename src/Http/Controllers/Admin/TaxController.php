<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Models\TaxClass;
use Pine\Commerce\Models\TaxRate;
use Pine\Commerce\Services\Admin\Countries;
use Pine\Commerce\Services\Tax\TaxRates;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings › Tax: tax classes and the rate table of each class (the options above them are the generic settings form,
 * group "tax"). Rates import/export as WooCommerce-compatible CSV:
 *   Country code, State code, Postcode / ZIP, City, Rate %, Tax name, Priority, Compound, Shipping, Tax class
 * (postcodes and cities separated by ";", Compound/Shipping 1/0, Tax class = slug, blank = standard).
 */
class TaxController extends Controller
{
    public const CSV_HEADINGS = ['Country code', 'State code', 'Postcode / ZIP', 'City', 'Rate %', 'Tax name', 'Priority', 'Compound', 'Shipping', 'Tax class'];

    public function storeClass(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('taxClass', ['name' => ['required', 'string', 'max:120']]);
        $slug = Str::slug($data['name']) ?: 'class';
        if (TaxClass::where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['name' => 'There is already a tax class called “'.$data['name'].'”.'])->errorBag('taxClass');
        }
        TaxClass::create(['name' => trim($data['name']), 'slug' => $slug, 'sort_order' => (int) TaxClass::max('sort_order') + 1]);

        return redirect()->to(route('admin.settings.edit', 'tax').'?class='.$slug.'#tax-rates')->with('success', 'Tax class “'.trim($data['name']).'” added – now add its rates.');
    }

    public function destroyClass(TaxClass $taxClass): RedirectResponse
    {
        abort_if($taxClass->slug === TaxClass::STANDARD, 422, 'The standard class cannot be deleted.');
        DB::transaction(function () use ($taxClass) {
            TaxRate::where('tax_class', $taxClass->slug)->delete();
            $taxClass->delete();
        });
        TaxRates::flush();

        return redirect()->to(route('admin.settings.edit', 'tax').'#tax-rates')->with('success', 'Tax class “'.$taxClass->name.'” deleted. Products that used it now use the standard rate.');
    }

    /** PUT rates of one class: rows with an id are updated, new rows created, rows left out deleted. */
    public function saveRates(Request $request, string $class): RedirectResponse
    {
        $class = TaxClass::normalise($class);
        abort_unless(array_key_exists($class, TaxClass::options()), 404);
        $data = $request->validate([
            'rates' => ['array', 'max:500'],
            'rates.*.id' => ['nullable', 'integer'],
            'rates.*.country' => ['nullable', 'string', 'max:2', 'regex:/^([A-Za-z]{2}|\*)?$/'],
            'rates.*.state' => ['nullable', 'string', 'max:100'],
            'rates.*.postcodes' => ['nullable', 'string', 'max:5000'],
            'rates.*.cities' => ['nullable', 'string', 'max:5000'],
            'rates.*.rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'rates.*.name' => ['nullable', 'string', 'max:120'],
            'rates.*.priority' => ['nullable', 'integer', 'min:1', 'max:99'],
            'rates.*.compound' => ['nullable', 'boolean'],
            'rates.*.shipping' => ['nullable', 'boolean'],
        ], ['rates.*.country.regex' => 'Use a 2-letter country code (GB, FR…) or leave it blank for every country.',
            'rates.*.rate.required' => 'Every rate needs a percentage.'], ['rates.*.rate' => 'rate', 'rates.*.country' => 'country']);

        $kept = [];
        DB::transaction(function () use ($data, $class, &$kept) {
            foreach (array_values($data['rates'] ?? []) as $i => $row) {
                $values = static::rateValues($row, $class) + ['sort_order' => $i];
                $rate = ! empty($row['id']) ? TaxRate::where('tax_class', $class)->find($row['id']) : null;
                $rate ? $rate->update($values) : $rate = TaxRate::create($values);
                $kept[] = $rate->id;
            }
            TaxRate::where('tax_class', $class)->whereNotIn('id', $kept ?: [0])->delete();
        });
        TaxRates::flush();

        return redirect()->to(route('admin.settings.edit', 'tax').'?class='.$class.'#tax-rates')
            ->with('success', count($kept).' '.Str::plural('rate', count($kept)).' saved for '.(TaxClass::options()[$class] ?? $class).'.');
    }

    public function export(Request $request): StreamedResponse
    {
        $class = is_string($request->query('class')) && $request->query('class') !== '' ? TaxClass::normalise($request->query('class')) : null;
        $rates = TaxRate::query()->when($class, fn ($q) => $q->where('tax_class', $class))->orderBy('tax_class')->orderBy('sort_order')->orderBy('id')->get();

        return response()->streamDownload(function () use ($rates) {
            $out = fopen('php://output', 'w');
            fputcsv($out, self::CSV_HEADINGS, ',', '"', '');
            foreach ($rates as $rate) {
                fputcsv($out, [
                    $rate->country, $rate->state, implode(';', $rate->postcodeList()), implode(';', $rate->cityList()),
                    TaxRate::formatRate($rate->rate), $rate->name, $rate->priority, $rate->compound ? 1 : 0, $rate->shipping ? 1 : 0,
                    $rate->tax_class === TaxClass::STANDARD ? '' : $rate->tax_class,
                ], ',', '"', '');
            }
            fclose($out);
        }, 'tax-rates'.($class ? '-'.$class : '').'-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** POST a CSV (the export format / WooCommerce's). mode=replace deletes the rates of every class in the file first. */
    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:2048', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel'],
            'mode' => ['required', Rule::in(['append', 'replace'])],
        ], ['file.mimetypes' => 'Choose a .csv file.']);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $rows = [];
        $errors = [];
        $line = 0;
        $header = null;
        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $line++;
            if ($cells === [null] || ! array_filter($cells, fn ($c) => trim((string) $c) !== '')) {
                continue;
            }
            $cells = array_map(fn ($c) => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $c)), $cells);
            if ($header === null && ! is_numeric($cells[4] ?? null)) {
                $header = $cells; // heading row

                continue;
            }
            $header ??= self::CSV_HEADINGS;
            [$country, $state, $postcodes, $cities, $rate, $name, $priority, $compound, $shipping, $class] = array_pad($cells, 10, '');
            $country = strtoupper($country) === '*' ? '' : strtoupper($country);
            if ($country !== '' && (! preg_match('/^[A-Z]{2}$/', $country) || ! array_key_exists($country, Countries::options()))) {
                $errors[] = "Line $line: unknown country code “{$country}”.";

                continue;
            }
            if (! is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100) {
                $errors[] = "Line $line: the rate must be a percentage between 0 and 100.";

                continue;
            }
            $rows[] = ['country' => $country, 'state' => strtoupper($state) === '*' ? '' : $state,
                'postcodes' => str_replace(';', "\n", $postcodes === '*' ? '' : $postcodes), 'cities' => str_replace(';', "\n", $cities === '*' ? '' : $cities),
                'rate' => $rate, 'name' => $name, 'priority' => is_numeric($priority) ? (int) $priority : 1,
                'compound' => in_array(strtolower($compound), ['1', 'yes', 'true'], true), 'shipping' => $shipping === '' || in_array(strtolower($shipping), ['1', 'yes', 'true'], true),
                'class' => TaxClass::normalise($class)];
        }
        fclose($handle);

        if ($errors) {
            return back()->withErrors(['file' => implode(' ', array_slice($errors, 0, 5)).(count($errors) > 5 ? ' …and '.(count($errors) - 5).' more.' : '')]);
        }
        if (! $rows) {
            return back()->withErrors(['file' => 'The file has no rates.']);
        }

        DB::transaction(function () use ($rows, $request) {
            $classes = array_values(array_unique(array_column($rows, 'class')));
            $known = TaxClass::options();
            foreach ($classes as $slug) {
                if (! array_key_exists($slug, $known)) {
                    TaxClass::create(['name' => Str::headline($slug), 'slug' => $slug, 'sort_order' => (int) TaxClass::max('sort_order') + 1]);
                }
            }
            if ($request->input('mode') === 'replace') {
                TaxRate::whereIn('tax_class', $classes)->delete();
            }
            $order = [];
            foreach ($rows as $row) {
                $order[$row['class']] ??= (int) TaxRate::where('tax_class', $row['class'])->max('sort_order') + 1;
                TaxRate::create(static::rateValues($row, $row['class']) + ['sort_order' => $order[$row['class']]++]);
            }
        });
        TaxRates::flush();

        return redirect()->to(route('admin.settings.edit', 'tax').'#tax-rates')->with('success', count($rows).' '.Str::plural('rate', count($rows)).' imported.');
    }

    /** Clean values of one rate row (form or CSV). */
    public static function rateValues(array $row, string $class): array
    {
        $list = fn ($v) => implode("\n", TaxRate::lines(str_replace(';', "\n", (string) $v)));
        $country = strtoupper(trim((string) ($row['country'] ?? '')));

        return [
            'tax_class' => $class,
            'country' => $country === '*' ? '' : $country,
            'state' => trim((string) ($row['state'] ?? '')),
            'postcodes' => $list($row['postcodes'] ?? '') ?: null,
            'cities' => $list($row['cities'] ?? '') ?: null,
            'rate' => round((float) $row['rate'], 4),
            'name' => trim((string) ($row['name'] ?? '')),
            'priority' => max(1, (int) ($row['priority'] ?? 1)),
            'compound' => filter_var($row['compound'] ?? false, FILTER_VALIDATE_BOOL),
            'shipping' => filter_var($row['shipping'] ?? false, FILTER_VALIDATE_BOOL),
        ];
    }
}
