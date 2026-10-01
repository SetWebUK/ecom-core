<?php

namespace Pine\Commerce\Import\Adapters;

use Pine\Commerce\Import\Contracts\Adapter;
use Pine\Commerce\Import\ImportContext;
use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\WordPressSource;

/** Convenience base: the run's ImportContext is bound before detect(), priority 0, no steps. */
abstract class AbstractAdapter implements Adapter
{
    protected ?ImportContext $ctx = null;

    public function boot(ImportContext $ctx): void
    {
        $this->ctx = $ctx;
    }

    public function label(): string
    {
        return $this->key();
    }

    public function priority(): int
    {
        return 0;
    }

    public function steps(): array
    {
        return [];
    }

    abstract public function detect(SiteProfile $site, WordPressSource $wp): bool;

    /** `commerce-import.{key}` config shortcut. */
    protected function config(string $key, $default = null)
    {
        return $this->ctx ? $this->ctx->config($key, $default) : $default;
    }
}
