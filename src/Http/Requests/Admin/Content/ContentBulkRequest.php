<?php

namespace Pine\Commerce\Http\Requests\Admin\Content;

use Pine\Commerce\Http\Requests\Admin\BulkActionRequest;
use Illuminate\Validation\Rule;

/**
 * Bulk bar submissions (ids[] + action) for the content & inbox lists. The allowed actions depend on the route.
 */
class ContentBulkRequest extends BulkActionRequest
{
    public const ACTIONS = [
        'admin.pages.bulk' => ['publish', 'draft', 'delete'],
        'admin.posts.bulk' => ['publish', 'draft', 'delete'],
        'admin.media.bulk' => ['delete'],
        'admin.redirects.bulk' => ['activate', 'deactivate', 'delete'],
        'admin.form-submissions.bulk' => ['read', 'unread', 'delete'],
        'admin.newsletter.bulk' => ['unsubscribe', 'resubscribe', 'delete'],
    ];

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['action'] = ['required', 'string', Rule::in(self::ACTIONS[$this->route()?->getName()] ?? [])];

        return $rules;
    }
}
