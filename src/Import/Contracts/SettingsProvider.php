<?php

namespace Pine\Commerce\Import\Contracts;

use Pine\Commerce\Import\ImportContext;

/** Store settings (chained: later providers override earlier ones; `settings.seed` from config wins last). */
interface SettingsProvider
{
    /** @return array<string,mixed> setting key => value */
    public function settings(ImportContext $ctx): array;
}
