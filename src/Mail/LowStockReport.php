<?php

namespace Pine\Commerce\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Pine\Commerce\Models\Product;

/**
 * Shop: the daily low-stock email (scheduled task inventory.low-stock-email, setting "scheduler.low_stock_email").
 * Published products with managed stock at or below their low-stock threshold (product override, else setting
 * "inventory.low_stock_threshold"), and published sold-out products. Same branded wrapper as the order emails.
 */
class LowStockReport extends Mailable
{
    use Queueable;

    public const LIMIT = 100;

    public Collection $low;

    public Collection $out;

    public function __construct()
    {
        $threshold = max(0, (int) setting('inventory.low_stock_threshold', 2));
        $this->low = Product::query()->published()
            ->where('manage_stock', true)->where('stock_quantity', '>', 0)
            ->whereRaw('stock_quantity <= COALESCE(low_stock_threshold, ?)', [$threshold])
            ->orderBy('stock_quantity')->orderBy('name')->limit(self::LIMIT)
            ->get(['id', 'name', 'sku', 'stock_quantity']);
        $this->out = Product::query()->published()->where('stock_status', 'outofstock')
            ->orderBy('name')->limit(self::LIMIT)->get(['id', 'name', 'sku']);
    }

    public function isEmpty(): bool
    {
        return $this->low->isEmpty() && $this->out->isEmpty();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Stock report: '.$this->low->count().' low, '.$this->out->count().' sold out');
    }

    public function content(): Content
    {
        return new Content(view: 'commerce::emails.low-stock-report', with: [
            'heading' => 'Daily stock report',
            'storeName' => (string) setting('store.name', config('app.name')),
            'low' => $this->low,
            'out' => $this->out,
            'limit' => self::LIMIT,
            'productUrl' => fn (Product $p) => Route::has('admin.products.edit') ? route('admin.products.edit', $p) : null,
            'settingsUrl' => Route::has('admin.settings.edit') ? route('admin.settings.edit', 'automation') : null,
        ]);
    }
}
