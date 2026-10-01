<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Http\Requests\Admin\Content\PostCategoryRequest;
use Pine\Commerce\Models\PostCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** Blog categories (/blog/category/{slug}/). */
class PostCategoryController extends Controller
{
    public function index(): View
    {
        return view('commerce::admin.post-categories.index', [
            'categories' => PostCategory::query()->withCount('posts')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('commerce::admin.post-categories.form', ['category' => new PostCategory]);
    }

    public function store(PostCategoryRequest $request): RedirectResponse
    {
        $category = PostCategory::create($request->categoryData());

        return redirect()->route('admin.post-categories.index')->with('success', "Blog category “{$category->name}” added.");
    }

    public function edit(PostCategory $postCategory): View
    {
        return view('commerce::admin.post-categories.form', ['category' => $postCategory->loadCount('posts')]);
    }

    public function update(PostCategoryRequest $request, PostCategory $postCategory): RedirectResponse
    {
        $postCategory->update($request->categoryData());

        return redirect()->route('admin.post-categories.index')->with('success', 'Blog category saved.');
    }

    public function destroy(PostCategory $postCategory): RedirectResponse
    {
        $count = $postCategory->posts()->count();
        $postCategory->delete(); // posts keep existing, without a category (FK nullOnDelete)

        return redirect()->route('admin.post-categories.index')->with('success', "Blog category “{$postCategory->name}” deleted".($count ? " – its {$count} posts are now uncategorised." : '.'));
    }
}
