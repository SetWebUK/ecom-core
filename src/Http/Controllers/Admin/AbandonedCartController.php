<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Http\Controllers\Admin\Concerns\AdminIndex;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Models\Cart;
use Pine\Commerce\Models\CartItem;
use Pine\Commerce\Services\Recovery\AbandonedCartRecovery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Abandoned checkouts: baskets with items that never became an order and haven't been touched for an hour.
 * Shows who (email captured at checkout, or the signed-in customer), what, the value at today's prices and a
 * ready-to-send "Complete your order" email (mailto) – the customer's basket is still waiting for them.
 */
class AbandonedCartController extends Controller
{
    use AdminIndex;

    public const IDLE_MINUTES = 60;

    public const CONTACT = ['yes' => 'With an email address', 'no' => 'Without an email address'];

    public function index(Request $request): View
    {
        $contact = $this->filterValue($request, 'contact', self::CONTACT);
        [$sort, $direction] = $this->sorting($request, ['updated_at', 'created_at'], 'updated_at', 'desc');

        $query = Cart::query()
            ->whereNull('converted_at')
            ->where('updated_at', '<', now()->subMinutes(self::IDLE_MINUTES))
            ->whereHas('items')
            ->when($contact === 'yes', fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNotNull('email')->where('email', '!=', '')->orWhereNotNull('user_id')))
            ->when($contact === 'no', fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('email')->orWhere('email', ''))->whereNull('user_id'));

        $carts = (clone $query)
            ->with([
                'user:id,name,first_name,last_name,email,phone',
                'items' => fn ($q) => $q->orderBy('id'),
                'items.product' => fn ($q) => $q->withTrashed()->select(['id', 'name', 'slug', 'sku', 'type', 'primary_category_id', 'regular_price', 'sale_price', 'sale_starts_at', 'sale_ends_at', 'deleted_at'])
                    ->with(['images' => fn ($images) => $images->orderBy('sort_order')->limit(1), 'primaryCategory:id,path']),
                'items.variation:id,product_id,regular_price,sale_price,image,options',
                'recoveryEmails:id,cart_id,step,sent_at,clicked_at,clicks',
            ])
            ->orderBy($sort, $direction)
            ->paginate($this->perPage($request))
            ->withQueryString();

        $carts->getCollection()->each(function (Cart $cart) {
            $cart->setAttribute('value', round($cart->items->sum(fn (CartItem $item) => $this->unitPrice($item) * $item->quantity), 2));
            $cart->setAttribute('contact_email', $cart->email ?: $cart->user?->email);
        });

        $summary = DB::table('carts')
            ->whereNull('converted_at')
            ->where('updated_at', '<', now()->subMinutes(self::IDLE_MINUTES))
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('cart_items')->whereColumn('cart_items.cart_id', 'carts.id'))
            ->selectRaw("COUNT(*) as carts, SUM(CASE WHEN (email IS NOT NULL AND email != '') OR user_id IS NOT NULL THEN 1 ELSE 0 END) as reachable")
            ->first();

        return view('commerce::admin.carts.index', [
            'carts' => $carts,
            'contact' => $contact,
            'summary' => $summary,
            'chips' => array_filter(['contact' => $contact ? self::CONTACT[$contact] : null]),
            'recovery' => AbandonedCartRecovery::stats(now()->subDays(30)),
            'remindersOn' => AbandonedCartRecovery::enabled(),
        ]);
    }

    /** One basket: contents, customer and the reminder timeline (emails sent, link clicks, stop / order). */
    public function show(int $cart): View
    {
        $cart = Cart::query()->with([
            'user:id,name,first_name,last_name,email,phone,marketing_opt_in',
            'items' => fn ($q) => $q->orderBy('id'),
            'items.product' => fn ($q) => $q->withTrashed()->with(['images' => fn ($images) => $images->orderBy('sort_order')->limit(1), 'primaryCategory:id,path']),
            'items.variation',
            'recoveryEmails',
            'recoveredOrder:id,number,total,status,created_at',
        ])->findOrFail($cart);
        $cart->setAttribute('value', round($cart->items->sum(fn (CartItem $item) => $this->unitPrice($item) * $item->quantity), 2));
        $email = AbandonedCartRecovery::contactEmail($cart);

        return view('commerce::admin.carts.show', [
            'cart' => $cart,
            'email' => $email,
            'unsubscribed' => $email && \Pine\Commerce\Models\EmailUnsubscribe::has($email, \Pine\Commerce\Models\EmailUnsubscribe::ABANDONED_CART),
            'consent' => $email ? AbandonedCartRecovery::hasMarketingConsent($email, $cart->user) : false,
            'remindersOn' => AbandonedCartRecovery::enabled(),
            'timeline' => $this->timeline($cart),
        ]);
    }

    /** Stop the reminder sequence of one basket. */
    public function stop(int $cart, AbandonedCartRecovery $recovery): RedirectResponse
    {
        $cart = Cart::query()->findOrFail($cart);
        $recovery->stop($cart, 'staff');

        return redirect()->route('admin.carts.show', $cart->id)->with('success', 'No more reminders will be sent for this basket.');
    }

    /** @return list<array{time:\Illuminate\Support\Carbon, icon:string, color:?string, text:string}> newest first */
    protected function timeline(Cart $cart): array
    {
        $events = [['time' => $cart->created_at, 'icon' => 'shopping-cart', 'color' => null, 'text' => 'Basket started']];
        foreach ($cart->recoveryEmails as $email) {
            $events[] = ['time' => $email->sent_at ?? $email->created_at, 'icon' => 'envelope', 'color' => 'primary',
                'text' => 'Reminder '.$email->step.' sent to '.$email->email.($email->subject ? ': “'.$email->subject.'”' : '')
                    .($email->coupon_code ? ' (discount code '.$email->coupon_code.')' : '')];
            if ($email->clicked_at) {
                $events[] = ['time' => $email->clicked_at, 'icon' => 'cursor-arrow-rays', 'color' => 'success',
                    'text' => 'Returned to the basket from reminder '.$email->step.($email->clicks > 1 ? ' ('.$email->clicks.' clicks)' : '')];
            }
        }
        $events[] = ['time' => $cart->updated_at, 'icon' => 'clock', 'color' => null, 'text' => 'Last basket activity'];
        if ($cart->recovery_stopped_at) {
            $events[] = ['time' => $cart->recovery_stopped_at, 'icon' => 'no-symbol', 'color' => 'warning',
                'text' => 'Reminders stopped: '.(AbandonedCartRecovery::STOP_REASONS[$cart->recovery_stop_reason] ?? $cart->recovery_stop_reason)];
        }
        if ($cart->converted_at) {
            $events[] = ['time' => $cart->converted_at, 'icon' => 'check-circle', 'color' => 'success', 'text' => 'Checked out'];
        }
        if ($cart->recovered_at && $cart->recoveredOrder) {
            $events[] = ['time' => $cart->recovered_at, 'icon' => 'banknotes', 'color' => 'success',
                'text' => 'Recovered: order #'.$cart->recoveredOrder->number.' ('.money((float) $cart->recoveredOrder->total).')'];
        }
        usort($events, fn ($a, $b) => $b['time'] <=> $a['time']);

        return $events;
    }

    protected function unitPrice(CartItem $item): float
    {
        $price = $item->variation ? $item->variation->currentPrice() : $item->product?->currentPrice();

        return round((float) $price, 2);
    }
}
