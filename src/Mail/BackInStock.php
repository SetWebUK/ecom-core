<?php

namespace Pine\Commerce\Mail;

use Pine\Commerce\Models\StockNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Customer: a product they asked about ("Email me when it's back in stock") can be bought again.
 * Sent from the back office (Products › Stock alerts › Notify, or automatically when staff put the product back in
 * stock) via Pine\Commerce\Services\Admin\CatalogueTools::sendStockAlert(). Uses the shared transactional email layout.
 */
class BackInStock extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public StockNotification $notification)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Back in stock: '.($this->notification->product?->name ?? 'your product'));
    }

    public function content(): Content
    {
        $alert = $this->notification;
        $alert->loadMissing(['product.images', 'product.primaryCategory', 'product.categories', 'variation']);
        $product = $alert->product;
        $variation = $alert->variation;

        $price = $variation?->currentPrice() ?? $product?->currentPrice() ?? ($product?->price !== null ? (float) $product->price : null);
        $imagePath = $variation?->image ?: $product?->images->first()?->path;

        return new Content(
            view: 'commerce::admin.emails.back-in-stock',
            with: [
                'heading' => 'It’s back in stock',
                'storeName' => (string) setting('store.name', config('app.name')),
                'product' => $product,
                'variantLabel' => $variation ? $this->variantLabel($variation->options ?? []) : null,
                'price' => $price,
                'imageUrl' => $imagePath ? media_url($imagePath) : null,
                'productUrl' => $product?->url,
            ],
        );
    }

    /** "16GB, Silver" from {"memory": "16gb", "colour": "silver"} (value names looked up in one query). */
    protected function variantLabel(array $options): ?string
    {
        if (! $options) {
            return null;
        }
        $names = \Pine\Commerce\Models\AttributeValue::query()
            ->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')
            ->whereIn('attributes.slug', array_keys($options))
            ->whereIn('attribute_values.slug', array_values($options))
            ->get(['attributes.slug as attribute_slug', 'attribute_values.slug', 'attribute_values.value'])
            ->mapWithKeys(fn ($v) => [$v->attribute_slug.'|'.$v->slug => $v->value]);

        return collect($options)->map(fn ($value, $attribute) => $names[$attribute.'|'.$value] ?? $value)->implode(', ');
    }
}
