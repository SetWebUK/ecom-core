{{-- Customer page: stats, orders, favourite products; contact details, default addresses, private note, account actions. --}}
@extends('commerce::admin.layouts.app')

@php
    use Pine\Commerce\Services\Admin\Countries;
    use Pine\Commerce\View\Components\Admin\Ui;
    use Illuminate\Support\Str;

    $name = $customer->full_name ?: $customer->email;
    $addressText = function ($address): string {
        if (! $address) {
            return '';
        }
        $lines = array_filter([
            trim($address->first_name.' '.$address->last_name), $address->company, $address->address_1, $address->address_2,
            $address->city, $address->county, $address->postcode,
        ], fn ($l) => trim((string) $l) !== '');
        if ($lines) {
            $lines[] = $address->country && $address->country !== 'GB' ? Countries::name($address->country) : 'United Kingdom';
        }

        return implode("\n", $lines);
    };
    $billingText = $addressText($billing);
    $shippingText = $addressText($shipping);
    $location = $billing ? implode(', ', array_filter([$billing->city, $billing->country && $billing->country !== 'GB' ? Countries::name($billing->country) : null])) : null;
    $addressErrors = $errors->getBag('address');
    $openAddress = $addressErrors->any() ? old('address_type') : null;
    $customerErrors = $errors->getBag('customer');
    $isStaff = $customer->isStaff();
@endphp

@section('title', $name.' · Customers')

@include('commerce::admin.orders._assets')

@section('content')
    <x-admin.page-header :title="$name" :back="route('admin.customers.index')" back-label="Back to customers"
                         :subtitle="collect([$location, 'Customer since '.\Pine\Commerce\Services\Admin\LocalTime::format($customer->created_at, 'j F Y')])->filter()->implode(' · ')">
        <x-slot:badges>
            @if ($isStaff)<x-admin.badge color="dark">{{ \Pine\Commerce\Models\User::ROLES[$customer->role] ?? $customer->role }}</x-admin.badge>@endif
            @if (! $customer->is_active)<x-admin.badge color="danger">Disabled</x-admin.badge>@endif
            @if ($customer->password)
                <x-admin.badge color="info">Registered</x-admin.badge>
            @else
                <x-admin.badge color="gray">Guest</x-admin.badge>
            @endif
            @if ($customer->marketing_opt_in)<x-admin.badge color="success" icon="envelope">Subscribed</x-admin.badge>@endif
        </x-slot:badges>
        <x-slot:actions>
            @if ($canEdit)
                <x-admin.dropdown label="More actions">
                    <x-admin.dropdown-item icon="pencil-square" x-on:click="$dispatch('open-modal', 'edit-customer')">Edit contact details</x-admin.dropdown-item>
                    @if ($customer->is_active)
                        <x-admin.confirm as="menu-item" :action="route('admin.customers.password-reset', $customer)" method="POST" icon="key" :danger="false"
                                         :title="$customer->password ? 'Send a password reset link?' : 'Invite them to create a password?'"
                                         :message="'We’ll email '.$customer->email.' a link to '.($customer->password ? 'choose a new password' : 'set a password and sign in to their account').'. The link expires after 60 minutes.'"
                                         confirm-label="Send email">{{ $customer->password ? 'Send password reset' : 'Send account invite' }}</x-admin.confirm>
                    @endif
                    @unless ($isSelf)
                        <div class="dropdown__sep"></div>
                        <x-admin.confirm as="menu-item" :action="route('admin.customers.toggle-active', $customer)" method="POST" :icon="$customer->is_active ? 'no-symbol' : 'check-circle'" :danger="$customer->is_active"
                                         :title="$customer->is_active ? 'Disable this account?' : 'Enable this account?'"
                                         :message="$customer->is_active ? 'They’ll be signed out and can’t sign in until you enable the account again. Their orders are kept.' : 'They’ll be able to sign in again.'"
                                         :confirm-label="$customer->is_active ? 'Disable account' : 'Enable account'">{{ $customer->is_active ? 'Disable account' : 'Enable account' }}</x-admin.confirm>
                        @if ((int) $stats->orders === 0 && ! $isStaff)
                            <x-admin.confirm as="menu-item" :action="route('admin.customers.destroy', $customer)" icon="trash"
                                             title="Delete this customer?" message="Their account and saved addresses are removed. This can’t be undone." confirm-label="Delete customer">Delete customer</x-admin.confirm>
                        @endif
                    @endunless
                </x-admin.dropdown>
            @endif
            <x-admin.button variant="primary" icon="plus" :href="route('admin.orders.create', ['customer' => $customer->id])">Create order</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    @if ($isStaff && ! $canEdit)
        <x-admin.callout type="info" class="mb-4">This is a staff account. Only administrators can change it (Settings › Staff).</x-admin.callout>
    @endif

    <div class="stats mb-4">
        <x-admin.stat label="Total spent" icon="banknotes" :value="money($stats->spent)" hint="Paid orders, minus refunds" />
        <x-admin.stat label="Orders" icon="shopping-bag" :value="number_format((int) $stats->orders)" :hint="(int) $stats->paid_orders !== (int) $stats->orders ? number_format((int) $stats->paid_orders).' paid' : null" />
        <x-admin.stat label="Average order" icon="calculator" :value="money($aov)" />
        <x-admin.stat label="Last order" icon="calendar-days" :value="$stats->last_order ? \Pine\Commerce\View\Components\Admin\Ui::smartDate($stats->last_order) : '—'"
                      :hint="$stats->first_order && $stats->first_order !== $stats->last_order ? 'First: '.\Pine\Commerce\Services\Admin\LocalTime::format(\Illuminate\Support\Carbon::parse($stats->first_order, 'UTC'), 'j M Y') : null" />
    </div>

    <div class="layout">
        <div class="layout__main">
            <x-admin.card flush>
                <x-slot:header>
                    <div class="flex-1"><h2 class="card__title">Orders</h2></div>
                </x-slot:header>
                @if ((int) $stats->orders > 0)
                    <x-slot:actions>
                        <x-admin.button variant="plain" :href="route('admin.orders.index', ['customer' => $customer->id, 'status' => 'all'])">View in Orders</x-admin.button>
                    </x-slot:actions>
                @endif
                @if ($orders->isEmpty())
                    <x-admin.empty icon="shopping-bag" title="No orders yet" description="Orders this customer places (signed in, or as a guest with the same email) appear here." size="sm">
                        <x-admin.button icon="plus" :href="route('admin.orders.create', ['customer' => $customer->id])">Create order</x-admin.button>
                    </x-admin.empty>
                @else
                    <div class="mt-2">
                        <x-admin.table stack compact>
                            <x-slot:head>
                                <th scope="col">Order</th>
                                <th scope="col">Date</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="num">Items</th>
                                <th scope="col" class="num">Total</th>
                            </x-slot:head>
                            @foreach ($orders as $order)
                                <tr data-href="{{ route('admin.orders.show', $order) }}">
                                    <td class="stack-title"><a href="{{ route('admin.orders.show', $order) }}" class="row-link">#{{ $order->number }}</a></td>
                                    <td class="nowrap" data-label="Date"><x-admin.time :value="$order->created_at" format="date" /></td>
                                    <td data-label="Status"><x-admin.status-badge :status="$order->status" size="sm" /></td>
                                    <td class="num" data-label="Items">{{ (int) $order->item_quantity }}</td>
                                    <td class="num" data-label="Total">
                                        @if ((float) $order->refunded_total > 0)<span class="money-was">{{ money($order->total) }}</span>{{ money((float) $order->total - (float) $order->refunded_total) }}@else{{ money($order->total) }}@endif
                                    </td>
                                </tr>
                            @endforeach
                        </x-admin.table>
                    </div>
                    @if ($orders->hasPages())
                        <x-admin.pagination :paginator="$orders" :per-page="false" />
                    @endif
                @endif
            </x-admin.card>

            @if ($topProducts->isNotEmpty())
                <x-admin.card title="Most bought" subtitle="From paid orders">
                    <ul class="summary-list">
                        @foreach ($topProducts as $product)
                            <li><span class="flex-1">{{ $product->name }}</span><span class="text-muted">× {{ (int) $product->quantity }}</span></li>
                        @endforeach
                    </ul>
                </x-admin.card>
            @endif
        </div>

        <div class="layout__aside">
            <x-admin.card title="Contact">
                @if ($canEdit)
                    <x-slot:actions>
                        <x-admin.button variant="plain" x-data x-on:click="$dispatch('open-modal', 'edit-customer')">Edit</x-admin.button>
                    </x-slot:actions>
                @endif
                <div class="stack stack--xs">
                    <div class="contact-line">
                        <x-admin.icon name="envelope" />
                        <a href="mailto:{{ $customer->email }}">{{ $customer->email }}</a>
                        <button type="button" class="btn btn--ghost btn--icon btn--sm" x-data @click="Sales.copy({{ \Illuminate\Support\Js::from($customer->email) }}, 'Email address')" aria-label="Copy email address" title="Copy"><x-admin.icon name="clipboard-document" /></button>
                    </div>
                    @php $phone = $customer->phone ?: $billing?->phone; @endphp
                    @if ($phone)
                        <div class="contact-line"><x-admin.icon name="phone" /><a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}">{{ $phone }}</a></div>
                    @else
                        <div class="contact-line text-muted"><x-admin.icon name="phone" />No phone number</div>
                    @endif
                    <div class="contact-line text-muted">
                        <x-admin.icon name="megaphone" />{{ $customer->marketing_opt_in ? 'Subscribed to email marketing' : 'Not subscribed to email marketing' }}
                    </div>
                </div>
            </x-admin.card>

            @foreach (['billing' => ['Billing address', $billing, $billingText], 'shipping' => ['Shipping address', $shipping, $shippingText]] as $type => [$title, $address, $text])
                <x-admin.card :title="$title">
                    @if ($canEdit)
                        <x-slot:actions>
                            @if ($text !== '')
                                <button type="button" class="btn btn--ghost btn--icon btn--sm" x-data @click="Sales.copy({{ \Illuminate\Support\Js::from($text) }}, @js($title))" aria-label="Copy {{ Str::lower($title) }}" title="Copy"><x-admin.icon name="clipboard-document" /></button>
                            @endif
                            <x-admin.button variant="plain" x-data x-on:click="$dispatch('open-modal', 'edit-{{ $type }}')">{{ $address ? 'Edit' : 'Add' }}</x-admin.button>
                        </x-slot:actions>
                    @endif
                    @if ($text !== '')
                        <div class="address">{{ $text }}</div>
                        @if ($address?->phone && $address->phone !== $customer->phone)<div class="text-sm mt-1">Tel: {{ $address->phone }}</div>@endif
                    @else
                        <p class="text-sm text-muted">{{ $type === 'shipping' && $billingText !== '' ? 'Same as billing (none saved).' : 'No address saved.' }}</p>
                    @endif
                </x-admin.card>
            @endforeach

            <x-admin.card title="Notes" subtitle="Private – the customer never sees these.">
                <form method="POST" action="{{ route('admin.customers.note', $customer) }}" x-data="{ text: @js(old('admin_note', $customer->admin_note ?? '')), saved: @js($customer->admin_note ?? '') }">
                    @csrf
                    @method('PUT')
                    <label for="f-admin_note" class="sr-only">Note</label>
                    <textarea name="admin_note" id="f-admin_note" class="textarea" rows="4" maxlength="5000" x-model="text" placeholder="e.g. Trade customer – prefers invoices by email."></textarea>
                    @error('admin_note')<p class="field__error"><x-admin.icon name="exclamation-circle" variant="mini" /><span>{{ $message }}</span></p>@enderror
                    <div class="row row--end mt-2" x-show="text !== saved" x-cloak>
                        <button type="button" class="btn btn--sm" @click="text = saved"><span>Cancel</span></button>
                        <x-admin.button type="submit" size="sm" variant="primary">Save note</x-admin.button>
                    </div>
                </form>
            </x-admin.card>

            <x-admin.card title="Account">
                <dl class="kv">
                    <dt>Type</dt><dd>{{ $customer->password ? 'Registered – can sign in' : 'Guest – no password set' }}</dd>
                    <dt>Status</dt><dd>{{ $customer->is_active ? 'Active' : 'Disabled' }}</dd>
                    @if ($customer->last_login_at)<dt>Last sign-in</dt><dd><x-admin.time :value="$customer->last_login_at" /></dd>@endif
                    <dt>Created</dt><dd><x-admin.time :value="$customer->created_at" format="date" /></dd>
                </dl>
            </x-admin.card>
        </div>
    </div>

    @if ($canEdit)
        <x-admin.modal name="edit-customer" title="Edit contact details" :open="$customerErrors->any()">
            <form method="POST" action="{{ route('admin.customers.update', $customer) }}" id="customer-form" class="stack-fields">
                @csrf
                @method('PUT')
                <div class="form-grid">
                    <x-admin.input name="first_name" label="First name" required :value="$customer->first_name" bag="customer" autocomplete="off" />
                    <x-admin.input name="last_name" label="Last name" :value="$customer->last_name" bag="customer" autocomplete="off" />
                </div>
                <x-admin.input name="email" type="email" label="Email" required :value="$customer->email" bag="customer" autocomplete="off"
                               help="Also their sign-in email. Past orders keep the email they were placed with." />
                <x-admin.input name="phone" type="tel" label="Phone" optional :value="$customer->phone" bag="customer" autocomplete="off" />
                <x-admin.checkbox name="marketing_opt_in" label="Customer agreed to receive marketing emails" :checked="$customer->marketing_opt_in" />
            </form>
            <x-slot:footer>
                <x-admin.button x-on:click="hide()">Cancel</x-admin.button>
                <x-admin.button type="submit" form="customer-form" variant="primary">Save</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>

        @foreach (['billing' => $billing, 'shipping' => $shipping] as $type => $address)
            <x-admin.modal :name="'edit-'.$type" :title="($address ? 'Edit ' : 'Add ').$type.' address'" size="lg" :open="$openAddress === $type">
                <form method="POST" action="{{ route('admin.customers.address', $customer) }}" id="customer-address-{{ $type }}" class="stack-fields">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="address_type" value="{{ $type }}">
                    <div class="form-grid">
                        <x-admin.input :name="$type.'_first_name'" label="First name" required :value="$address?->first_name ?? $customer->first_name" bag="address" autocomplete="off" />
                        <x-admin.input :name="$type.'_last_name'" label="Last name" :value="$address?->last_name ?? $customer->last_name" bag="address" autocomplete="off" />
                    </div>
                    <x-admin.input :name="$type.'_company'" label="Company" optional :value="$address?->company" bag="address" autocomplete="off" />
                    <x-admin.input :name="$type.'_address_1'" label="Address" :value="$address?->address_1" bag="address" autocomplete="off" />
                    <x-admin.input :name="$type.'_address_2'" label="Apartment, suite, etc." optional :value="$address?->address_2" bag="address" autocomplete="off" />
                    <div class="form-grid form-grid--3">
                        <x-admin.input :name="$type.'_city'" label="Town / city" :value="$address?->city" bag="address" autocomplete="off" />
                        <x-admin.input :name="$type.'_county'" label="County" optional :value="$address?->county" bag="address" autocomplete="off" />
                        <x-admin.input :name="$type.'_postcode'" label="Postcode" :value="$address?->postcode" bag="address" autocomplete="off" />
                    </div>
                    <div class="form-grid">
                        <x-admin.field label="Country" :for="'f-'.$type.'_country'" :error="$type.'_country'" bag="address">
                            <select name="{{ $type }}_country" id="f-{{ $type }}_country" class="select">
                                @foreach (Countries::options() as $code => $countryName)
                                    <option value="{{ $code }}" @selected(old($type.'_country', $address?->country ?: 'GB') === $code)>{{ $countryName }}</option>
                                @endforeach
                            </select>
                        </x-admin.field>
                        <x-admin.input :name="$type.'_phone'" type="tel" label="Phone" optional :value="$address?->phone" bag="address" autocomplete="off" />
                    </div>
                </form>
                <x-slot:footer>
                    <x-admin.button x-on:click="hide()">Cancel</x-admin.button>
                    <x-admin.button type="submit" :form="'customer-address-'.$type" variant="primary">Save address</x-admin.button>
                </x-slot:footer>
            </x-admin.modal>
        @endforeach
    @endif
@endsection
