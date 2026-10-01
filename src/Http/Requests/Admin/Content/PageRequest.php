<?php

namespace Pine\Commerce\Http\Requests\Admin\Content;

use Pine\Commerce\Models\Page;
use Pine\Commerce\Services\Admin\PageBlocks;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create/update a content page. pageData() is the only thing saved (Page uses $guarded = ['id']).
 * Content is trusted staff HTML and is stored exactly as posted – but only when the editor reports a change
 * (content_changed = 1), so saving SEO fields never re-serialises imported Elementor markup.
 */
class PageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canAccessAdmin();
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        if (is_string($this->input('slug'))) {
            $clean['slug'] = Str::slug(trim($this->input('slug'), " /\t\n"));
        }
        if (is_string($this->input('title'))) {
            $clean['title'] = trim($this->input('title'));
        }
        if ($this->input('parent_id') === '') {
            $clean['parent_id'] = null;
        }
        $this->merge($clean);
    }

    public function rules(): array
    {
        $page = $this->route('page');
        $isHome = $this->input('template') === 'home';

        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => [Rule::requiredIf(! $isHome), 'nullable', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'parent_id' => ['nullable', 'integer', Rule::exists('pages', 'id'), Rule::notIn(array_filter([$page instanceof Page ? $page->id : null]))],
            'template' => ['required', Rule::in(array_keys(PageBlocks::templates()))],
            'status' => ['required', Rule::in(['published', 'draft'])],
            'content' => ['nullable', 'string', 'max:4000000'],
            'content_changed' => ['nullable', 'boolean'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:1000'],
            'noindex' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:-100000', 'max:100000'],
            'blocks_extra' => ['nullable', 'string', 'max:200000', 'json'],
        ] + PageBlocks::rules($this->input('template'));
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'Use lower-case letters, numbers and dashes only.',
            'slug.required' => 'Enter the page address (URL handle).',
            'parent_id.not_in' => 'A page can’t be its own parent.',
            'blocks_extra.json' => 'This isn’t valid JSON – check for missing commas or quotes.',
            'blocks.*.regex' => 'Choose an image from the library (or enter an uploads/… path).',
            'blocks.*.*.regex' => 'Choose an image from the library (or enter an uploads/… path).',
            'blocks.*.*.*.regex' => 'Choose an image from the library (or enter an uploads/… path).',
            'blocks.*.*.*.*.regex' => 'Choose an image from the library (or enter an uploads/… path).',
        ];
    }

    public function attributes(): array
    {
        return ['slug' => 'URL handle', 'parent_id' => 'parent page', 'meta_title' => 'page title', 'blocks_extra' => 'advanced data'];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $errors = $validator->errors();
                /** @var Page|null $page */
                $page = $this->route('page') instanceof Page ? $this->route('page') : null;
                $template = $this->input('template');

                if ($template === 'home' && ! $errors->has('template')) {
                    $otherHome = Page::where(fn ($q) => $q->where('template', 'home')->orWhere('path', ''))
                        ->when($page, fn ($q) => $q->whereKeyNot($page->id))->first(['id', 'title']);
                    if ($otherHome) {
                        $errors->add('template', "“{$otherHome->title}” is already the home page. Change that page’s template first.");
                    }
                }

                if (! $errors->hasAny(['slug', 'parent_id', 'template']) && $template !== 'home') {
                    $path = $this->computedPath();
                    $clash = Page::where('path', $path)->when($page, fn ($q) => $q->whereKeyNot($page->id))->first(['id', 'title']);
                    if ($clash) {
                        $errors->add('slug', "The page “{$clash->title}” already uses /{$path}/. Choose a different URL handle.");
                    }
                    if ($page && $this->filled('parent_id') && $this->isDescendant((int) $this->input('parent_id'), $page)) {
                        $errors->add('parent_id', 'A page can’t be moved under one of its own sub-pages.');
                    }
                    if (preg_match('#^(admin|storage|webhooks|feeds|api|up)(/|$)#', $path)) {
                        $errors->add('slug', 'That address is reserved by the system.');
                    }
                }

                if (! $errors->has('blocks_extra') && filled($this->input('blocks_extra'))) {
                    $decoded = json_decode((string) $this->input('blocks_extra'), true);
                    if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
                        $errors->add('blocks_extra', 'Advanced data must be a JSON object, e.g. {"key": "value"}.');
                    }
                }
            },
        ];
    }

    /** Full URL path the page will get (mirrors Page::saving). */
    public function computedPath(): string
    {
        if ($this->input('template') === 'home') {
            return '';
        }
        $parent = $this->filled('parent_id') ? Page::find((int) $this->input('parent_id')) : null;

        return ($parent && $parent->path !== '' ? $parent->path.'/' : '').$this->input('slug');
    }

    protected function isDescendant(int $candidateId, Page $page): bool
    {
        $seen = [];
        $current = Page::find($candidateId, ['id', 'parent_id']);
        while ($current && ! isset($seen[$current->id])) {
            if ($current->id === $page->id) {
                return true;
            }
            $seen[$current->id] = true;
            $current = $current->parent_id ? Page::find($current->parent_id, ['id', 'parent_id']) : null;
        }

        return false;
    }

    /** Normalised attributes for Page::create()/update(). */
    public function pageData(?Page $page = null): array
    {
        $template = $this->input('template');
        $isHome = $template === 'home';

        $blocks = PageBlocks::clean((array) $this->input('blocks', []), $template);
        $extra = filled($this->input('blocks_extra')) ? (array) json_decode((string) $this->input('blocks_extra'), true) : [];
        $blocks = array_replace($extra, $blocks);
        if ($page && ! $this->has('blocks_extra')) {
            $blocks = array_replace(PageBlocks::extra((array) $page->blocks, $template), $blocks);
        }
        if (($blocks['show_title'] ?? null) === false) {
            unset($blocks['show_title']); // the default
        }

        $data = [
            'title' => $this->input('title'),
            'slug' => $isHome ? ($this->input('slug') ?: ($page?->slug ?: 'home')) : $this->input('slug'),
            'parent_id' => $isHome ? null : ($this->filled('parent_id') ? (int) $this->input('parent_id') : null),
            'template' => $template,
            'status' => $this->input('status'),
            'meta_title' => $this->filled('meta_title') ? trim($this->input('meta_title')) : null,
            'meta_description' => $this->filled('meta_description') ? trim($this->input('meta_description')) : null,
            'noindex' => $this->boolean('noindex'),
            'blocks' => $blocks ?: null,
        ];
        if ($this->has('sort_order')) {
            $data['sort_order'] = (int) $this->input('sort_order');
        }
        // Only replace the stored HTML when it was actually edited (or for a new page)
        if (! $page || $this->boolean('content_changed')) {
            $data['content'] = $this->input('content');
        }

        return $data;
    }
}
