<?php

namespace Pine\Commerce\Import\Contracts;

use Pine\Commerce\Import\ImportContext;

/**
 * One unit of the WordPress/WooCommerce import (docs/ARCHITECTURE.md §12.3). Steps are idempotent: every row
 * is matched on its WordPress id (or a natural key) and updated in place, so an import can be re-run at any time.
 */
interface Step
{
    /** Unique key, e.g. 'catalog' or 'catalog.products'. */
    public function key(): string;

    /** Section the step belongs to (settings|media|users|catalog|orders|extras|content|menus|redirects|<custom>) – used by --only/--skip. */
    public function section(): string;

    /** @return string[] step keys that must run first (topological order; ties keep registration order) */
    public function after(): array;

    public function shouldRun(ImportContext $ctx): bool;

    /** Import; runs inside a DB transaction on the target connection. */
    public function run(ImportContext $ctx): void;

    /** --fresh: delete previously imported rows this step owns (wp_id / natural keys) – never anything else. */
    public function purge(ImportContext $ctx): void;
}
