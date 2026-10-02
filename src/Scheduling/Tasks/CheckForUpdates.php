<?php

namespace Pine\Commerce\Scheduling\Tasks;

use Pine\Commerce\Scheduling\Task;
use Pine\Commerce\Updater\UpdateChecker;
use Pine\Commerce\Updater\Updater;

/**
 * Daily update check (Admin › Updates): looks for a newer pine/commerce / skeleton release and records it, so the
 * dashboard and the sidebar can say so. It never installs anything – an administrator approves every update.
 */
class CheckForUpdates extends Task
{
    public function skipReason(): ?string
    {
        if (! Updater::enabled()) {
            return 'The updater is switched off (feature switch "updater").';
        }
        if (! filter_var(config('commerce.updater.check', true), FILTER_VALIDATE_BOOL)) {
            return 'Scheduled update checks are off (commerce.updater.check).';
        }
        if (app()->runningUnitTests()) {
            return 'Not checked while running tests.';
        }

        return null;
    }

    public function handle(): string
    {
        $check = app(UpdateChecker::class)->check(null, 'schedule');
        $result = $check->result;
        if ($result['error']) {
            return 'Check failed: '.$result['error'];
        }

        return $result['update_available']
            ? 'pine/commerce '.$result['latest'].' is available (installed '.$result['installed'].') – approve it in Admin › Updates'
            : 'Up to date ('.$result['installed'].')';
    }
}
