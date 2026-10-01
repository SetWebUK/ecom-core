<?php

namespace Pine\Commerce\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Collection;
use Pine\Commerce\Models\Cart;
use Pine\Commerce\Models\CartRecoveryEmail;
use Pine\Commerce\Models\Coupon;
use Pine\Commerce\Services\Checkout\CartLine;
use Pine\Commerce\Services\Recovery\AbandonedCartRecovery;

/**
 * Customer: an abandoned-cart reminder (Settings › Abandoned carts, sent by AbandonedCartRecovery). Lists the basket at
 * today's prices, the optional single-use coupon, a signed one-click "return to my basket" link (restores the basket
 * and lands on the checkout) and an unsubscribe link (also as List-Unsubscribe / one-click POST headers).
 * No tracking pixel. View: commerce::emails.abandoned-cart inside the theme's emails.layouts.base.
 */
class AbandonedCartReminder extends Mailable
{
    use Queueable;

    /** @param Collection<int, CartLine> $lines */
    public function __construct(
        public Cart $cart,
        public CartRecoveryEmail $record,
        public array $step,
        public Collection $lines,
        public ?Coupon $coupon = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->placeholders($this->step['subject']));
    }

    public function headers(): Headers
    {
        $url = AbandonedCartRecovery::unsubscribeUrl($this->record);

        return new Headers(text: ['List-Unsubscribe' => '<'.$url.'>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
    }

    public function content(): Content
    {
        $coupon = $this->coupon;

        return new Content(view: 'commerce::emails.abandoned-cart', with: [
            'heading' => $this->placeholders($this->step['subject']),
            'storeName' => (string) setting('store.name', config('app.name')),
            'intro' => $this->placeholders($this->step['intro']),
            'lines' => $this->lines,
            'subtotal' => round($this->lines->sum(fn (CartLine $line) => $line->subtotal()), 2),
            'restoreUrl' => AbandonedCartRecovery::restoreUrl($this->record),
            'unsubscribeUrl' => AbandonedCartRecovery::unsubscribeUrl($this->record),
            'coupon' => $coupon,
            'couponText' => $coupon ? ($coupon->type === 'percent'
                ? rtrim(rtrim(number_format((float) $coupon->amount, 2, '.', ''), '0'), '.').'% off'
                : money((float) $coupon->amount).' off') : null,
            'brand' => \Pine\Commerce\Theme\Storefront::color('primary_color', '#1d4ed8'),
        ]);
    }

    /** {name} = first name or "there", {store} = store name. */
    protected function placeholders(string $text): string
    {
        $name = trim((string) ($this->cart->user?->first_name ?: ''));

        return strtr($text, ['{name}' => $name !== '' ? $name : 'there', '{store}' => (string) setting('store.name', config('app.name'))]);
    }
}
