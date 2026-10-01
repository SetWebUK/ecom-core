<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Http\Controllers\Admin\Concerns\AdminIndex;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Http\Requests\Admin\Content\ContentBulkRequest;
use Pine\Commerce\Http\Requests\Admin\Content\PageRequest;
use Pine\Commerce\Models\Page;
use Pine\Commerce\Services\Admin\PageBlocks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Content pages: list, edit (rich editor with the storefront's CSS, SEO, structured blocks, the home page builder).
 */
class PageController extends Controller
{
    use AdminIndex;

    public const SORTS = ['title', 'path', 'template', 'updated_at', 'created_at'];

    /** Addresses the shop answers itself – a Page row with the same path is never shown. */
    public const SYSTEM_PATHS = [
        'basket' => 'the basket',
        'checkout' => 'the checkout',
        'my-account' => 'customer accounts',
        'shop' => 'the shop listing',
    ];

    public function index(Request $request): View
    {
        $status = $this->filterValue($request, 'status', ['published' => 1, 'draft' => 1]) ?? 'all';
        $template = $this->filterValue($request, 'template', PageBlocks::templates());
        $q = $this->searchTerm($request);
        [$sort, $direction] = $this->sorting($request, self::SORTS, 'path');

        $pages = Page::query()
            ->select(['id', 'parent_id', 'title', 'slug', 'path', 'template', 'status', 'noindex', 'wp_id', 'updated_at', 'created_at'])
            ->when($q !== '', fn (Builder $query) => $query->where(fn (Builder $w) => $w
                ->where('title', 'like', $this->like($q))
                ->orWhere('path', 'like', $this->like(trim($q, '/')))))
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->when($template, fn (Builder $query) => $query->where('template', $template))
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate($this->perPage($request, 50))
            ->withQueryString();

        $counts = Page::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return view('commerce::admin.pages.index', [
            'pages' => $pages,
            'q' => $q,
            'status' => $status,
            'tabs' => [
                'all' => ['label' => 'All', 'count' => (int) $counts->sum()],
                'published' => ['label' => 'Published', 'count' => (int) ($counts['published'] ?? 0)],
                'draft' => ['label' => 'Drafts', 'count' => (int) ($counts['draft'] ?? 0)],
            ],
            'chips' => array_filter(['template' => $template ? 'Template: '.PageBlocks::templates()[$template]['label'] : null]),
            'templateOptions' => collect(PageBlocks::templates())->map(fn ($t) => $t['label'])->all(),
        ]);
    }

    public function create(Request $request): View
    {
        $parent = $request->integer('parent') ? Page::find($request->integer('parent')) : null;

        return $this->form(new Page(['template' => 'default', 'status' => 'draft', 'parent_id' => $parent?->id]));
    }

    public function store(PageRequest $request): RedirectResponse
    {
        $page = Page::create($request->pageData());

        return redirect()->route('admin.pages.edit', $page)->with('success', $page->status === 'published'
            ? "Page “{$page->title}” published."
            : "Page “{$page->title}” saved as a draft.");
    }

    public function edit(Page $page): View
    {
        return $this->form($page);
    }

    public function update(PageRequest $request, Page $page): RedirectResponse
    {
        $oldPath = $page->path;
        DB::transaction(function () use ($request, $page, $oldPath) {
            $page->update($request->pageData($page));
            if ($page->path !== $oldPath) {
                $this->refreshChildPaths($page);
            }
        });

        $message = 'Page saved.';
        if ($page->path !== $oldPath && $oldPath !== '') {
            $message .= " Its address changed from /{$oldPath}/ to /{$page->path}/ – add a redirect if the old address is linked anywhere.";
        }

        return redirect()->route('admin.pages.edit', $page)->with('success', $message);
    }

    public function destroy(Page $page): RedirectResponse
    {
        if ($page->template === 'home' || $page->path === '') {
            return back()->with('error', 'The home page can’t be deleted.');
        }
        $title = $page->title;
        $page->delete();

        return redirect()->route('admin.pages.index')->with('success', "Page “{$title}” deleted.");
    }

    public function duplicate(Page $page): RedirectResponse
    {
        if ($page->template === 'home') {
            return back()->with('error', 'The home page can’t be duplicated – there can only be one.');
        }
        $slug = $page->slug.'-copy';
        for ($i = 2; Page::where('path', ($page->parent?->path ? $page->parent->path.'/' : '').$slug)->exists(); $i++) {
            $slug = $page->slug.'-copy-'.$i;
        }
        $copy = $page->replicate(['path']); // keeps wp_id so the page's legacy Elementor CSS still applies
        $copy->forceFill(['title' => $page->title.' (copy)', 'slug' => $slug, 'status' => 'draft'])->save();

        return redirect()->route('admin.pages.edit', $copy)->with('success', 'Copy created as a draft. Change its title and address, then publish it.');
    }

    public function bulk(ContentBulkRequest $request): RedirectResponse
    {
        $ids = $request->ids();
        $action = $request->input('action');

        $count = match ($action) {
            'publish' => Page::whereIn('id', $ids)->update(['status' => 'published', 'updated_at' => now()]),
            'draft' => Page::whereIn('id', $ids)->where('template', '!=', 'home')->update(['status' => 'draft', 'updated_at' => now()]),
            'delete' => Page::whereIn('id', $ids)->where('template', '!=', 'home')->where('path', '!=', '')->get()->each->delete()->count(),
        };
        $noun = Str::plural('page', $count);
        $verb = ['publish' => 'published', 'draft' => 'unpublished', 'delete' => 'deleted'][$action];

        return back()->with('success', "{$count} {$noun} {$verb}.");
    }

    protected function form(Page $page): View
    {
        $blocks = PageBlocks::forEditing($page);
        $excluded = $page->exists ? $this->subtreeIds($page) : [];
        $parents = Page::query()->where('path', '!=', '')->whereNotIn('id', $excluded)
            ->orderBy('path')->get(['id', 'title', 'path'])
            ->mapWithKeys(fn (Page $p) => [$p->id => $p->title.'  (/'.$p->path.'/)'])->all();
        $homeTaken = Page::where(fn ($q) => $q->where('template', 'home')->orWhere('path', ''))
            ->when($page->exists, fn ($q) => $q->whereKeyNot($page->id))->exists();

        return view('commerce::admin.pages.form', [
            'page' => $page,
            'blocks' => $blocks,
            'extra' => PageBlocks::extra($blocks, $page->template),
            'parents' => $parents,
            'parentPaths' => Page::query()->whereIn('id', array_keys($parents))->pluck('path', 'id')->all(),
            'templates' => collect(PageBlocks::templates())
                ->reject(fn ($t, $key) => ($key === 'home' && $homeTaken) || ($key === 'blog' && $page->path !== 'blog' && $page->template !== 'blog'))
                ->all(),
            'systemNote' => self::SYSTEM_PATHS[$page->path] ?? null,
            'contentCss' => $this->contentCss($page),
        ]);
    }

    /** Storefront stylesheets for the editor (the active theme's editor_css), so editing looks like the live page. */
    protected function contentCss(Page $page): string
    {
        // the active theme's editor_css (theme.json), plus the page's legacy Elementor CSS when it was published
        $extra = $page->wp_id && commerce_feature('legacy_content') ? ['css/elementor/post-'.(int) $page->wp_id.'.css'] : [];

        return implode(',', array_merge(theme()->editorCssUrls($extra), [commerce_admin_asset('css/editor-site.css', absolute: false)]));
    }

    /** Ids of a page and all pages below it. */
    protected function subtreeIds(Page $page): array
    {
        $ids = [$page->id];
        $frontier = [$page->id];
        while ($frontier) {
            $frontier = Page::whereIn('parent_id', $frontier)->whereNotIn('id', $ids)->pluck('id')->all();
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    /** After a page's address changes, re-save its sub-pages so their paths follow (Page::saving builds the path). */
    protected function refreshChildPaths(Page $page): void
    {
        foreach (Page::where('parent_id', $page->id)->get() as $child) {
            $old = $child->path;
            $child->save(); // the saving hook rebuilds the path from the parent's
            if ($child->path !== $old) {
                $this->refreshChildPaths($child);
            }
        }
    }
}
