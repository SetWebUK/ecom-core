<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Http\Controllers\Admin\Concerns\AdminIndex;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Http\Requests\Admin\Content\ContentBulkRequest;
use Pine\Commerce\Http\Requests\Admin\Content\PostRequest;
use Pine\Commerce\Models\Post;
use Pine\Commerce\Models\PostCategory;
use Pine\Commerce\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Blog posts: list with status tabs (published / scheduled / draft), create/edit with scheduling and SEO. */
class PostController extends Controller
{
    use AdminIndex;

    public const SORTS = ['title', 'published_at', 'updated_at'];

    public const STATES = ['published' => 'Published', 'scheduled' => 'Scheduled', 'draft' => 'Draft'];

    public function index(Request $request): View
    {
        $status = $this->filterValue($request, 'status', self::STATES) ?? 'all';
        $categories = PostCategory::query()->orderBy('name')->pluck('name', 'id');
        $category = $this->filterValue($request, 'category', $categories->all());
        $q = $this->searchTerm($request);
        [$sort, $direction] = $this->sorting($request, self::SORTS, 'published_at', 'desc');

        $posts = Post::query()
            ->select(['id', 'post_category_id', 'author_id', 'title', 'slug', 'featured_image', 'status', 'published_at', 'updated_at'])
            ->with(['category:id,name', 'author:id,name,first_name,last_name'])
            ->when($q !== '', fn (Builder $query) => $query->where(fn (Builder $w) => $w->where('title', 'like', $this->like($q))->orWhere('slug', 'like', $this->like($q))))
            ->when($category, fn (Builder $query) => $query->where('post_category_id', (int) $category))
            ->tap(fn (Builder $query) => static::scopeState($query, $status))
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        $counts = Post::query()->selectRaw(
            "count(*) as total, sum(status = 'published' and (published_at is null or published_at <= ?)) as published, sum(status = 'published' and published_at > ?) as scheduled, sum(status <> 'published') as draft",
            [now(), now()]
        )->first();

        return view('commerce::admin.posts.index', [
            'posts' => $posts,
            'q' => $q,
            'status' => $status,
            'tabs' => [
                'all' => ['label' => 'All', 'count' => (int) $counts->total],
                'published' => ['label' => 'Published', 'count' => (int) $counts->published],
                'scheduled' => ['label' => 'Scheduled', 'count' => (int) $counts->scheduled],
                'draft' => ['label' => 'Drafts', 'count' => (int) $counts->draft],
            ],
            'categoryOptions' => $categories->all(),
            'chips' => array_filter(['category' => $category ? 'Category: '.$categories[(int) $category] : null]),
        ]);
    }

    public function create(): View
    {
        return $this->form(new Post(['status' => 'draft', 'author_id' => auth()->id(), 'post_category_id' => PostCategory::query()->orderByDesc('id')->value('id')]));
    }

    public function store(PostRequest $request): RedirectResponse
    {
        $post = Post::create($request->postData());

        return redirect()->route('admin.posts.edit', $post)->with('success', match (static::state($post)) {
            'published' => 'Post published.',
            'scheduled' => 'Post scheduled for '.\Pine\Commerce\Services\Admin\LocalTime::format($post->published_at, 'j M Y, H:i').'.',
            default => 'Post saved as a draft.',
        });
    }

    public function edit(Post $post): View
    {
        return $this->form($post);
    }

    public function update(PostRequest $request, Post $post): RedirectResponse
    {
        $post->update($request->postData($post));

        return redirect()->route('admin.posts.edit', $post)->with('success', static::state($post) === 'scheduled'
            ? 'Post saved – it goes live on '.\Pine\Commerce\Services\Admin\LocalTime::format($post->published_at, 'j M Y \a\t H:i').'.'
            : 'Post saved.');
    }

    public function destroy(Post $post): RedirectResponse
    {
        $post->delete();

        return redirect()->route('admin.posts.index')->with('success', "Post “{$post->title}” deleted.");
    }

    public function bulk(ContentBulkRequest $request): RedirectResponse
    {
        $query = Post::whereIn('id', $request->ids());
        $action = $request->input('action');
        $count = match ($action) {
            'publish' => (clone $query)->where('status', '!=', 'published')->update(['status' => 'published', 'published_at' => now(), 'updated_at' => now()])
                + (clone $query)->where('status', 'published')->count(),
            'draft' => $query->update(['status' => 'draft', 'updated_at' => now()]),
            'delete' => $query->delete(),
        };
        $verb = ['publish' => 'published', 'draft' => 'unpublished', 'delete' => 'deleted'][$action];

        return back()->with('success', $count.' '.Str::plural('post', $count).' '.$verb.'.');
    }

    /** published | scheduled | draft */
    public static function state(Post $post): string
    {
        if ($post->status !== 'published') {
            return 'draft';
        }

        return $post->published_at && $post->published_at->isFuture() ? 'scheduled' : 'published';
    }

    public static function scopeState(Builder $query, string $state): void
    {
        match ($state) {
            'published' => $query->where('status', 'published')->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now())),
            'scheduled' => $query->where('status', 'published')->where('published_at', '>', now()),
            'draft' => $query->where('status', '!=', 'published'),
            default => null,
        };
    }

    protected function form(Post $post): View
    {
        return view('commerce::admin.posts.form', [
            'post' => $post,
            'categories' => PostCategory::query()->orderBy('name')->pluck('name', 'id')->all(),
            'authors' => User::query()->whereIn('role', ['admin', 'manager'])->orderBy('name')->get(['id', 'name', 'first_name', 'last_name', 'email'])
                ->mapWithKeys(fn (User $u) => [$u->id => $u->full_name])->all(),
            'state' => $post->exists ? static::state($post) : null,
            // the active theme's editor_css (theme.json) + the admin's editor tweaks
            'contentCss' => implode(',', array_merge(theme()->editorCssUrls(), [commerce_admin_asset('css/editor-site.css', absolute: false)])),
        ]);
    }
}
