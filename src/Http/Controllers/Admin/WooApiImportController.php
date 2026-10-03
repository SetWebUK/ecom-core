<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Import\WooApi\Client;
use Pine\Commerce\Import\WooApi\Connection;
use Pine\Commerce\Import\WooApi\Importer;
use Pine\Commerce\Import\WooApi\Probe;
use Pine\Commerce\Import\WooApi\RunLog;
use Pine\Commerce\Import\WooApi\RunManager;
use Pine\Commerce\Import\WooApi\SameSite;
use Pine\Commerce\Import\WooApi\StoredConnection;
use Pine\Commerce\Models\WooApiImport;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Admin › Import › WooCommerce API (administrators only, feature switch "woo_api_import"): the saved connection
 * (secrets encrypted, never shown again), "Test connection", the entity checklist and options, starting a run in
 * the background (no queue: a detached artisan process, like Admin › Updates), its live progress page (polls
 * status()), cancel / resume, the history and the run logs. docs/IMPORTER.md "Importing via the WooCommerce REST API".
 */
class WooApiImportController extends Controller
{
    public function index(RunManager $manager): View
    {
        $open = WooApiImport::openRun();
        if ($open) {
            $open = $manager->reconcile($open);
        }
        $connection = StoredConnection::form();
        $stored = StoredConnection::load();
        $lastCompleted = WooApiImport::query()->where('status', 'completed')->where('dry_run', false)
            ->when($stored->url !== '', fn ($q) => $q->where('site_url', $stored->url))->latest('id')->first();

        return view('commerce::admin.import.woo-api.index', [
            'connection' => $connection,
            'probe' => session('woo_api_probe'),
            'open' => $open && $open->isOpen() ? $open : null,
            'choices' => Importer::CHOICES,
            'storeChoices' => Importer::STORE_CHOICES,
            'sameSite' => $stored->url !== '' && SameSite::matches($stored),
            'importedSite' => SameSite::importedSiteUrl(),
            'lastCompleted' => $lastCompleted,
            'history' => WooApiImport::query()->latest('id')->paginate(15),
            'labels' => Importer::LABELS,
        ]);
    }

    /** Save the connection; with action=test also test it (result flashed to the page). */
    public function saveConnection(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'mode' => ['required', Rule::in(['rest', 'store'])],
            'url' => ['required', 'string', 'max:255'],
            'key' => ['nullable', 'string', 'max:255'],
            'secret' => ['nullable', 'string', 'max:255'],
            'wp_user' => ['nullable', 'string', 'max:120'],
            'wp_password' => ['nullable', 'string', 'max:255'],
            'auth' => ['required', Rule::in(Connection::AUTH_MODES)],
            'verify_tls' => ['nullable', 'boolean'],
            'key_clear' => ['nullable', 'boolean'],
            'secret_clear' => ['nullable', 'boolean'],
            'wp_password_clear' => ['nullable', 'boolean'],
        ], [], ['url' => 'site address', 'key' => 'consumer key', 'secret' => 'consumer secret', 'wp_password' => 'application password']);
        if ($validator->fails()) {
            // never flash a typed secret back into the session
            return back()->withErrors($validator)->withInput($request->except(StoredConnection::SECRETS));
        }
        $data = $validator->validated();
        try {
            Connection::normaliseUrl($data['url']);
        } catch (Throwable $e) {
            return back()->withErrors(['url' => $e->getMessage()])->withInput($request->except(StoredConnection::SECRETS));
        }
        StoredConnection::save($data + ['verify_tls' => $request->boolean('verify_tls')]);
        if ($request->input('action') !== 'test') {
            return redirect()->route('admin.import.woo.index')->with('success', 'Connection saved.');
        }

        $connection = StoredConnection::load();
        if ($connection->url === '' || (! $connection->store && ! $connection->hasKeys())) {
            return redirect()->route('admin.import.woo.index')->with('warning', 'Enter the consumer key and secret (or choose the public Store API) to test the connection.');
        }
        $result = (new Probe(new Client($connection)))->run();
        $result['tested_at'] = now()->toIso8601String();

        return redirect()->route('admin.import.woo.index', ['#test'])->with('woo_api_probe', $result)
            ->with($result['ok'] ? ($result['problems'] ? 'warning' : 'success') : 'error',
                $result['ok'] ? 'Connected to '.($result['store']['name'] ?: $connection->host()).($result['problems'] ? ' – with permission problems, see below.' : '.')
                    : 'The connection test failed: '.$result['error']);
    }

    public function start(Request $request, RunManager $manager): RedirectResponse
    {
        $data = $request->validate([
            'entities' => ['required', 'array', 'min:1'],
            'entities.*' => [Rule::in(array_keys(Importer::CHOICES))],
            'images' => ['nullable', 'boolean'],
            'existing' => ['required', Rule::in(['update', 'skip'])],
            'orders_after' => ['nullable', 'date_format:Y-m-d'],
            'since' => ['nullable', 'date'],
            'notes' => ['nullable', 'boolean'],
            'same_site' => ['nullable', 'boolean'],
            'dry_run' => ['nullable', 'boolean'],
        ], ['entities.required' => 'Tick at least one thing to import.']);
        $connection = StoredConnection::load();
        if ($connection->url === '' || (! $connection->store && ! $connection->hasKeys())) {
            return back()->with('error', 'Save a connection (site address and API key, or the public Store API) first.');
        }
        try {
            $run = $manager->create($connection, [
                'entities' => $data['entities'],
                'images' => $request->boolean('images'),
                'existing' => $data['existing'],
                'orders_after' => $data['orders_after'] ?? null,
                'since' => $data['since'] ?? null,
                'notes' => $request->boolean('notes'),
            ], $request->boolean('dry_run'), $request->boolean('same_site'), $request->user(), 'admin');
        } catch (Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        $launch = $manager->launch($run);
        $response = redirect()->route('admin.import.woo.show', $run);

        return $launch['started']
            ? $response->with('success', ($run->dry_run ? 'Dry run' : 'Import').' #'.$run->id.' started in the background.')
            : $response->with('warning', 'The import could not be started from the web server ('.$launch['error'].'). Run on the server: '.$launch['command']);
    }

    public function show(WooApiImport $import, RunManager $manager): View
    {
        $import = $manager->reconcile($import);

        return view('commerce::admin.import.woo-api.show', [
            'run' => $import,
            'payload' => $this->payload($import, 0),
            'labels' => Importer::LABELS,
            'choices' => Importer::CHOICES,
        ]);
    }

    public function status(Request $request, WooApiImport $import, RunManager $manager): JsonResponse
    {
        return response()->json($this->payload($manager->reconcile($import), max(0, (int) $request->query('from', 0))));
    }

    public function cancel(WooApiImport $import, RunManager $manager): RedirectResponse
    {
        $import = $manager->cancel($import);

        return redirect()->route('admin.import.woo.show', $import)->with('info', $import->status === 'cancelling'
            ? 'Cancelling – the import stops after the page it is working on.' : 'Import #'.$import->id.' is '.$import->status.'.');
    }

    public function resume(WooApiImport $import, RunManager $manager): RedirectResponse
    {
        try {
            $import = $manager->resume($import);
        } catch (Throwable $e) {
            return redirect()->route('admin.import.woo.show', $import)->with('error', $e->getMessage());
        }
        if (StoredConnection::load()->url !== $import->site_url) {
            $import->forceFill(['status' => 'interrupted'])->save();

            return redirect()->route('admin.import.woo.show', $import)->with('error', 'The saved connection now points at another shop – connect to '.$import->site_url.' again to resume this import.');
        }
        $launch = $manager->launch($import);

        return redirect()->route('admin.import.woo.show', $import)->with($launch['started'] ? 'success' : 'warning', $launch['started']
            ? 'Import #'.$import->id.' resumed from where it stopped.'
            : 'It could not be started from the web server ('.$launch['error'].'). Run on the server: '.$launch['command']);
    }

    public function log(WooApiImport $import): BinaryFileResponse
    {
        $file = RunLog::for($import->id)->file;
        abort_unless(is_file($file), 404);

        return response()->download($file, 'woocommerce-import-'.$import->id.'.log', ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /** @return array<string,mixed> */
    protected function payload(WooApiImport $run, int $from): array
    {
        [$lines, $next] = RunLog::for($run->id)->tail($from);
        $entities = [];
        foreach ($run->entities() as $entity) {
            $entities[] = ['key' => $entity, 'label' => Importer::LABELS[$entity]] + $run->entityProgress($entity);
        }

        return [
            'id' => $run->id,
            'status' => $run->status,
            'finished' => $run->isFinished(),
            'resumable' => $run->isResumable(),
            'dry_run' => (bool) $run->dry_run,
            'current' => $run->progress['current'] ?? null,
            'entities' => $entities,
            'issues' => array_reverse(array_slice((array) ($run->progress['issues'] ?? []), -100)),
            'warnings' => (int) $run->warnings,
            'errors' => (int) $run->errors,
            'error' => $run->error,
            'lines' => array_values(array_filter($lines, fn ($l) => ! str_contains($l, '] HTTP '))),
            'next' => $next,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }
}
