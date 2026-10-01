<?php

namespace Pine\Commerce\Scheduling;

/**
 * One scheduled task: a core one (Pine\Commerce\Scheduling\Scheduler::TASKS) or a client one registered with
 * Commerce::scheduledTask(['task' => MyTask::class, …]). Resolved from the container on every run.
 *
 *  - skipReason(): why the task has nothing to do right now (a setting or feature switch is off), or null to run;
 *  - handle(): do the work and return a one-line summary for `commerce:schedule:status` / Settings › Scheduled tasks.
 *
 * Tasks never assume cron exists: every one is safe to run late, twice or from the web fallback.
 */
abstract class Task
{
    public function skipReason(): ?string
    {
        return null;
    }

    abstract public function handle(): string;
}
