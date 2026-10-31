<?php

namespace Pine\Commerce\Services\Admin;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Pine\Commerce\Models\FormSubmission;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductReview;
use Pine\Commerce\Models\ShippingMethod;
use Pine\Commerce\Models\StockNotification;
use Pine\Commerce\Models\User;
use Pine\Commerce\Scheduling\Scheduler;
use Pine\Commerce\Services\Payments\PaymentManager;
use Pine\Commerce\Support\Features;
use Throwable;

/**
 * The back-office home's "To do" card, in plain words for shop staff:
 *   problems()  what stops the shop taking money, delivering or emailing (administrators only – they can fix it)
 *   tasks()     work waiting in other screens: orders on hold, reviews to approve, unread messages, restock requests
 * Each entry is ['key', 'tone' (danger|warning|info), 'icon', 'title', 'text', 'url' (null = nothing to click), 'action'].
 * Only entries with something to do are returned, so an empty list means "all caught up". Orders to send have their
 * own card on the home page and are left out here.
 */
class StoreTasks
{
    /** @return list<array{key:string, tone:string, icon:string, title:string, text:string, url:?string, action:?string}> */
    public static function forUser(?User $user): array
    {
        return array_merge($user?->isAdmin() ? static::problems() : [], static::tasks());
    }

    /** @return list<array{key:string, tone:string, icon:string, title:string, text:string, url:?string, action:?string}> */
    public static function problems(): array
    {
        $out = [];
        $payments = static::route('admin.payments.edit');

        $available = 0;
        $testing = [];
        try {
            $gateways = app(PaymentManager::class)->all();
        } catch (Throwable) {
            $gateways = [];
        }
        foreach ($gateways as $gateway) {
            if (! $gateway->isEnabled()) {
                continue;
            }
            if (! $gateway->isConfigured()) {
                $out[] = static::entry('payment-keys-'.$gateway->code(), 'danger', 'credit-card', $gateway->title().' is switched on but its keys are missing',
                    'It stays hidden at checkout until the keys are added.', $payments, 'Add the keys');

                continue;
            }
            $available++;
            if (static::isTestMode($gateway)) {
                $testing[] = $gateway->title();
            }
        }
        if ($available === 0) {
            array_unshift($out, static::entry('payments', 'danger', 'credit-card', 'Customers can’t pay yet',
                'No payment method is switched on, so nobody can complete the checkout.', $payments, 'Set up payments'));
        } elseif ($testing) {
            // one entry: PayPal, its card form and wallets share a single sandbox switch
            $out[] = static::entry('payments-test', 'warning', 'credit-card', count($testing) === $available ? 'Payments are in test mode' : 'Some payments are in test mode',
                Str::of(implode(', ', $testing))->replaceLast(', ', ' and ').' '.(count($testing) === 1 ? 'takes' : 'take').' test payments, not real money. Switch to the live keys before you open the shop.',
                $payments, 'Payment settings');
        }

        if (! ShippingMethod::query()->where('is_active', true)->exists()) {
            $out[] = static::entry('delivery', 'danger', 'truck', 'No delivery options',
                'Add at least one delivery option (it can be free) so customers can check out.', static::route('admin.shipping.index'), 'Set up delivery');
        }

        if (in_array((string) config('mail.default'), ['log', 'array'], true)) {
            $out[] = static::entry('mailer', 'warning', 'envelope', 'Emails aren’t being sent',
                'Order confirmations and password resets are only written to a log file. Ask your developer to connect an email service (MAIL_MAILER).', null, null);
        }
        $from = (string) (setting('emails.from_address') ?: config('mail.from.address'));
        if ($from === '' || preg_match('/@(example\.(com|org|net|test)|localhost)$/i', $from)) {
            $out[] = static::entry('sender', 'warning', 'envelope', 'Emails come from a placeholder address',
                ($from !== '' ? '“'.$from.'” isn’t a real address. ' : '').'Customers see the sender address on every order email.', static::route('admin.settings.edit', 'emails'), 'Email settings');
        }

        $out = array_merge($out, static::backgroundJobs());

        if (! Product::query()->published()->exists()) {
            $out[] = static::entry('products', 'info', 'tag', 'Your shop has no products yet',
                'Add a product, or import them from a spreadsheet.', static::route('admin.products.create'), 'Add a product');
        }

        return $out;
    }

    /** @return list<array{key:string, tone:string, icon:string, title:string, text:string, url:?string, action:?string}> */
    public static function tasks(): array
    {
        $out = [];

        if ($onHold = Order::query()->where('status', 'on-hold')->count()) {
            $out[] = static::entry('on-hold', 'warning', 'pause-circle', static::count($onHold, 'order').' on hold',
                'Usually waiting for a bank transfer. Check the money has arrived, then mark '.($onHold === 1 ? 'it' : 'them').' as paid.',
                static::route('admin.orders.index', ['status' => 'on-hold']), 'Review');
        }

        if (Features::enabled('reviews', false) && ($reviews = ProductReview::query()->where('is_approved', false)->count())) {
            $out[] = static::entry('reviews', 'info', 'star', static::count($reviews, 'review').' waiting for approval',
                'Approved reviews show on the product pages.', static::route('admin.reviews.index', ['status' => 'pending']), 'Review');
        }

        if (Features::enabled('contact_form', false) && ($unread = FormSubmission::query()->whereNull('read_at')->count())) {
            $out[] = static::entry('messages', 'info', 'envelope', static::count($unread, 'unread message'),
                'From the contact form on your website.', static::route('admin.form-submissions.index', ['status' => 'unread']), 'Read');
        }

        if (Features::enabled('stock_alerts', false)) {
            $waiting = StockNotification::query()->whereNull('notified_at')->selectRaw('COUNT(DISTINCT email) AS people, COUNT(DISTINCT product_id) AS products')->first();
            if ($waiting && (int) $waiting->people > 0) {
                $out[] = static::entry('stock-alerts', 'info', 'bell-alert', static::count((int) $waiting->people, 'customer').' waiting for sold-out items',
                    'They asked to be told when '.static::count((int) $waiting->products, 'product').' '.((int) $waiting->products === 1 ? 'is' : 'are').' back. They’re emailed automatically when you restock.',
                    static::route('admin.stock-alerts.index'), 'See what they want');
            }
        }

        return $out;
    }

    /**
     * Live shops only (production): cron isn't checking in, or a scheduled task failed / is overdue (Scheduler::health()).
     * A warning, not a danger: without cron the web fallback still cancels unpaid orders, but only while people visit.
     *
     * @return list<array{key:string, tone:string, icon:string, title:string, text:string, url:?string, action:?string}>
     */
    protected static function backgroundJobs(): array
    {
        if (! app()->environment('production') || ! filter_var(config('commerce.scheduler.enabled', true), FILTER_VALIDATE_BOOL)) {
            return [];
        }
        try {
            $health = Scheduler::health();
        } catch (Throwable) {
            return [];
        }
        $url = static::route('admin.settings.edit', 'automation');

        if (! $health['running']) {
            return [static::entry('scheduler', 'warning', 'clock', $health['beat'] ? 'Background jobs have stopped' : 'Background jobs aren’t set up',
                ($health['beat'] ? 'The server’s cron job last checked in '.$health['beat']->diffForHumans().'. ' : 'The server needs a cron job, and it hasn’t run yet. ')
                .'It cancels unpaid orders, starts and ends sale prices, sends basket reminders and tidies up each night. '
                .($health['beat'] ? 'Ask your developer or host to check it: the line it should run is on the next screen.' : 'Ask your developer or host to add it: the line to copy is on the next screen.'),
                $url, 'See what’s needed')];
        }

        $failed = array_column($health['failed'], 'label');
        $late = array_values(array_diff(array_column($health['overdue'], 'label'), $failed));
        if (! $failed && ! $late) {
            return [];
        }
        $names = fn (array $labels) => (string) Str::of('“'.implode('”, “', $labels).'”')->replaceLast('”, “', '” and “');
        $text = trim(($failed ? $names($failed).' didn’t finish last time. ' : '').($late ? $names($late).' '.(count($late) === 1 ? 'is' : 'are').' running late. ' : ''));

        return [static::entry('scheduler-tasks', 'warning', 'clock', static::count(count($failed) + count($late), 'background job').' need'.(count($failed) + count($late) === 1 ? 's' : '').' a look',
            $text.' The details are on the next screen.', $url, 'See the jobs')];
    }

    /** Stripe test keys or PayPal sandbox (PayPal's card form and wallets share its sandbox switch). */
    public static function isTestMode(object $gateway): bool
    {
        return (method_exists($gateway, 'isTestMode') && $gateway->isTestMode())
            || (method_exists($gateway, 'isSandbox') && $gateway->isSandbox());
    }

    protected static function entry(string $key, string $tone, string $icon, string $title, string $text, ?string $url, ?string $action): array
    {
        return compact('key', 'tone', 'icon', 'title', 'text', 'url') + ['action' => $url ? $action : null];
    }

    protected static function count(int $n, string $noun): string
    {
        return number_format($n).' '.Str::plural($noun, $n);
    }

    protected static function route(string $name, mixed $parameters = []): ?string
    {
        return Route::has($name) ? route($name, $parameters) : null;
    }
}
