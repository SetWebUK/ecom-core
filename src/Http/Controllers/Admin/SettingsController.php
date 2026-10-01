<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Http\Requests\Admin\Content\PaymentSettingsRequest;
use Pine\Commerce\Http\Requests\Admin\Content\SettingsRequest;
use Pine\Commerce\Models\ShippingMethod;
use Pine\Commerce\Models\User;
use Pine\Commerce\Services\Admin\StoreSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Store settings. The overview links to every group; the general/checkout/emails/seo groups are rendered from
 * Pine\Commerce\Services\Admin\StoreSettings, payments has its own screen (administrators only, secrets encrypted).
 */
class SettingsController extends Controller
{
    public function index(Request $request): View
    {
        $groups = collect(StoreSettings::groups())->reject(fn ($g) => ($g['admin'] ?? false) && ! $request->user()->isAdmin());

        return view('commerce::admin.settings.index', [
            'groups' => $groups,
            'summary' => [
                'payments' => $this->paymentSummary(),
                'shipping' => ShippingMethod::query()->where('is_active', true)->count().' active delivery options',
                'staff' => User::query()->whereIn('role', ['admin', 'manager'])->where('is_active', true)->count().' people can sign in',
            ],
        ]);
    }

    public function edit(Request $request, string $group): View
    {
        static::authorizeGroup($request, $group);

        return view('commerce::admin.settings.group', [
            'group' => $group,
            'config' => StoreSettings::groups()[$group],
            'sections' => StoreSettings::sections($group),
        ]);
    }

    public function update(SettingsRequest $request, string $group): RedirectResponse
    {
        static::authorizeGroup($request, $group);
        $changed = StoreSettings::save($group, $request->validated());

        return redirect()->route('admin.settings.edit', $group)->with('success', $changed
            ? 'Settings saved. The website uses them straight away.'
            : 'Nothing changed.');
    }

    /** 404 unless $group is a generic-form group (core or Commerce::settings()); 403 for an administrators-only group. */
    public static function authorizeGroup(Request $request, string $group): void
    {
        abort_unless(in_array($group, StoreSettings::formGroups(), true), 404);
        abort_if((StoreSettings::groups()[$group]['admin'] ?? false) && ! $request->user()?->isAdmin(), 403);
    }

    public function payments(): View
    {
        $methods = PaymentSettingsRequest::methods();

        return view('commerce::admin.settings.payments', [
            'methods' => $methods,
            'values' => PaymentSettingsRequest::currentValues(),
            // webhook address for every gateway that declares one (adminSettings()['webhook'])
            'webhooks' => collect($methods)->filter(fn ($m) => ! empty($m['webhook']))->map(fn ($m, $code) => route('webhooks.payment', $code))->all(),
            'status' => PaymentSettingsRequest::status(),
        ]);
    }

    public function updatePayments(PaymentSettingsRequest $request): RedirectResponse
    {
        $changed = $request->save();

        $warnings = collect(PaymentSettingsRequest::status())->filter(fn ($s) => $s['enabled'] && ! $s['configured'])->map(fn ($s) => $s['label']);
        if ($warnings->isNotEmpty()) {
            return redirect()->route('admin.payments.edit')->with('warning', 'Saved, but '.$warnings->implode(' and ').' won’t show at checkout until all of its keys are filled in.');
        }

        return redirect()->route('admin.payments.edit')->with('success', $changed ? 'Payment settings saved.' : 'Nothing changed.');
    }

    protected function paymentSummary(): string
    {
        $on = collect(PaymentSettingsRequest::status())->filter(fn ($s) => $s['enabled'] && $s['configured'])->pluck('label');

        return $on->isEmpty() ? 'No payment methods switched on' : $on->implode(', ').' switched on';
    }
}
