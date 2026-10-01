<?php

namespace Pine\Commerce\Console;

use Illuminate\Console\Command;
use Pine\Commerce\Support\Doctor;

/**
 * Health check of the install: prints every check as PASS / WARN / FAIL / INFO with the fix. Read-only.
 * Exit code 1 when any check FAILs (usable in deploy scripts: `php artisan commerce:doctor || exit 1`).
 */
class DoctorCommand extends Command
{
    protected $signature = 'commerce:doctor
        {--connection= : Database connection to check (default: database.default)}
        {--json : Print the results as JSON}
        {--only-problems : Hide passing and informational checks}';

    protected $description = 'Check the pine/commerce install (environment, database, assets, theme, mail, payments) and print fixes';

    public function handle(): int
    {
        $results = (new Doctor($this->option('connection') ?: null))->run();
        $counts = Doctor::counts($results);

        if ($this->option('json')) {
            $this->line(json_encode(['counts' => $counts, 'checks' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $counts[Doctor::FAIL] ? self::FAILURE : self::SUCCESS;
        }

        $labels = [
            Doctor::PASS => '<fg=green;options=bold>PASS</>',
            Doctor::WARN => '<fg=yellow;options=bold>WARN</>',
            Doctor::FAIL => '<fg=red;options=bold>FAIL</>',
            Doctor::INFO => '<fg=blue;options=bold>INFO</>',
        ];

        $group = null;
        foreach ($results as $r) {
            if ($this->option('only-problems') && in_array($r['status'], [Doctor::PASS, Doctor::INFO], true)) {
                continue;
            }
            if ($r['group'] !== $group) {
                $group = $r['group'];
                $this->newLine();
                $this->line("<options=bold>{$group}</>");
            }
            $this->line("  {$labels[$r['status']]}  {$r['title']}: {$r['message']}");
            if ($r['fix']) {
                $this->line("        <fg=gray>fix:</> {$r['fix']}");
            }
        }

        $this->newLine();
        $summary = sprintf('%d passed, %d warning(s), %d failed.', $counts[Doctor::PASS], $counts[Doctor::WARN], $counts[Doctor::FAIL]);
        $counts[Doctor::FAIL] ? $this->components->error($summary) : ($counts[Doctor::WARN] ? $this->components->warn($summary) : $this->components->info($summary));

        return $counts[Doctor::FAIL] ? self::FAILURE : self::SUCCESS;
    }
}
