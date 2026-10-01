<?php

namespace Pine\Commerce\Import\Adapters;

use Pine\Commerce\Import\Contracts\RedirectProvider;
use Pine\Commerce\Import\Data\RedirectRule;
use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\WordPressSource;

/** Redirection plugin: enabled URL rules of enabled groups, in the plugin's evaluation order (position, id). */
class Redirection extends AbstractAdapter implements RedirectProvider
{
    public function key(): string
    {
        return 'redirection';
    }

    public function label(): string
    {
        return 'Redirection';
    }

    public function priority(): int
    {
        return 30;
    }

    public function detect(SiteProfile $site, WordPressSource $wp): bool
    {
        return $site->hasPlugin('redirection/redirection.php') || $wp->hasTable('redirection_items');
    }

    public function redirects(): iterable
    {
        $wp = $this->ctx->wp;
        $groups = $wp->table('redirection_groups')->where('status', 'enabled')->pluck('id')->all();
        foreach ($wp->table('redirection_items')->where('status', 'enabled')->where('action_type', 'url')
            ->whereIn('group_id', $groups ?: [0])->orderBy('position')->orderBy('id')->cursor() as $item) {
            $last = (string) ($item->last_access ?? '');
            yield new RedirectRule(trim((string) $item->url), (string) $item->action_data, (int) $item->action_code, 'redirection',
                (bool) $item->regex, (int) ($item->last_count ?? 0),
                $last !== '' && ! str_starts_with($last, '1970') && ! str_starts_with($last, '0000') ? $last : null);
        }
    }
}
