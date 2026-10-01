<?php

namespace Pine\Commerce\Mail;

use Pine\Commerce\Mail\Support\OrderEmail;

/** Customer: order dispatched / complete, with the tracking number when one is set (WC "Completed order"). */
class CustomerCompletedOrder extends OrderEmail
{
    public function label(): string
    {
        return 'Completed order';
    }

    public function invoiceEmailKey(): ?string
    {
        return 'customer_completed';
    }

    protected function subjectText(): string
    {
        return sprintf('Your %s order is now complete', static::storeName());
    }

    protected function headingText(): string
    {
        return 'Thanks for shopping with us';
    }

    protected function template(): string
    {
        return 'customer-completed-order';
    }

    protected function extra(): array
    {
        return ['trackingUrl' => static::trackingUrl($this->order->tracking_carrier, $this->order->tracking_number)];
    }

    /** Link to the carrier's tracking page for the common UK carriers. */
    public static function trackingUrl(?string $carrier, ?string $number): ?string
    {
        $number = trim((string) $number);
        if ($number === '') {
            return null;
        }
        $n = rawurlencode($number);
        $carrier = strtolower((string) $carrier);

        return match (true) {
            str_contains($carrier, 'dpd') => 'https://track.dpd.co.uk/parcels/'.$n,
            str_contains($carrier, 'royal') => 'https://www.royalmail.com/track-your-item#/tracking-results/'.$n,
            str_contains($carrier, 'parcelforce') => 'https://www.parcelforce.com/track-trace?trackNumber='.$n,
            str_contains($carrier, 'dhl') => 'https://www.dhl.com/gb-en/home/tracking.html?tracking-id='.$n,
            str_contains($carrier, 'ups') => 'https://www.ups.com/track?tracknum='.$n,
            str_contains($carrier, 'fedex') => 'https://www.fedex.com/fedextrack/?trknbr='.$n,
            str_contains($carrier, 'evri') || str_contains($carrier, 'hermes') => 'https://www.evri.com/track/parcel/'.$n,
            str_contains($carrier, 'yodel') => 'https://www.yodel.co.uk/tracking/'.$n,
            default => null,
        };
    }
}
