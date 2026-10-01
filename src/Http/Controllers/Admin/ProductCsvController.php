<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Pine\Commerce\Exports\CsvExport;
use Pine\Commerce\Http\Controllers\Admin\Concerns\AdminIndex;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Services\Admin\Catalogue\ProductCsv\Columns;
use Pine\Commerce\Services\Admin\Catalogue\ProductCsv\Exporter;
use Pine\Commerce\Services\Admin\Catalogue\ProductCsv\ImportRunner;
use Pine\Commerce\Services\Admin\Catalogue\ProductCsv\ImportSession;
use Pine\Commerce\Services\Admin\Catalogue\ProductFilter;
use Pine\Commerce\Services\Admin\LocalTime;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Products › Import / Export (feature switch product_csv): the full product CSV.
 *
 *   GET    admin/products/csv                              admin.products.csv                 export + upload forms, recent imports
 *   GET    admin/products/csv/export                       admin.products.csv.export          CSV (?scope=filtered keeps the list filters)
 *   POST   admin/products/csv/import                       admin.products.csv.upload          upload → column mapping
 *   GET    admin/products/csv/import/{token}               admin.products.csv.mapping         map columns + options
 *   POST   admin/products/csv/import/{token}               admin.products.csv.map             save → dry run
 *   GET    admin/products/csv/import/{token}/run           admin.products.csv.run             progress / preview / report page
 *   POST   admin/products/csv/import/{token}/step          admin.products.csv.step            JSON: process the next batch
 *   POST   admin/products/csv/import/{token}/start         admin.products.csv.start           dry run checked → real import
 *   GET    admin/products/csv/import/{token}/report        admin.products.csv.report          results as CSV (?pass=preview|import)
 *   DELETE admin/products/csv/import/{token}               admin.products.csv.destroy         discard the upload + reports
 *
 * Batches are driven by the page (one request per batch, no queue worker) and resumable: the progress lives in the
 * ImportSession on the private disk. (Route names sit under admin.products.* so the Products menu stays open.)
 */
class ProductCsvController extends Controller
{
    use AdminIndex;

    public const SHOW = ['all' => 'All rows', 'create' => 'New', 'update' => 'Updated', 'skip' => 'Skipped', 'error' => 'Errors', 'warnings' => 'Warnings'];

    public function index(Request $request): View
    {
        $filter = ProductFilter::fromRequest($request);

        return view('commerce::admin.products.csv.index', [
            'filter' => $filter,
            'filtered' => $filter->isFiltered(),
            'filteredCount' => $filter->isFiltered() ? $filter->query()->count() : null,
            'total' => \Pine\Commerce\Models\Product::query()->count(),
            'filterQuery' => $request->except(['page', 'per_page', 'sort', 'direction']),
            'imports' => ImportSession::recent(8),
            'columns' => Columns::exportKeys(),
            'maxRows' => ImportSession::maxRows(),
            'maxKb' => $this->maxKb(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filter = ProductFilter::fromRequest($request);
        $query = $request->query('scope') === 'filtered' ? $filter->query() : \Pine\Commerce\Models\Product::query();
        $variations = $request->query('variations', '1') !== '0';

        return Exporter::response($query, 'products-full-'.LocalTime::now()->format('Y-m-d-His').'.csv', $variations);
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:'.$this->maxKb(), 'mimes:csv,txt', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel,text/x-csv'],
        ], [
            'file.required' => 'Choose a CSV file to import.',
            'file.mimes' => 'Upload a .csv file (in Excel: File › Save As › CSV UTF-8).',
            'file.mimetypes' => 'Upload a .csv file (in Excel: File › Save As › CSV UTF-8).',
            'file.max' => 'The file can be up to '.round($this->maxKb() / 1024).' MB.',
        ]);

        try {
            $session = ImportSession::create($request->file('file')->getRealPath(), $request->file('file')->getClientOriginalName(), $request->user()->getKey());
        } catch (RuntimeException $e) {
            return back()->withErrors(['file' => $e->getMessage()])->with('error', $e->getMessage());
        }

        return redirect()->route('admin.products.csv.mapping', $session->token);
    }

    public function mapping(Request $request, string $token): View|RedirectResponse
    {
        $session = $this->session($request, $token);
        if (in_array($session->phase(), ['importing', 'done'], true)) {
            return redirect()->route('admin.products.csv.run', $token);
        }

        return view('commerce::admin.products.csv.mapping', [
            'session' => $session,
            'targets' => Columns::targets($session->state['headers']),
        ]);
    }

    public function map(Request $request, string $token): RedirectResponse
    {
        $session = $this->session($request, $token);
        if (in_array($session->phase(), ['importing', 'done'], true)) {
            return redirect()->route('admin.products.csv.run', $token)->with('warning', 'This import has already run – upload the file again to import it once more.');
        }
        $headers = $session->state['headers'];
        $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string', 'max:40'],
            'update_existing' => ['required', 'boolean'],
            'match_by' => ['required', Rule::in(['sku', 'id'])],
            'create_missing' => ['required', 'boolean'],
            'download_images' => ['required', 'boolean'],
            'empty_cells' => ['required', Rule::in(['skip', 'overwrite'])],
        ]);

        $mapping = [];
        $used = [];
        $errors = [];
        foreach (array_keys($headers) as $index) {
            $target = (string) ($request->input("mapping.$index") ?? '');
            if ($target === '') {
                $mapping[$index] = null;

                continue;
            }
            if (! Columns::isTarget($target)) {
                $errors["mapping.$index"] = 'Choose a field from the list.';

                continue;
            }
            if (isset($used[$target])) {
                $errors["mapping.$index"] = '“'.Columns::label($target).'” is already used for column “'.$headers[$used[$target]].'”.';

                continue;
            }
            $used[$target] = $index;
            $mapping[$index] = $target;
        }
        if (! array_intersect(array_keys($used), ['sku', 'id', 'name'])) {
            $errors['mapping'] = 'Map at least one of SKU, ID or Name, so rows can be matched or created.';
        }
        if ($request->input('match_by') === 'id' && ! isset($used['id'])) {
            $errors['match_by'] = 'Map the ID column to match products by ID.';
        }
        if ($errors) {
            return back()->withErrors($errors)->withInput();
        }

        $session->state['mapping'] = $mapping;
        $session->state['options'] = [
            'update_existing' => $request->boolean('update_existing'),
            'match_by' => (string) $request->input('match_by'),
            'create_missing' => $request->boolean('create_missing'),
            'download_images' => $request->boolean('download_images'),
            'empty_cells' => (string) $request->input('empty_cells'),
        ];
        $session->begin('preview');

        return redirect()->route('admin.products.csv.run', $token);
    }

    public function run(Request $request, string $token): View|RedirectResponse
    {
        $session = $this->session($request, $token);
        if ($session->phase() === 'mapping') {
            return redirect()->route('admin.products.csv.mapping', $token);
        }
        $show = $this->filterValue($request, 'show', self::SHOW) ?? 'all';
        $rows = [];
        $matching = 0;
        if (! $session->isRunning()) {
            foreach ($session->results() as $row) {
                $keep = match ($show) {
                    'all' => true,
                    'warnings' => ($row['action'] ?? '') !== 'error' && ! empty($row['messages']),
                    default => ($row['action'] ?? '') === $show,
                };
                if ($keep && ++$matching <= 500) {
                    $rows[] = $row;
                }
            }
        }

        return view('commerce::admin.products.csv.run', [
            'session' => $session,
            'status' => $session->status(),
            'rows' => $rows,
            'hidden' => max(0, $matching - count($rows)),
            'show' => $show,
            'chunk' => max(1, min(ImportRunner::MAX_CHUNK, (int) config('commerce.product_csv.chunk_size', 25))),
        ]);
    }

    public function step(Request $request, string $token): JsonResponse
    {
        $session = $this->session($request, $token);
        @set_time_limit(max(60, (int) config('commerce.product_csv.time_budget', 20) * 3));
        try {
            $status = (new ImportRunner($session))->step();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($status);
    }

    public function start(Request $request, string $token): RedirectResponse
    {
        $session = $this->session($request, $token);
        if ($session->phase() !== 'previewed') {
            return redirect()->route('admin.products.csv.run', $token)->with('warning', 'Check the dry run first.');
        }
        $session->begin('importing');

        return redirect()->route('admin.products.csv.run', $token);
    }

    public function report(Request $request, string $token): StreamedResponse
    {
        $session = $this->session($request, $token);
        $pass = $request->query('pass') === 'preview' ? 'preview' : 'importing';
        $rows = (function () use ($session, $pass) {
            foreach ($session->results($pass) as $row) {
                yield [$row['line'] ?? '', $row['action'] ?? '', $row['type'] ?? '', $row['sku'] ?? '', $row['name'] ?? '', $row['id'] ?? '',
                    implode(' | ', (array) ($row['messages'] ?? []))];
            }
        })();
        $name = Str::slug(pathinfo((string) $session->state['filename'], PATHINFO_FILENAME)) ?: 'import';

        return CsvExport::stream(($pass === 'preview' ? 'dry-run-' : 'import-report-').$name.'.csv',
            ['line', 'result', 'type', 'sku', 'name', 'id', 'messages'], $rows);
    }

    public function destroy(Request $request, string $token): RedirectResponse
    {
        $session = $this->session($request, $token);
        if ($session->phase() === 'importing' && $session->isRunning()) {
            return back()->with('warning', 'This import is still running – let it finish first.');
        }
        $session->delete();

        return redirect()->route('admin.products.csv')->with('success', 'Import removed.');
    }

    protected function session(Request $request, string $token): ImportSession
    {
        $session = ImportSession::find($token);
        abort_unless($session && $session->ownedBy($request->user()), 404);

        return $session;
    }

    protected function maxKb(): int
    {
        return max(100, (int) config('commerce.product_csv.max_file_kb', 20480));
    }
}
