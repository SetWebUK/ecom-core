<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Exports\CsvExport;
use Pine\Commerce\Http\Controllers\Admin\Concerns\AdminIndex;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Http\Requests\Admin\Content\ContentBulkRequest;
use Pine\Commerce\Http\Requests\Admin\Content\RedirectRequest;
use Pine\Commerce\Models\Redirect;
use Pine\Commerce\Services\Admin\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Redirects from old addresses (hits counted by the storefront's ResolveController), with CSV import/export.
 */
class RedirectController extends Controller
{
    use AdminIndex;

    public const SORTS = ['from_path', 'hits', 'last_hit_at', 'created_at', 'status_code'];

    public const IMPORT_MAX_ROWS = 5000;

    public function index(Request $request): View
    {
        $q = $this->searchTerm($request);
        $status = $this->filterValue($request, 'status', ['active' => 1, 'inactive' => 1, 'unused' => 1]) ?? 'all';
        $type = $this->filterValue($request, 'type', RedirectRequest::TYPES);
        [$sort, $direction] = $this->sorting($request, self::SORTS, 'from_path');

        $redirects = $this->filtered($request)
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate($this->perPage($request, 50))
            ->withQueryString();

        $counts = Redirect::query()->selectRaw('count(*) as total, sum(is_active = 1) as active, sum(is_active = 0) as inactive, sum(hits = 0) as unused')->first();

        return view('commerce::admin.redirects.index', [
            'redirects' => $redirects,
            'q' => $q,
            'status' => $status,
            'tabs' => [
                'all' => ['label' => 'All', 'count' => (int) $counts->total],
                'active' => ['label' => 'Active', 'count' => (int) $counts->active],
                'inactive' => ['label' => 'Switched off', 'count' => (int) $counts->inactive],
                'unused' => ['label' => 'Never used', 'count' => (int) $counts->unused],
            ],
            'chips' => array_filter(['type' => $type ? 'Type: '.RedirectRequest::TYPES[$type]['label'] : null]),
            'typeOptions' => collect(RedirectRequest::TYPES)->map(fn ($t) => $t['label'])->all(),
            'isFiltered' => $q !== '' || $type || $status !== 'all',
        ]);
    }

    public function create(Request $request): View
    {
        $from = $request->query('from');

        return view('commerce::admin.redirects.form', [
            'redirect' => new Redirect([
                'from_path' => is_string($from) ? RedirectRequest::normaliseFrom($from) : '',
                'status_code' => 301,
                'is_active' => true,
            ]),
        ]);
    }

    public function store(RedirectRequest $request): RedirectResponse
    {
        $redirect = Redirect::create($request->redirectData());

        return redirect()->route('admin.redirects.index')->with('success', "Redirect from /{$redirect->from_path}/ added.");
    }

    public function edit(Redirect $redirect): View
    {
        return view('commerce::admin.redirects.form', ['redirect' => $redirect]);
    }

    public function update(RedirectRequest $request, Redirect $redirect): RedirectResponse
    {
        $redirect->update($request->redirectData());

        return redirect()->route('admin.redirects.edit', $redirect)->with('success', 'Redirect saved.');
    }

    public function destroy(Redirect $redirect): RedirectResponse
    {
        $redirect->delete();

        return redirect()->route('admin.redirects.index')->with('success', "Redirect from /{$redirect->from_path}/ deleted.");
    }

    public function bulk(ContentBulkRequest $request): RedirectResponse
    {
        $query = Redirect::whereIn('id', $request->ids());
        $action = $request->input('action');
        $count = match ($action) {
            'activate' => $query->update(['is_active' => true, 'updated_at' => now()]),
            'deactivate' => $query->update(['is_active' => false, 'updated_at' => now()]),
            'delete' => $query->delete(),
        };
        $verb = ['activate' => 'switched on', 'deactivate' => 'switched off', 'delete' => 'deleted'][$action];

        return back()->with('success', $count.' '.Str::plural('redirect', $count).' '.$verb.'.');
    }

    public function export(Request $request): StreamedResponse
    {
        // Alphabetical, so lazy() (offset pages) – lazyById() would skip rows when the order isn't by id
        $query = $this->filtered($request)->orderBy('from_path')->orderBy('id');

        return CsvExport::stream('redirects-'.now()->format('Y-m-d').'.csv', ['from', 'to', 'type', 'active', 'hits', 'last_hit'], (function () use ($query) {
            foreach ($query->lazy(1000) as $r) {
                yield ['/'.$r->from_path.'/', $r->to_url, $r->status_code, $r->is_active ? 'yes' : 'no', $r->hits, $r->last_hit_at ? LocalTime::format($r->last_hit_at, 'Y-m-d H:i') : null];
            }
        })());
    }

    /** CSV columns: from, to, [type]. A header row is optional. Existing addresses are updated or skipped. */
    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:4096', 'mimes:csv,txt'],
            'mode' => ['required', 'in:update,skip'],
        ], [
            'file.required' => 'Choose a CSV file to import.',
            'file.mimes' => 'Upload a .csv file (from, to, type).',
            'file.max' => 'The file is too large (4 MB max).',
        ], ['file' => 'CSV file']);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if (! $handle) {
            return back()->with('error', 'The file could not be read.');
        }

        $existing = Redirect::query()->pluck('id', 'from_path');
        $map = Redirect::query()->where('is_active', true)->pluck('to_url', 'from_path')->all();
        $rows = [];
        $problems = [];
        $line = 0;
        while (($cells = fgetcsv($handle, 4000, ',', '"', '\\')) !== false) {
            $line++;
            $cells = array_map(fn ($c) => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $c)), $cells);
            if (count(array_filter($cells, fn ($c) => $c !== '')) === 0) {
                continue;
            }
            if ($line === 1 && preg_match('/^(from|old|source)/i', $cells[0] ?? '')) {
                continue; // header
            }
            if (count($rows) >= self::IMPORT_MAX_ROWS) {
                $problems[] = 'Only the first '.self::IMPORT_MAX_ROWS.' rows were read.';
                break;
            }
            $from = RedirectRequest::normaliseFrom($cells[0] ?? '');
            $to = RedirectRequest::normaliseTarget($cells[1] ?? '');
            $code = (int) ($cells[2] ?? 301) ?: 301;
            $gone = $code === RedirectRequest::GONE;
            if ($gone) {
                $to = ''; // 410 Gone has no new address
            }
            if ($from === '' || ($to === '' && ! $gone)) {
                $problems[] = "Row {$line}: needs both an old and a new address.";

                continue;
            }
            if (! array_key_exists($code, RedirectRequest::TYPES)) {
                $problems[] = "Row {$line}: type {$code} isn’t supported (use 301, 302, 307, 308 or 410).";

                continue;
            }
            if (mb_strlen($from) > 255 || mb_strlen($to) > 1000 || (! $gone && ! preg_match('#^(/|https?://|mailto:|tel:)#i', $to))) {
                $problems[] = "Row {$line}: the address is too long or not allowed.";

                continue;
            }
            $trial = $map;
            unset($trial[$from]);
            if ($problem = RedirectRequest::problem($from, $to, null, $trial, $gone)) {
                $problems[] = "Row {$line} (/{$from}/): ".$problem[1];

                continue;
            }
            $map[$from] = $to;
            $rows[$from] = ['from_path' => $from, 'to_url' => $to, 'status_code' => $code];
        }
        fclose($handle);

        $created = $updated = $skipped = 0;
        DB::transaction(function () use ($rows, $existing, $request, &$created, &$updated, &$skipped) {
            foreach ($rows as $from => $row) {
                if (isset($existing[$from])) {
                    if ($request->input('mode') === 'skip') {
                        $skipped++;

                        continue;
                    }
                    Redirect::whereKey($existing[$from])->update($row + ['is_active' => true, 'updated_at' => now()]);
                    $updated++;
                } else {
                    Redirect::create($row + ['is_active' => true]);
                    $created++;
                }
            }
        });

        $summary = "Import finished: {$created} added, {$updated} updated".($skipped ? ", {$skipped} skipped (already existed)" : '').'.';
        if ($problems) {
            return redirect()->route('admin.redirects.index')
                ->with($created + $updated ? 'warning' : 'error', $summary.' '.count($problems).' '.Str::plural('row', count($problems)).' ignored.')
                ->with('import_problems', array_slice($problems, 0, 20));
        }

        return redirect()->route('admin.redirects.index')->with('success', $summary);
    }

    protected function filtered(Request $request): Builder
    {
        $q = $this->searchTerm($request);
        $status = $this->filterValue($request, 'status', ['active' => 1, 'inactive' => 1, 'unused' => 1]);
        $type = $this->filterValue($request, 'type', RedirectRequest::TYPES);
        $pathQ = RedirectRequest::normaliseFrom($q);

        return Redirect::query()
            ->when($q !== '', fn (Builder $query) => $query->where(fn (Builder $w) => $w
                ->where('from_path', 'like', $this->like($pathQ !== '' ? $pathQ : $q))
                ->orWhere('to_url', 'like', $this->like($q))))
            ->when($status === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->when($status === 'unused', fn (Builder $query) => $query->where('hits', 0))
            ->when($type, fn (Builder $query) => $query->where('status_code', (int) $type));
    }
}
