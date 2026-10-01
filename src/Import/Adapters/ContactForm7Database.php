<?php

namespace Pine\Commerce\Import\Adapters;

use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Steps\FormsStep;

/** Contact Form 7 Database Addon (CFDB7) submissions – step extras.forms. */
class ContactForm7Database extends AbstractAdapter
{
    public function key(): string
    {
        return 'cfdb7';
    }

    public function label(): string
    {
        return 'Contact Form 7 submissions (CFDB7)';
    }

    public function detect(SiteProfile $site, WordPressSource $wp): bool
    {
        return $wp->hasTable('db7_forms');
    }

    public function steps(): array
    {
        return [FormsStep::class];
    }
}
