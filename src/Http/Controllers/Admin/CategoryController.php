<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Http\Controllers\Admin\Concerns\AdminIndex;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Http\Requests\Admin\Catalogue\CategoryRequest;
use Pine\Commerce\Models\Category;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Services\Admin\Catalogue\UrlRedirects;
use Pine\Commerce\Services\Admin\CatalogueTools;
use Pine\Commerce\Services\Admin\CategoryTree;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Product categories: drag-and-drop tree (reorder + re-parent), create/edit with URL-change redirects, delete with
 * "move products to".
 *
 *   GET  admin/categories                       admin.categories.index
 *   POST admin/categories/reorder               admin.categories.reorder      JSON {nodes:[{id,parent_id}], redirects}
 *   POST admin/categories/{id}/visibility       admin.categories.visibility   JSON toggle "shown in the shop"
 *   GET|POST|PUT|DELETE resource routes         admin.categories.*            (no show)
 */
class CategoryController extends Controller
{
    use AdminIndex;

    public function index(Request $request): View
    {
        $q = $this->searchTerm($request);
        $counts = CategoryTree::productCounts();
        $matches = null;
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $matches = CategoryTree::flat()
                ->filter(fn (Category $c) => str_contains(mb_strtolower($c->name.' '.$c->path), $needle))
                ->values();
        }

        return view('commerce::admin.categories.index', [
            'tree' => $matches === null ? CategoryTree::nested() : [],
            'matches' => $matches,
            'counts' => $counts,
            'total' => CategoryTree::flat()->count(),
            'q' => $q,
        ]);
    }

    public function create(Request $request): View
    {
        $parentId = (int) $request->query('parent');
        $category = new Category([
            'is_visible' => true,
            'show_in_menu' => true,
            'parent_id' => $parentId && CategoryTree::flat()->contains('id', $parentId) ? $parentId : null,
        ]);

        return view('commerce::admin.categories.form', $this->formData($category));
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        $data = $request->categoryData();
        $category = new Category($data);
        $category->sort_order = CategoryTree::nextSortOrder($data['parent_id']);
        $category->save();
        CatalogueTools::flushStorefrontCaches();

        return redirect()->route('admin.categories.edit', $category)->with('success', "Category “{$category->name}” created.");
    }

    public function edit(Category $category): View
    {
        return view('commerce::admin.categories.form', $this->formData($category));
    }

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        $data = $request->categoryData();
        $pathChanges = $request->path() !== $category->path;
        $snapshot = $pathChanges && $request->boolean('create_redirects') ? UrlRedirects::snapshot([$category->id]) : null;

        DB::transaction(function () use ($category, $data) {
            if ($category->parent_id !== $data['parent_id']) {
                $category->sort_order = CategoryTree::nextSortOrder($data['parent_id']);
            }
            $category->fill($data)->save(); // Category::saved re-paths the sub-categories
        });
        CatalogueTools::flushStorefrontCaches();

        $message = 'Category saved.';
        if ($snapshot) {
            $count = UrlRedirects::fromSnapshot($snapshot);
            $message .= $count ? " {$count} old ".Str::plural('address', $count).' now redirect to the new ones.' : '';
        } elseif ($pathChanges) {
            $message .= ' Its web address changed to /'.$category->path.'/.';
        }

        return redirect()->route('admin.categories.edit', $category)->with('success', $message);
    }

    public function destroy(Request $request, Category $category): RedirectResponse
    {
        $productCount = DB::table('category_product')->where('category_id', $category->id)->count();
        $validated = $request->validate([
            'move_to' => [Rule::requiredIf($productCount > 0), 'nullable', 'integer', Rule::exists('categories', 'id'), Rule::notIn([$category->id])],
            'create_redirects' => ['boolean'],
        ], [
            'move_to.required' => 'Choose where this category’s products should go.',
            'move_to.not_in' => 'Choose a different category.',
        ]);
        $target = ! empty($validated['move_to']) ? Category::find((int) $validated['move_to']) : null;
        if ($target && in_array($target->id, CategoryTree::descendantIds($category->id), true)) {
            return back()->withErrors(['move_to' => 'Choose a category outside the one you’re deleting.'])->with('error', 'Choose a category outside the one you’re deleting.');
        }

        $redirects = $request->boolean('create_redirects', true);
        $snapshot = $redirects ? UrlRedirects::snapshot([$category->id]) : null;
        $childCount = $category->children()->count();
        $name = $category->name;

        DB::transaction(function () use ($category, $target) {
            if ($target) {
                $productIds = DB::table('category_product')->where('category_id', $category->id)->pluck('product_id')->all();
                DB::table('category_product')->insertOrIgnore(array_map(fn ($id) => ['category_id' => $target->id, 'product_id' => $id], $productIds));
                Product::withTrashed()->where('primary_category_id', $category->id)->update(['primary_category_id' => $target->id]);
            } else {
                Product::withTrashed()->where('primary_category_id', $category->id)->update(['primary_category_id' => null]);
            }
            // Sub-categories move up one level
            foreach ($category->children()->get() as $child) {
                $child->parent_id = $category->parent_id;
                $child->save();
            }
            $category->delete();
        });
        CatalogueTools::flushStorefrontCaches();

        $message = "Category “{$name}” deleted.";
        if ($target && $productCount) {
            $message .= " Its {$productCount} ".Str::plural('product', $productCount)." moved to “{$target->name}”.";
        }
        if ($childCount) {
            $message .= " {$childCount} sub-".Str::plural('category', $childCount).' moved up a level.';
        }
        if ($snapshot) {
            $fallback = $target?->path ?? ($category->parent_id ? Category::find($category->parent_id)?->path : null) ?? '';
            $count = UrlRedirects::fromSnapshot($snapshot, [$category->id => $fallback]);
            if ($count) {
                $message .= " {$count} old ".Str::plural('address', $count).' redirect to the new ones.';
            }
        }

        return redirect()->route('admin.categories.index')->with('success', $message);
    }

    /** Drag-and-drop: new parent + order for every category in the tree. */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nodes' => ['required', 'array', 'max:2000'],
            'nodes.*.id' => ['required', 'integer', 'distinct'],
            'nodes.*.parent_id' => ['nullable', 'integer'],
            'redirects' => ['boolean'],
        ]);
        $all = Category::query()->get(['id', 'parent_id', 'sort_order', 'path', 'slug'])->keyBy('id');
        $nodes = collect($validated['nodes'])->map(fn ($n) => ['id' => (int) $n['id'], 'parent_id' => isset($n['parent_id']) ? (int) $n['parent_id'] : null]);

        // Every id must exist, every parent must exist, and the result must be a tree
        $parents = $all->map(fn ($c) => $c->parent_id)->all();
        foreach ($nodes as $node) {
            if (! $all->has($node['id']) || ($node['parent_id'] !== null && ! $all->has($node['parent_id']))) {
                return response()->json(['message' => 'The categories changed in the meantime – reload the page and try again.'], 422);
            }
            $parents[$node['id']] = $node['parent_id'];
        }
        foreach (array_keys($parents) as $id) {
            $seen = [];
            for ($cursor = $id; $cursor !== null; $cursor = $parents[$cursor] ?? null) {
                if (isset($seen[$cursor])) {
                    return response()->json(['message' => 'A category can’t be moved inside itself.'], 422);
                }
                $seen[$cursor] = true;
            }
        }

        $moved = $nodes->filter(fn ($n) => $all[$n['id']]->parent_id !== $n['parent_id'])->pluck('id')->all();
        $snapshot = $moved && $request->boolean('redirects', true) ? UrlRedirects::snapshot($moved) : null;

        DB::transaction(function () use ($nodes, $all) {
            $positions = [];
            foreach ($nodes as $node) {
                $position = $positions[$node['parent_id'] ?? 0] = ($positions[$node['parent_id'] ?? 0] ?? -1) + 1;
                $category = $all[$node['id']];
                if ($category->parent_id !== $node['parent_id']) {
                    // Full model save so the URL path (and the sub-categories' paths) follow the new parent
                    $model = Category::find($category->id);
                    $model->parent_id = $node['parent_id'];
                    $model->sort_order = $position;
                    $model->save();
                } elseif ((int) $category->sort_order !== $position) {
                    Category::query()->whereKey($category->id)->update(['sort_order' => $position]);
                }
            }
        });
        CatalogueTools::flushStorefrontCaches();

        $message = $moved ? 'Category moved.' : 'New order saved.';
        if ($snapshot && ($count = UrlRedirects::fromSnapshot($snapshot))) {
            $message .= " {$count} old ".Str::plural('address', $count).' now redirect to the new ones.';
        }
        CategoryTree::flush();

        return response()->json([
            'message' => $message,
            'paths' => Category::query()->pluck('path', 'id'),
        ]);
    }

    public function visibility(Request $request, Category $category): JsonResponse
    {
        $visible = $request->has('visible') ? $request->boolean('visible') : ! $category->is_visible;
        $category->forceFill(['is_visible' => $visible])->saveQuietly();
        CatalogueTools::flushStorefrontCaches();

        return response()->json([
            'visible' => $category->is_visible,
            'message' => $category->is_visible ? "“{$category->name}” is shown in the shop." : "“{$category->name}” is hidden from the shop.",
        ]);
    }

    protected function formData(Category $category): array
    {
        $productCount = $category->exists ? DB::table('category_product')->join('products', 'products.id', '=', 'category_product.product_id')
            ->where('category_product.category_id', $category->id)->whereNull('products.deleted_at')->count() : 0;
        $subtreeIds = $category->exists ? CategoryTree::descendantIds($category->id) : [];
        $affectedProducts = $category->exists ? DB::table('category_product')->whereIn('category_id', $subtreeIds)->distinct()->count('product_id') : 0;

        return [
            'category' => $category,
            'parentOptions' => CategoryTree::options($category->exists ? $category->id : null),
            'parentPaths' => CategoryTree::flat()->pluck('path', 'id'),
            'moveOptions' => collect(CategoryTree::options())->except($subtreeIds)->all(),
            'productCount' => $productCount,
            'childCount' => $category->exists ? max(0, count($subtreeIds) - 1) : 0,
            'affectedProducts' => $affectedProducts,
            'ancestors' => $category->exists ? CategoryTree::ancestors($category->id) : [],
        ];
    }
}
