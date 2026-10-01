<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Http\Controllers\Admin\Concerns\AdminIndex;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Models\Category;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\User;
use Pine\Commerce\Services\Admin\CategoryTree;
use Pine\Commerce\Services\Admin\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * Global search (top bar + results page) and the JSON lookups behind the picker components:
 *   GET admin/search            admin.search              HTML results page
 *   GET admin/api/search        admin.search.suggest      top-bar suggestions  {groups: [{key,label,items:[…]}]}
 *   GET admin/api/products      admin.api.products        <x-admin.product-picker>   {data: [{id,label,sub,image}]}
 *   GET admin/api/categories    admin.api.categories      <x-admin.category-picker>
 *   GET admin/api/customers     admin.api.customers       <x-admin.customer-picker>
 */
class SearchController extends Controller
{
    use AdminIndex;

    public function index(Request $request): View
    {
        $q = $this->searchTerm($request);

        return view('commerce::admin.dashboard.search', [
            'q' => $q,
            'groups' => mb_strlen($q) >= 1 ? $this->groups($q, 25) : [],
        ]);
    }

    public function suggest(Request $request): JsonResponse
    {
        $q = $this->searchTerm($request);

        return response()->json(['q' => $q, 'groups' => mb_strlen($q) >= 2 ? $this->groups($q, 5) : []]);
    }

    public function products(Request $request): JsonResponse
    {
        $q = $this->searchTerm($request);
        if ($q === '') {
            return response()->json(['data' => []]);
        }

        $products = $this->productQuery($q)->limit(20)->get(['id', 'name', 'sku', 'status', 'price', 'type', 'stock_status']);

        return response()->json(['data' => $products->map(fn (Product $p) => static::productOption($p))->values()]);
    }

    public function categories(Request $request): JsonResponse
    {
        $q = mb_strtolower($this->searchTerm($request));
        $options = CategoryTree::flat()
            ->filter(fn (Category $c) => $q === '' || str_contains(mb_strtolower($c->name.' '.$c->path), $q))
            ->take(50)
            ->map(fn (Category $c) => static::categoryOption($c))
            ->values();

        return response()->json(['data' => $options]);
    }

    public function customers(Request $request): JsonResponse
    {
        $q = $this->searchTerm($request);
        if ($q === '') {
            return response()->json(['data' => []]);
        }

        $users = $this->customerQuery($q)->withCount('orders')->limit(20)->get(['id', 'name', 'first_name', 'last_name', 'email', 'phone', 'role']);

        return response()->json(['data' => $users->map(fn (User $u) => static::customerOption($u))->values()]);
    }

    // Option shapes shared with the picker components -----------------------------------------

    /** @return array{id:int,label:string,sub:string,image:?string} */
    public static function productOption(Product $product): array
    {
        $image = $product->relationLoaded('images') ? $product->images->first()?->path : null;
        $bits = array_filter([
            $product->sku ? 'SKU '.$product->sku : null,
            $product->price !== null ? money($product->price) : null,
            $product->status !== 'published' ? (OrderStatus::PRODUCT_STATUSES[$product->status] ?? $product->status) : null,
            $product->stock_status === 'outofstock' ? 'Out of stock' : null,
        ]);

        return [
            'id' => $product->id,
            'label' => $product->name,
            'sub' => implode(' · ', $bits),
            'image' => $image ? media_url($image) : null,
        ];
    }

    /** @return array{id:int,label:string,sub:string,image:null} */
    public static function categoryOption(Category $category): array
    {
        return [
            'id' => $category->id,
            'label' => $category->name,
            'sub' => '/'.$category->path.'/',
            'image' => null,
        ];
    }

    /** @return array{id:int,label:string,sub:string,image:null} */
    public static function customerOption(User $user): array
    {
        $orders = $user->orders_count ?? null;

        return [
            'id' => $user->id,
            'label' => $user->full_name ?: $user->email,
            'sub' => implode(' · ', array_filter([$user->email, $orders !== null ? $orders.' '.str('order')->plural($orders) : null, $user->role !== 'customer' ? (User::ROLES[$user->role] ?? $user->role) : null])),
            'image' => null,
        ];
    }

    // Search --------------------------------------------------------------------------------

    /** @return list<array{key:string,label:string,items:list<array>,more:?string}> */
    protected function groups(string $q, int $limit): array
    {
        $groups = [];

        $orders = $this->orderQuery($q)->limit($limit)
            ->get(['id', 'number', 'status', 'total', 'email', 'billing_first_name', 'billing_last_name', 'created_at']);
        if ($orders->isNotEmpty()) {
            $groups[] = [
                'key' => 'orders',
                'label' => 'Orders',
                'items' => $orders->map(fn (Order $o) => [
                    'id' => $o->id,
                    'title' => '#'.$o->number.' · '.($o->billing_name ?: $o->email),
                    'subtitle' => money($o->total).' · '.\Pine\Commerce\View\Components\Admin\Ui::smartDate($o->created_at).' · '.$o->email,
                    'url' => $this->url('admin.orders.show', $o),
                    'icon' => 'inbox-stack',
                    'image' => null,
                    'badge' => ['label' => OrderStatus::label($o->status), 'color' => OrderStatus::color($o->status)],
                ])->values()->all(),
                'more' => Route::has('admin.orders.index') ? route('admin.orders.index', ['q' => $q]) : null,
            ];
        }

        $products = $this->productQuery($q)->limit($limit)->get(['id', 'name', 'sku', 'status', 'price', 'type', 'stock_status']);
        if ($products->isNotEmpty()) {
            $groups[] = [
                'key' => 'products',
                'label' => 'Products',
                'items' => $products->map(function (Product $p) {
                    $option = static::productOption($p);

                    return [
                        'id' => $p->id,
                        'title' => $p->name,
                        'subtitle' => $option['sub'],
                        'url' => $this->url('admin.products.edit', $p),
                        'icon' => 'tag',
                        'image' => $option['image'],
                        'badge' => null,
                    ];
                })->values()->all(),
                'more' => Route::has('admin.products.index') ? route('admin.products.index', ['q' => $q]) : null,
            ];
        }

        $customers = $this->customerQuery($q)->withCount('orders')->limit($limit)
            ->get(['id', 'name', 'first_name', 'last_name', 'email', 'phone', 'role']);
        if ($customers->isNotEmpty()) {
            $groups[] = [
                'key' => 'customers',
                'label' => 'Customers',
                'items' => $customers->map(fn (User $u) => [
                    'id' => $u->id,
                    'title' => $u->full_name ?: $u->email,
                    'subtitle' => static::customerOption($u)['sub'],
                    'url' => $this->url('admin.customers.show', $u),
                    'icon' => 'user',
                    'image' => null,
                    'badge' => null,
                ])->values()->all(),
                'more' => Route::has('admin.customers.index') ? route('admin.customers.index', ['q' => $q]) : null,
            ];
        }

        return $groups;
    }

    protected function orderQuery(string $q): Builder
    {
        $digits = ltrim($q, '#');
        $like = $this->like($q);

        return Order::query()
            ->where(function (Builder $query) use ($q, $digits, $like) {
                if (ctype_digit($digits)) {
                    $query->where('number', $digits)->orWhere('number', 'like', addcslashes($digits, '%_\\').'%');
                }
                $query->orWhere('email', 'like', $like)
                    ->orWhereRaw("CONCAT_WS(' ', billing_first_name, billing_last_name) LIKE ?", [$like])
                    ->orWhereRaw("CONCAT_WS(' ', shipping_first_name, shipping_last_name) LIKE ?", [$like])
                    ->orWhere(function (Builder $names) use ($q) {
                        foreach ($this->words($q) as $word) {
                            $names->whereRaw("CONCAT_WS(' ', billing_first_name, billing_last_name, email) LIKE ?", [$this->like($word)]);
                        }
                    })
                    ->orWhere('billing_postcode', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('transaction_id', $q);
            })
            ->orderByRaw('number = ? DESC', [$digits])
            ->latest('created_at');
    }

    protected function productQuery(string $q): Builder
    {
        $query = Product::query()->with(['images' => fn ($images) => $images->orderBy('sort_order')->limit(1)]);
        foreach ($this->words($q) as $word) {
            $like = $this->like($word);
            $query->where(fn (Builder $w) => $w->where('name', 'like', $like)->orWhere('sku', 'like', $like));
        }

        return $query
            ->orderByRaw('sku = ? DESC', [$q])
            ->orderByRaw('name LIKE ? DESC', [addcslashes($q, '%_\\').'%'])
            ->orderByRaw("status = 'published' DESC")
            ->orderByRaw("stock_status = 'outofstock' ASC")
            ->orderBy('name');
    }

    protected function customerQuery(string $q): Builder
    {
        $query = User::query();
        foreach ($this->words($q) as $word) {
            $like = $this->like($word);
            $query->where(fn (Builder $w) => $w
                ->where('email', 'like', $like)
                ->orWhere('name', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('phone', 'like', $like));
        }

        return $query->orderByRaw("role = 'customer' DESC")->orderBy('first_name')->orderBy('email');
    }

    /** Up to 5 search words ("linen navy" matches "Classic Linen Shirt Navy …"). @return list<string> */
    protected function words(string $q): array
    {
        return array_slice(array_values(array_filter(preg_split('/\s+/', $q) ?: [], fn ($w) => $w !== '')), 0, 5);
    }

    protected function url(string $route, mixed $model): string
    {
        return Route::has($route) ? route($route, $model) : '#';
    }
}
