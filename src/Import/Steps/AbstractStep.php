<?php

namespace Pine\Commerce\Import\Steps;

use Pine\Commerce\Import\Contracts\Step;
use Pine\Commerce\Import\ImportContext;
use Pine\Commerce\Import\Source\WordPressSource;

/**
 * Base class of the import steps: binds the run's ImportContext and WordPress source, then calls import() / clear().
 * key() defaults to the section; after() declares steps that must run first (see Import\Pipeline for ordering).
 */
abstract class AbstractStep implements Step
{
    protected ImportContext $ctx;

    protected WordPressSource $wp;

    public function __construct(?ImportContext $ctx = null)
    {
        if ($ctx) {
            $this->bind($ctx);
        }
    }

    public function key(): string
    {
        return $this->section();
    }

    abstract public function section(): string;

    public function after(): array
    {
        return [];
    }

    public function shouldRun(ImportContext $ctx): bool
    {
        return true;
    }

    public function run(ImportContext $ctx): void
    {
        $this->bind($ctx);
        $this->import();
    }

    public function purge(ImportContext $ctx): void
    {
        $this->bind($ctx);
        $this->clear();
    }

    /** Import this step's data (the context is bound). */
    abstract protected function import(): void;

    /** Remove previously imported rows owned by this step (used by --fresh). */
    protected function clear(): void {}

    protected function bind(ImportContext $ctx): void
    {
        $this->ctx = $ctx;
        $this->wp = $ctx->wp;
    }

    protected function now(): string
    {
        return now()->format('Y-m-d H:i:s');
    }
}
