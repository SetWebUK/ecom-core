<?php

namespace Pine\Commerce\Services\Admin\Catalogue;

use Pine\Commerce\Models\Category;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Services\Admin\CatalogueTools;
use Pine\Commerce\Services\Admin\CategoryTree;
use Pine\Commerce\Services\Admin\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Pine\Commerce\Support\Sql;

/**
 * Filters of the Products list (status tab, search, category incl. sub-categories, stock, type, price range,
 * condition, brand), shared by the list, its CSV export and the tab counts. Every value is whitelisted.
 */
class ProductFilter
{
    public const TABS = [
        'all' => 'All',
        'active' => 'Active',
        'draft' => 'Draft',
        'outofstock' => 'Out of stock',
        'onsale' => 'On sale',
        'featured' => 'Featured',
        'trashed' => 'Deleted',
    ];

    public const STOCK = [
        'instock' => 'In stock',
        'low' => 'Low stock',
        'outofstock' => 'Out of stock',
        'onbackorder' => 'On backorder',
    ];

    public const TYPES = ['simple' => 'Simple product', 'variable' => 'Product with variants'];

    public string $tab = 'all';

    public string $q = '';

    public ?int $category = null;

    public ?string $stock = null;

    public ?string $type = null;

    public ?float $priceMin = null;

    public ?float $priceMax = null;

    public ?string $condition = null;

    public ?string $brand = null;

    /** @var list<int> */
    public array $ids = [];

    public static function fromRequest(Request $request): static
    {
        $f = new static;
        $tab = $request->query('status');
        $f->tab = is_string($tab) && array_key_exists($tab, self::TABS) ? $tab : 'all';
        $q = $request->query('q');
        $f->q = is_string($q) ? mb_substr(trim($q), 0, 100) : '';
        $category = $request->query('category');
        $f->category = is_scalar($category) && ctype_digit((string) $category) && CategoryTree::flat()->contains('id', (int) $category) ? (int) $category : null;
        $stock = $request->query('stock');
        $f->stock = is_string($stock) && array_key_exists($stock, self::STOCK) ? $stock : null;
        $type = $request->query('type');
        $f->type = is_string($type) && array_key_exists($type, self::TYPES) ? $type : null;
        $f->priceMin = static::money($request->query('price_min'));
        $f->priceMax = static::money($request->query('price_max'));
        $condition = $request->query('condition');
        $f->condition = is_string($condition) && commerce_feature('product_condition', false)
            && in_array($condition, static::conditionOptions(), true) ? $condition : null;
        $brand = $request->query('brand');
        $f->brand = is_string($brand) && commerce_feature('product_brand', false)
            && in_array($brand, static::brandOptions(), true) ? $brand : null;
        $ids = $request->query('ids');
        if (is_string($ids) && $ids !== '') {
            $f->ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', $ids))))), 0, 1000);
        }

        return $f;
    }

    protected static function money(mixed $value): ?float
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim(str_replace(['£', ',', ' '], '', (string) $value));

        return is_numeric($value) && (float) $value >= 0 ? round((float) $value, 2) : null;
    }

    /** Base query for the current filters (tab included). */
    public function query(): Builder
    {
        $query = Product::query();
        if ($this->tab === 'trashed') {
            $query->onlyTrashed();
        }
        static::applyTab($query, $this->tab);

        if ($this->ids) {
            $query->whereIn('products.id', $this->ids);
        }
        if ($this->q !== '') {
            foreach (array_slice(preg_split('/\s+/', $this->q) ?: [], 0, 5) as $word) {
                $like = '%'.addcslashes($word, '%_\\').'%';
                $query->where(fn (Builder $w) => $w
                    ->where('products.name', 'like', $like)
                    ->orWhere('products.sku', 'like', $like)
                    ->orWhereExists(fn ($v) => $v->select(DB::raw(1))->from('product_variations')
                        ->whereColumn('product_variations.product_id', 'products.id')->where('product_variations.sku', 'like', $like)));
            }
        }
        if ($this->category) {
            $ids = CategoryTree::descendantIds($this->category);
            $query->whereExists(fn ($sub) => $sub->select(DB::raw(1))->from('category_product')
                ->whereColumn('category_product.product_id', 'products.id')->whereIn('category_product.category_id', $ids));
        }
        if ($this->stock) {
            static::applyStock($query, $this->stock);
        }
        if ($this->type) {
            $query->where('products.type', $this->type);
        }
        if ($this->priceMin !== null) {
            $query->where('products.price', '>=', $this->priceMin);
        }
        if ($this->priceMax !== null) {
            $query->where('products.price', '<=', $this->priceMax);
        }
        if ($this->condition) {
            $query->where('products.condition', $this->condition);
        }
        if ($this->brand) {
            $query->where('products.brand', $this->brand);
        }

        return $query;
    }

    public static function applyTab(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'active' => $query->where('products.status', 'published'),
            'draft' => $query->where('products.status', 'draft'),
            'outofstock' => $query->where('products.stock_status', 'outofstock'),
            'featured' => $query->where('products.is_featured', true),
            'onsale' => $query->whereRaw(static::onSaleSql(), static::onSaleBindings()),
            default => $query,
        };
    }

    public static function applyStock(Builder $query, string $stock): Builder
    {
        if ($stock === 'low') {
            return $query->whereRaw(static::lowStockSql(), [CatalogueTools::lowStockThreshold(), CatalogueTools::lowStockThreshold()]);
        }

        return $query->where('products.stock_status', $stock);
    }

    /** Products whose tracked quantity is at or below their (or the store's) low-stock threshold – simple or any variant. */
    public static function lowStockSql(): string
    {
        return Sql::qualify("((products.type <> 'variable' AND products.manage_stock = 1 AND products.stock_quantity IS NOT NULL AND products.stock_quantity > 0
                  AND products.stock_quantity <= COALESCE(products.low_stock_threshold, ?))
              OR (products.type = 'variable' AND EXISTS (SELECT 1 FROM ".Sql::table('product_variations')." lv WHERE lv.product_id = products.id AND lv.is_active = 1
                  AND lv.manage_stock = 1 AND lv.stock_quantity IS NOT NULL AND lv.stock_quantity > 0 AND lv.stock_quantity <= COALESCE(products.low_stock_threshold, ?))))", ['products']);
    }

    /** SQL matching Product::isOnSale() (simple) or any active variant with a sale price (variable). */
    public static function onSaleSql(): string
    {
        return Sql::qualify("((products.type <> 'variable' AND products.sale_price IS NOT NULL AND products.sale_price < products.regular_price
                  AND (products.sale_starts_at IS NULL OR products.sale_starts_at <= ?) AND (products.sale_ends_at IS NULL OR products.sale_ends_at >= ?))
              OR (products.type = 'variable' AND EXISTS (SELECT 1 FROM ".Sql::table('product_variations')." sv WHERE sv.product_id = products.id AND sv.is_active = 1
                  AND sv.sale_price > 0 AND sv.sale_price < sv.regular_price)))", ['products']);
    }

    public static function onSaleBindings(): array
    {
        $now = now()->toDateTimeString();

        return [$now, $now];
    }

    /** Tab counts in one query. @return array<string,int> */
    public static function counts(): array
    {
        $row = DB::table('products')->selectRaw(
            'SUM(deleted_at IS NULL) AS c_all,
             SUM(deleted_at IS NULL AND status = \'published\') AS c_active,
             SUM(deleted_at IS NULL AND status = \'draft\') AS c_draft,
             SUM(deleted_at IS NULL AND stock_status = \'outofstock\') AS c_outofstock,
             SUM(deleted_at IS NULL AND is_featured = 1) AS c_featured,
             SUM(deleted_at IS NULL AND '.static::onSaleSql().') AS c_onsale,
             SUM(deleted_at IS NOT NULL) AS c_trashed',
            static::onSaleBindings()
        )->first();

        $counts = [];
        foreach (array_keys(self::TABS) as $tab) {
            $counts[$tab] = (int) ($row->{'c_'.$tab} ?? 0);
        }

        return $counts;
    }

    /** @return list<string> */
    public static function conditionOptions(): array
    {
        static $options = null;

        return $options ??= Product::query()->whereNotNull('condition')->where('condition', '<>', '')->distinct()->orderBy('condition')->pluck('condition')->all();
    }

    /** @return list<string> */
    public static function brandOptions(): array
    {
        static $options = null;

        return $options ??= Product::query()->whereNotNull('brand')->where('brand', '<>', '')->distinct()->orderBy('brand')->pluck('brand')->all();
    }

    /** Removable chips for the active filters: [query param => label]. */
    public function chips(): array
    {
        $category = $this->category ? CategoryTree::flat()->firstWhere('id', $this->category) : null;

        return array_filter([
            'category' => $category ? 'Category: '.$category->name : null,
            'stock' => $this->stock ? 'Stock: '.self::STOCK[$this->stock] : null,
            'type' => $this->type ? self::TYPES[$this->type] : null,
            'price_min' => $this->priceMin !== null ? 'Price from '.money($this->priceMin) : null,
            'price_max' => $this->priceMax !== null ? 'Price up to '.money($this->priceMax) : null,
            'condition' => $this->condition ? 'Condition: '.$this->condition : null,
            'brand' => $this->brand ? 'Brand: '.$this->brand : null,
        ]);
    }

    public function isFiltered(): bool
    {
        return $this->q !== '' || $this->chips() !== [] || $this->tab !== 'all' || $this->ids !== [];
    }

    /** @return array<string,string> */
    public static function stockStatusOptions(): array
    {
        return OrderStatus::STOCK_STATUSES;
    }

    public function categoryModel(): ?Category
    {
        return $this->category ? CategoryTree::flat()->firstWhere('id', $this->category) : null;
    }
}
