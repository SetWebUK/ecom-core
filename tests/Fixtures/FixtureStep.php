<?php

namespace Pine\Commerce\Tests\Fixtures;

use Pine\Commerce\Import\Steps\AbstractStep;

/** A client importer step (ExtensionApiTest). */
class FixtureStep extends AbstractStep
{
    public function key(): string
    {
        return 'extras.acme-loyalty';
    }

    public function section(): string
    {
        return 'extras';
    }

    protected function import(): void {}
}
