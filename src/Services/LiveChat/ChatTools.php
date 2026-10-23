<?php

namespace Pine\Commerce\Services\LiveChat;

use Illuminate\Support\Str;
use Pine\Commerce\Models\ChatConversation;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Services\Catalog\ProductListing;
use Pine\Commerce\Services\Catalog\Text;
use Throwable;

/**
 * The live-chat assistant's tools. They only read what the storefront already shows (published products, prices,
 * stock, specifications) – never orders, customers or anything behind a login – plus request_human, which hands the
 * conversation to staff. Results are compact JSON strings.
 */
class ChatTools
{
    public function __construct(protected ChatConversation $conversation, protected LiveChat $chat) {}

    /** @return list<array{name:string, description:string, parameters:array}> */
    public static function definitions(): array
    {
        return [
            [
                'name' => 'search_products',
                'description' => 'Search the shop\'s published products by keywords (brand, model, product type, spec such as "i5 16GB"). '
                    .'Returns up to 8 matches with id, name, price, stock and link. Use it before recommending or pricing anything.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Search words, e.g. "thinkpad t480" or "laptop 16gb ssd".'],
                        'max_price' => ['type' => 'number', 'description' => 'Optional upper price limit in the shop currency.'],
                        'in_stock_only' => ['type' => 'boolean', 'description' => 'Only products that can be ordered now (default true).'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'get_product',
                'description' => 'Full details of one product by its id from search_products: price, sale, stock, description, '
                    .'specifications and options (variations).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['product_id' => ['type' => 'integer', 'description' => 'The product id.']],
                    'required' => ['product_id'],
                ],
            ],
            [
                'name' => 'request_human',
                'description' => 'Hand the chat to a member of staff. Use it when the customer asks for a person, or needs something '
                    .'you cannot do: orders, payments, returns, faults, complaints, account changes or a question you cannot answer.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['reason' => ['type' => 'string', 'description' => 'One line for the staff member: what the customer needs.']],
                    'required' => ['reason'],
                ],
            ],
        ];
    }

    public function run(string $name, array $input): string
    {
        try {
            $result = match ($name) {
                'search_products' => $this->search((string) ($input['query'] ?? ''), isset($input['max_price']) ? (float) $input['max_price'] : null,
                    ($input['in_stock_only'] ?? true) !== false),
                'get_product' => $this->product((int) ($input['product_id'] ?? 0)),
                'request_human' => $this->requestHuman((string) ($input['reason'] ?? '')),
                default => ['error' => 'Unknown tool '.$name],
            };
        } catch (Throwable $e) {
            report($e);
            $result = ['error' => 'The lookup failed. Say you could not check right now and offer to put the customer through to the team.'];
        }

        return (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function search(string $query, ?float $maxPrice, bool $inStockOnly): array
    {
        $query = Str::limit(trim(preg_replace('/\s+/u', ' ', $query)), 80, '');
        if (mb_strlen($query) < 2) {
            return ['products' => [], 'note' => 'Search words needed.'];
        }
        $listing = ProductListing::make()->searching($query);
        $builder = $listing->applySort($listing->filteredQuery())->with(['primaryCategory', 'categories', 'variations']);
        if ($inStockOnly) {
            $builder->inStock();
        }
        if ($maxPrice !== null && $maxPrice > 0) {
            $builder->where('price', '<=', $maxPrice);
        }
        $products = $builder->limit(8)->get();

        return [
            'products' => $products->map(fn (Product $p) => $this->summary($p))->values()->all(),
            'note' => $products->isEmpty() ? 'No matches. Try fewer or different words, or offer to put the customer through to the team.' : null,
        ];
    }

    protected function product(int $id): array
    {
        $product = Product::query()->published()->with(['specs', 'variations', 'categories'])->find($id);
        if (! $product) {
            return ['error' => 'No published product with that id.'];
        }
        $presenter = commerce_presenter();
        $specs = [];
        foreach ($presenter::specRows($product)->take(40) as $row) {
            $specs[(string) $row->label] = Str::limit(trim(strip_tags((string) $row->value)), 200);
        }

        return $this->summary($product) + [
            'description' => Str::limit(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) ($product->short_description ?: $product->description))))), 1500),
            'specifications' => $specs,
            'categories' => $product->categories->pluck('name')->take(5)->values()->all(),
            'options' => $product->type === 'variable' ? $product->variations->where('is_active', true)->take(20)->map(fn ($v) => [
                'name' => Str::limit($v->label, 120),
                'price' => ($price = $v->currentPrice()) !== null ? money($price) : null,
                'in_stock' => $v->isInStock(),
            ])->values()->all() : [],
        ];
    }

    protected function summary(Product $p): array
    {
        $presenter = commerce_presenter();
        $price = $presenter::price($p);
        $sale = $presenter::sale($p);

        return [
            'id' => $p->id,
            'name' => Text::title($p->name),
            'price' => $price !== null ? money($price) : null,
            'was' => $sale && isset($sale['regular']) ? money((float) $sale['regular']) : null,
            'in_stock' => $p->isInStock(),
            'stock' => $p->manage_stock && $p->stock_quantity !== null ? (int) $p->stock_quantity : null,
            'category' => optional($p->primaryCategory ?? $p->categories->first())->name,
            'url' => $p->url,
        ];
    }

    protected function requestHuman(string $reason): array
    {
        $online = $this->chat->handOff($this->conversation, Str::limit(trim($reason), 300), false);

        return $online
            ? ['status' => 'requested', 'staff_online' => true, 'tell_customer' => 'A member of the team has been asked to join; they will reply here shortly.']
            : ['status' => 'requested', 'staff_online' => false, 'tell_customer' => 'Nobody from the team is online right now. The chat will ask for their email so the team can reply.'];
    }
}
