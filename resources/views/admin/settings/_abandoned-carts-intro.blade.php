{{-- Settings › Abandoned carts: how reminders work + totals so far (AbandonedCartRecovery::stats()). --}}
@php
    use Pine\Commerce\Services\Recovery\AbandonedCartRecovery;
    $stats = AbandonedCartRecovery::stats(now()->subDays(30));
    $cron = \Pine\Commerce\Scheduling\Scheduler::cronRunning() || config('commerce.scheduler.web_fallback', false);
@endphp
<p class="text-sm text-muted">
    When a shopper leaves products in their basket after typing their email address at checkout (or while signed in), these emails remind them,
    with a secure “Return to my basket” link that restores the basket and opens the checkout. Reminders stop as soon as they order, empty the basket or unsubscribe.
    No tracking pixels are used – only clicks on the basket link are counted.
</p>
@if (! $cron)
    <x-admin.callout type="warning" class="mt-3" title="Reminders need scheduled tasks">Cron is not running on this server, so no reminders will be sent until it is added – see <a href="{{ route('admin.settings.edit', 'automation') }}">Settings › Scheduled tasks</a>.</x-admin.callout>
@endif
<div class="grid-3 mt-4">
    <x-admin.stat label="Reminders sent (30 days)" icon="envelope" :value="number_format($stats['emails'])" />
    <x-admin.stat label="Baskets recovered" icon="shopping-cart" :value="number_format($stats['recovered'])" :hint="$stats['clicks'].' link '.\Illuminate\Support\Str::plural('click', $stats['clicks'])" />
    <x-admin.stat label="Recovered revenue" icon="banknotes" :value="money($stats['revenue'])" hint="Paid orders" />
</div>
