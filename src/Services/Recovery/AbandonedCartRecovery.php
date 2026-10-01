<?php

namespace Pine\Commerce\Services\Recovery;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Pine\Commerce\Mail\AbandonedCartReminder;
use Pine\Commerce\Models\Cart;
use Pine\Commerce\Models\CartItem;
use Pine\Commerce\Support\Sql;
use Pine\Commerce\Models\CartRecoveryEmail;
use Pine\Commerce\Models\Coupon;
use Pine\Commerce\Models\EmailUnsubscribe;
use Pine\Commerce\Models\NewsletterSubscriber;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\User;
use Pine\Commerce\Commerce;
use Pine\Commerce\Services\Admin\StoreSettings;
use Pine\Commerce\Services\Checkout\CartLine;
use Pine\Commerce\Support\Features;
use Throwable;

/**
 * Abandoned-cart recovery emails (Settings › Abandoned carts; scheduled task carts.abandoned-emails).
 *
 * Who gets them: baskets with products that never became an order, with an email address (typed at checkout – saved
 * on carts.email as soon as the field is filled – or the signed-in customer's), idle for at least the first step's
 * delay and for no longer than "abandoned_carts.max_age_days". Consent ("abandoned_carts.consent"):
 *   marketing (default) – only customers who agreed to marketing (account opt-in or an active newsletter subscription);
 *   all                 – everyone who gave an email address (a service reminder; every email has an unsubscribe link).
 *
 * The sequence: up to three steps, each with its own switch, delay after the last basket activity (hours), subject,
 * intro text and optional single-use coupon (percent or fixed, restricted to the customer's address, with an expiry).
 * One email per basket per run; later steps wait for their delay since the previous email as well.
 *
 * It stops (carts.recovery_stopped_at + reason) when the basket becomes an order (converted_at), the basket is emptied,
 * the address unsubscribes, the customer ordered in another basket, the basket is merged into another one, or staff
 * stop it. No tracking pixels: only clicks on the signed "return to my basket" link are recorded.
 *
 * Recovered revenue: an order placed from a basket that was sent a reminder (or within 7 days of clicking one) marks
 * that basket recovered_order_id / recovered_at (OrderPlaced → markRecovered()).
 */
class AbandonedCartRecovery
{
    public const STEPS = [1, 2, 3];

    /** Signed links in the emails stay valid this long. */
    public const LINK_DAYS = 30;

    /** A click this recent still counts an order in another basket as recovered. */
    public const CLICK_ATTRIBUTION_DAYS = 7;

    public const CONSENT = [
        'marketing' => 'Only customers who agreed to marketing emails (recommended)',
        'all' => 'Everyone who entered their email address – a service reminder with an unsubscribe link',
    ];

    public const COUPON_TYPES = ['none' => 'No discount', 'percent' => 'Percentage off', 'fixed_cart' => 'Fixed amount off the basket'];

    public const STOP_REASONS = [
        'emptied' => 'Basket emptied', 'unsubscribed' => 'Unsubscribed', 'ordered' => 'Ordered in another basket',
        'merged' => 'Restored into another basket', 'staff' => 'Stopped by staff',
    ];

    /** Package defaults of each step (the settings screen shows them; config commerce.settings.defaults may replace them). */
    public const STEP_DEFAULTS = [
        1 => ['enabled' => true, 'delay_hours' => 1, 'subject' => 'You left something in your basket',
            'intro' => 'Hi {name}, you left some items in your basket at {store}. They’re saved and ready when you are.',
            'coupon' => 'none', 'coupon_amount' => 10, 'coupon_days' => 7],
        2 => ['enabled' => true, 'delay_hours' => 24, 'subject' => 'Your basket is still waiting',
            'intro' => 'Hi {name}, just a reminder that your basket at {store} is still saved. If you had a question or a problem at checkout, reply to this email and we’ll help.',
            'coupon' => 'none', 'coupon_amount' => 10, 'coupon_days' => 7],
        3 => ['enabled' => false, 'delay_hours' => 72, 'subject' => 'A little something to help you decide',
            'intro' => 'Hi {name}, your basket at {store} is still saved – and here’s a discount to help you decide.',
            'coupon' => 'percent', 'coupon_amount' => 10, 'coupon_days' => 3],
    ];

    // ------------------------------------------------------------------ settings

    /** Feature abandoned_carts on AND Settings › Abandoned carts › "Send reminder emails" (off by default). */
    public static function enabled(): bool
    {
        return (new static)->disabledReason() === null;
    }

    public function disabledReason(): ?string
    {
        if (! Features::enabled('abandoned_carts', false)) {
            return 'Feature abandoned_carts is off.';
        }
        if (! filter_var(static::setting('abandoned_carts.emails_enabled', false), FILTER_VALIDATE_BOOL)) {
            return 'Reminder emails are switched off (Settings › Abandoned carts).';
        }

        return static::steps() ? null : 'Every reminder step is switched off (Settings › Abandoned carts).';
    }

    /** One step's settings. @return array{step:int, enabled:bool, delay_hours:int, subject:string, intro:string, coupon:string, coupon_amount:float, coupon_days:int} */
    public static function step(int $step): array
    {
        $defaults = self::STEP_DEFAULTS[$step] ?? self::STEP_DEFAULTS[1];
        $value = function (string $key) use ($step, $defaults) {
            $full = "abandoned_carts.step{$step}.{$key}";

            return static::setting($full, $defaults[$key]);
        };
        $coupon = (string) $value('coupon');

        return [
            'step' => $step,
            'enabled' => filter_var($value('enabled'), FILTER_VALIDATE_BOOL),
            'delay_hours' => max(1, (int) $value('delay_hours')),
            'subject' => trim((string) $value('subject')) ?: $defaults['subject'],
            'intro' => trim((string) $value('intro')),
            'coupon' => isset(self::COUPON_TYPES[$coupon]) ? $coupon : 'none',
            'coupon_amount' => max(0, (float) $value('coupon_amount')),
            'coupon_days' => max(1, (int) $value('coupon_days')),
        ];
    }

    /** Switched-on steps, shortest delay first. @return list<array> */
    public static function steps(): array
    {
        $steps = array_values(array_filter(array_map(fn ($n) => static::step($n), self::STEPS), fn ($s) => $s['enabled']));
        usort($steps, fn ($a, $b) => [$a['delay_hours'], $a['step']] <=> [$b['delay_hours'], $b['step']]);

        return $steps;
    }

    /** A setting, else the client default (config commerce.settings.defaults), else $fallback. */
    protected static function setting(string $key, mixed $fallback): mixed
    {
        return setting($key, StoreSettings::defaultFor($key, $fallback));
    }

    public static function consent(): string
    {
        $value = (string) static::setting('abandoned_carts.consent', 'marketing');

        return isset(self::CONSENT[$value]) ? $value : 'marketing';
    }

    public static function maxAgeDays(): int
    {
        return max(1, (int) static::setting('abandoned_carts.max_age_days', 7));
    }

    public static function minValue(): float
    {
        return max(0, (float) static::setting('abandoned_carts.min_value', 0));
    }

    // ------------------------------------------------------------------ sending

    /**
     * Send every reminder that is due now.
     *
     * @return array{sent:int, failed:int, stopped:int}
     */
    public function sendDue(?Carbon $now = null): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'stopped' => 0];
        if ($this->disabledReason() !== null) {
            return $result;
        }
        $now ??= now();
        $steps = static::steps();

        Cart::query()
            ->whereNull('converted_at')
            ->whereNull('recovery_stopped_at')
            ->where('updated_at', '<=', $now->copy()->subHours($steps[0]['delay_hours']))
            ->where('updated_at', '>=', $now->copy()->subDays(static::maxAgeDays()))
            ->where(fn ($q) => $q->where(fn ($e) => $e->whereNotNull('email')->where('email', '!=', ''))->orWhereNotNull('user_id'))
            ->with(['user', 'recoveryEmails', 'items' => fn ($q) => $q->orderBy('id'),
                'items.product' => fn ($q) => $q->with(['images', 'primaryCategory']), 'items.variation'])
            ->orderBy('id')
            ->chunkById(100, function (Collection $carts) use (&$result, $now, $steps) {
                foreach ($carts as $cart) {
                    $this->processCart($cart, $now, $steps, $result);
                }
            });

        return $result;
    }

    protected function processCart(Cart $cart, Carbon $now, array $steps, array &$result): void
    {
        $email = static::contactEmail($cart);
        if (! $email) {
            return;
        }
        if ($cart->items->isEmpty()) {
            $this->stop($cart, 'emptied');
            $result['stopped']++;

            return;
        }
        if (EmailUnsubscribe::has($email, EmailUnsubscribe::ABANDONED_CART)) {
            $this->stop($cart, 'unsubscribed');
            $result['stopped']++;

            return;
        }
        if (Order::query()->where('email', $email)->whereIn('status', Order::PAID_STATUSES)->where('created_at', '>=', $cart->created_at)->exists()) {
            $this->stop($cart, 'ordered');
            $result['stopped']++;

            return;
        }
        if (static::consent() === 'marketing' && ! static::hasMarketingConsent($email, $cart->user)) {
            return;
        }
        $step = $this->nextStep($cart, $steps, $now);
        if ($step === null) {
            return;
        }
        $lines = static::lines($cart);
        if ($lines->isEmpty() || $lines->sum(fn (CartLine $l) => $l->subtotal()) < static::minValue()) {
            return;
        }
        $this->send($cart, $step, $email, $lines) ? $result['sent']++ : $result['failed']++;
    }

    /**
     * The next switched-on step that is due for this basket, or null. A step is due once the basket has been idle for
     * its delay and – after an earlier reminder – once the gap between the two steps' delays has passed since that one.
     */
    public function nextStep(Cart $cart, array $steps, Carbon $now): ?array
    {
        $sent = $cart->relationLoaded('recoveryEmails') ? $cart->recoveryEmails : $cart->recoveryEmails()->get();
        $sentSteps = $sent->pluck('step')->map(fn ($s) => (int) $s)->all();
        $last = $sent->sortBy('sent_at')->last();
        $lastDelay = $last ? static::step((int) $last->step)['delay_hours'] : null;

        foreach ($steps as $step) {
            if (in_array($step['step'], $sentSteps, true) || ($lastDelay !== null && $step['delay_hours'] <= $lastDelay)) {
                continue;
            }
            if ($cart->updated_at->copy()->addHours($step['delay_hours'])->gt($now)) {
                return null;
            }
            if ($last && $last->sent_at && $last->sent_at->copy()->addHours($step['delay_hours'] - $lastDelay)->gt($now)) {
                return null;
            }

            return $step;
        }

        return null;
    }

    /** Send one step now (coupon created first). Returns false (nothing recorded) when the email could not be sent. */
    public function send(Cart $cart, array $step, ?string $email = null, ?Collection $lines = null): bool
    {
        $email ??= static::contactEmail($cart);
        if (! $email) {
            return false;
        }
        $lines ??= static::lines($cart);
        $coupon = null;
        $record = null;
        try {
            $coupon = $this->createCoupon($cart, $email, $step);
            $record = CartRecoveryEmail::create([
                'cart_id' => $cart->id, 'step' => $step['step'], 'email' => $email, 'subject' => $step['subject'],
                'coupon_code' => $coupon?->code, 'sent_at' => now(),
            ]);
            Mail::to($email)->send(new AbandonedCartReminder($cart, $record, $step, $lines, $coupon));
            // not $cart->save(): updated_at is the customer's last activity and must not move
            DB::table('carts')->where('id', $cart->id)->update(['abandoned_email_sent_at' => now()]);
            $cart->setRelation('recoveryEmails', $cart->recoveryEmails()->get());

            return true;
        } catch (Throwable $e) {
            Log::warning('Abandoned-cart reminder failed for basket '.$cart->id.': '.$e->getMessage());
            $record?->delete();
            $coupon?->delete();

            return false;
        }
    }

    /** Single-use coupon for this reminder (null when the step has none or coupons are switched off). */
    public function createCoupon(Cart $cart, string $email, array $step): ?Coupon
    {
        if ($step['coupon'] === 'none' || $step['coupon_amount'] <= 0 || ! Features::enabled('coupons', false)) {
            return null;
        }
        do {
            $code = 'BASKET-'.Str::upper(Str::random(8));
        } while (Coupon::query()->where('code', $code)->exists());

        return Coupon::create([
            'code' => $code,
            'description' => 'Abandoned basket reminder '.$step['step'].' – basket #'.$cart->id,
            'type' => $step['coupon'],
            'amount' => $step['coupon'] === 'percent' ? min(100, $step['coupon_amount']) : $step['coupon_amount'],
            'usage_limit' => 1,
            'usage_limit_per_user' => 1,
            'allowed_emails' => [$email],
            'expires_at' => now()->addDays($step['coupon_days'])->endOfDay(),
            'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------------ customers

    /** The basket's address: typed at checkout, else the signed-in customer's. */
    public static function contactEmail(Cart $cart): ?string
    {
        $email = mb_strtolower(trim((string) ($cart->email ?: $cart->user?->email)));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /** Agreed to marketing: account opt-in (users.marketing_opt_in) or an active newsletter subscription. */
    public static function hasMarketingConsent(string $email, ?User $user = null): bool
    {
        $email = mb_strtolower(trim($email));
        $user ??= Commerce::userModel()::query()->where('email', $email)->first();
        if ($user && $user->marketing_opt_in) {
            return true;
        }

        return NewsletterSubscriber::query()->where('email', $email)->whereNull('unsubscribed_at')->exists();
    }

    /** Priced basket lines (products that can still be bought). @return Collection<int, CartLine> */
    public static function lines(Cart $cart): Collection
    {
        return $cart->items
            ->filter(fn (CartItem $item) => $item->product && $item->product->status === 'published' && ! $item->product->trashed())
            ->filter(fn (CartItem $item) => ($item->variation ? $item->variation->currentPrice() : $item->product->currentPrice()) !== null)
            ->map(fn (CartItem $item) => CartLine::fromItem($item))
            ->values();
    }

    public function stop(Cart $cart, string $reason): void
    {
        DB::table('carts')->where('id', $cart->id)->whereNull('recovery_stopped_at')
            ->update(['recovery_stopped_at' => now(), 'recovery_stop_reason' => $reason]);
        $cart->recovery_stopped_at = now();
        $cart->recovery_stop_reason = $reason;
        $cart->syncOriginalAttributes(['recovery_stopped_at', 'recovery_stop_reason']);
    }

    /** Unsubscribe the basket's address from every future reminder (any basket) and stop this sequence. */
    public function unsubscribe(Cart $cart, ?string $email = null, string $source = 'link'): void
    {
        $email ??= static::contactEmail($cart);
        if ($email) {
            EmailUnsubscribe::add($email, EmailUnsubscribe::ABANDONED_CART, $source);
        }
        $this->stop($cart, 'unsubscribed');
    }

    // ------------------------------------------------------------------ links

    public static function restoreUrl(CartRecoveryEmail $email): string
    {
        return URL::temporarySignedRoute('cart.recover', now()->addDays(self::LINK_DAYS), ['cart' => $email->cart_id, 'e' => $email->id]);
    }

    public static function unsubscribeUrl(CartRecoveryEmail $email): string
    {
        return URL::temporarySignedRoute('cart.recover.unsubscribe', now()->addDays(self::LINK_DAYS * 3), ['cart' => $email->cart_id, 'e' => $email->id]);
    }

    public function recordClick(CartRecoveryEmail $email): void
    {
        DB::table('cart_recovery_emails')->where('id', $email->id)->update([
            'clicks' => DB::raw('clicks + 1'),
            'clicked_at' => $email->clicked_at ?? now(),
            'updated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------ recovered orders

    /** OrderPlaced: the basket (or one whose reminder was clicked recently) was won back by this order. */
    public function markRecovered(Order $order): void
    {
        try {
            $cartId = (int) ($order->meta['cart_id'] ?? 0);
            $cart = $cartId ? Cart::query()->find($cartId) : null;
            if (! $cart || ! CartRecoveryEmail::query()->where('cart_id', $cart->id)->exists()) {
                $email = mb_strtolower(trim((string) $order->email));
                $clicked = $email ? CartRecoveryEmail::query()->where('email', $email)->whereNotNull('clicked_at')
                    ->where('clicked_at', '>=', now()->subDays(self::CLICK_ATTRIBUTION_DAYS))
                    ->latest('clicked_at')->first() : null;
                $cart = $clicked ? Cart::query()->find($clicked->cart_id) : null;
            }
            if (! $cart || ($cart->recovered_order_id && (int) $cart->recovered_order_id !== (int) $order->id)) {
                return;
            }
            DB::table('carts')->where('id', $cart->id)->update(['recovered_order_id' => $order->id, 'recovered_at' => now()]);
        } catch (Throwable $e) {
            Log::warning('Could not mark recovered basket for order '.$order->id.': '.$e->getMessage());
        }
    }

    /**
     * Totals for the admin (abandoned carts page, dashboard): reminders sent, baskets emailed, clicks, recovered
     * baskets and their paid order value, since $since (null = all time).
     *
     * @return array{emails:int, carts:int, clicks:int, recovered:int, revenue:float}
     */
    public static function stats(?Carbon $since = null): array
    {
        $emails = DB::table('cart_recovery_emails')->when($since, fn ($q) => $q->where('sent_at', '>=', $since));
        $recovered = DB::table('carts')
            ->join('orders', 'orders.id', '=', 'carts.recovered_order_id')
            ->whereNotNull('carts.recovered_at')
            ->whereIn('orders.status', Order::PAID_STATUSES)
            ->when($since, fn ($q) => $q->where('carts.recovered_at', '>=', $since))
            ->selectRaw(Sql::qualify('COUNT(*) as n, COALESCE(SUM(orders.total), 0) as revenue', ['orders'])) // prefix-safe
            ->first();

        return [
            'emails' => (int) (clone $emails)->count(),
            'carts' => (int) (clone $emails)->distinct()->count('cart_id'),
            'clicks' => (int) (clone $emails)->whereNotNull('clicked_at')->count(),
            'recovered' => (int) ($recovered->n ?? 0),
            'revenue' => round((float) ($recovered->revenue ?? 0), 2),
        ];
    }
}
