<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Models\PlatformUpdate;
use Pine\Commerce\Services\Admin\StaffPassword;
use Pine\Commerce\Updater\ComposerProject;
use Pine\Commerce\Updater\Skeleton\SkeletonBaseline;
use Pine\Commerce\Updater\Skeleton\SkeletonComparer;
use Pine\Commerce\Updater\Skeleton\SkeletonUpdater;
use Pine\Commerce\Updater\UpdateChecker;
use Pine\Commerce\Updater\UpdateLock;
use Pine\Commerce\Updater\UpdateManager;
use Pine\Commerce\Updater\UpdateRunner;
use Throwable;

/**
 * Admin › Updates (administrators only, feature switch "updater"): update check, approve & install a pine/commerce
 * release (password + confirmation; runs in the background, the page follows its log), skeleton file updates and the
 * audit history. Nothing typed in the browser reaches a command: the version comes from the stored check and file
 * paths from the stored skeleton comparison.
 */
class UpdateController extends Controller
{
    public function index(UpdateManager $manager, ComposerProject $project): View
    {
        $status = UpdateChecker::status();
        $open = PlatformUpdate::openRun();
        if ($open) {
            $open = $manager->reconcile($open);
        }
        $history = PlatformUpdate::query()->latest('id')->paginate(20, ['id', 'type', 'status', 'via', 'user_email', 'from_version', 'to_version', 'files', 'error', 'created_at', 'finished_at']);

        return view('commerce::admin.updates.index', [
            'status' => $status,
            'check' => $status['check'],
            'result' => $status['check']?->result ?? [],
            'open' => $open && in_array($open->status, PlatformUpdate::OPEN, true) ? $open : null,
            'installedPretty' => $project->installedPrettyVersion(),
            'constraint' => $project->constraint(),
            'repository' => $project->repository(),
            'baseline' => SkeletonBaseline::read(),
            'plan' => PlatformUpdate::query()->where('type', PlatformUpdate::TYPE_SKELETON)->latest('id')->first(),
            'history' => $history,
        ]);
    }

    public function check(Request $request, UpdateChecker $checker): RedirectResponse
    {
        try {
            $check = $checker->check($request->user(), 'admin');
        } catch (Throwable $e) {
            Log::warning('Update check failed: '.$e->getMessage());

            return back()->with('error', 'The update check failed: '.$e->getMessage());
        }
        $result = $check->result;
        if ($result['error']) {
            return back()->with('error', $result['error']);
        }

        return back()->with($result['update_available'] ? 'warning' : 'success', $result['update_available']
            ? "pine/commerce {$result['latest']} is available. Read the changes below, then approve it."
            : 'You are up to date (pine/commerce '.$result['installed'].').');
    }

    public function install(Request $request, UpdateManager $manager): RedirectResponse
    {
        $data = $request->validate([
            'check' => ['required', 'integer'],
            'password' => ['required', 'string'],
            'confirm' => ['accepted'],
        ], [
            'confirm.accepted' => 'Tick the box to confirm you have read the changes and approve this update.',
            'password.required' => 'Enter your password to approve the update.',
        ]);
        $user = $request->user();
        if (! StaffPassword::check($user, (string) $data['password'])) {
            throw ValidationException::withMessages(['password' => 'That password is not correct.']);
        }
        $check = PlatformUpdate::query()->where('type', PlatformUpdate::TYPE_CHECK)->findOrFail((int) $data['check']);

        try {
            $update = $manager->approve($check, $user, 'admin');
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
        $launch = $manager->launch($update);
        $response = redirect()->route('admin.updates.show', $update);
        if ($secret = $update->meta('secret')) {
            // the approving administrator keeps using the site while it is in maintenance mode
            $response->withCookie(MaintenanceModeBypassCookie::create($secret));
        }
        if (! $launch['started']) {
            return $response->with('warning', 'Approved, but the update could not be started from the web server ('.$launch['error'].'). Run on the server: '.$launch['command']);
        }

        return $response->with('success', "Update to pine/commerce {$update->to_version} approved and started.");
    }

    public function show(PlatformUpdate $update, UpdateManager $manager): View
    {
        $update = $manager->reconcile($update);

        return view('commerce::admin.updates.show', [
            'update' => $update,
            'steps' => UpdateRunner::STEPS,
            'payload' => $this->payload($update, 0),
            'restore' => $update->meta('backup') ? UpdateRunner::restoreHint((string) $update->meta('backup')) : null,
            'bypass' => $this->bypassUrl($update),
        ]);
    }

    public function status(Request $request, PlatformUpdate $update, UpdateManager $manager): JsonResponse
    {
        $update = $manager->reconcile($update);

        return response()->json($this->payload($update, max(0, (int) $request->query('from', 0))));
    }

    // ------------------------------------------------------------------ skeleton

    public function compareSkeleton(Request $request, SkeletonUpdater $skeleton): RedirectResponse
    {
        try {
            $plan = $skeleton->plan($request->user(), 'admin');
        } catch (Throwable $e) {
            return redirect()->route('admin.updates.index')->with('error', $e->getMessage());
        }
        $counts = $plan->result['counts'] ?? [];
        $safe = ($counts['added'] ?? 0) + ($counts['unchanged'] ?? 0) + ($counts['removed'] ?? 0);

        return redirect()->route('admin.updates.index', ['#skeleton'])
            ->with('success', "Skeleton {$plan->from_version} → {$plan->to_version} compared: {$safe} file(s) can be updated safely.");
    }

    public function applySkeleton(Request $request, PlatformUpdate $update, SkeletonUpdater $skeleton): RedirectResponse
    {
        abort_unless($update->type === PlatformUpdate::TYPE_SKELETON, 404);
        $data = $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['string', 'max:500'],
            'password' => ['required', 'string'],
            'confirm' => ['accepted'],
        ], [
            'files.required' => 'Select at least one file to update.',
            'confirm.accepted' => 'Tick the box to confirm.',
        ]);
        if (! StaffPassword::check($request->user(), (string) $data['password'])) {
            throw ValidationException::withMessages(['password' => 'That password is not correct.']);
        }
        try {
            $result = $skeleton->apply($update, array_values($data['files']), $request->user());
        } catch (Throwable $e) {
            return redirect()->route('admin.updates.index', ['#skeleton'])->with('error', $e->getMessage());
        }
        $message = count($result['applied']).' skeleton file(s) updated'.($result['skipped'] ? ', '.count($result['skipped']).' skipped' : '')
            .($result['baseline_moved'] ? " – the project now matches skeleton {$update->to_version}." : '.');

        return redirect()->route('admin.updates.index', ['#skeleton'])->with($result['skipped'] ? 'warning' : 'success', $message);
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string,mixed> */
    protected function payload(PlatformUpdate $update, int $from): array
    {
        $lines = $update->logLines();

        return [
            'id' => $update->getKey(),
            'status' => $update->status,
            'step' => $update->step,
            'step_label' => UpdateRunner::STEPS[$update->step] ?? null,
            'from_version' => $update->from_version,
            'to_version' => $update->to_version,
            'finished' => $update->isFinished(),
            'error' => $update->error,
            'lines' => array_slice($lines, $from),
            'next' => count($lines),
            'running' => app(UpdateLock::class)->held(),
            'backup' => $update->meta('backup') ? basename((string) $update->meta('backup')) : null,
        ];
    }

    /** The maintenance bypass link – only while the update runs (the secret is cleared when it ends). */
    protected function bypassUrl(PlatformUpdate $update): ?string
    {
        $secret = $update->meta('secret');

        return $secret && ! $update->isFinished() ? url('/'.$secret) : null;
    }
}
