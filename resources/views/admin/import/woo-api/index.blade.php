{{-- Admin › Import › WooCommerce API (administrators, feature "woo_api_import"): Pine\Commerce\Http\Controllers\Admin\WooApiImportController@index --}}
@extends('commerce::admin.layouts.app')

@section('title', 'Import from WooCommerce')

@php
    $statusColor = ['pending' => 'info', 'running' => 'attention', 'cancelling' => 'attention', 'completed' => 'success', 'failed' => 'danger',
        'cancelled' => 'gray', 'interrupted' => 'warning'];
    $isStore = old('mode', $connection['store'] ? 'store' : 'rest') === 'store';
    $saved = fn (string $field) => ! empty($connection[$field]);
    $sinceDefault = $lastCompleted?->started_at?->format('Y-m-d\TH:i');
@endphp

@section('content')
    <x-admin.page-header title="Import from WooCommerce" subtitle="Pull products, categories, customers, orders and more from a WooCommerce shop through its REST API. Nothing on the shop is changed – a read-only API key is enough." />

    <div class="stack" x-data="{ mode: @js($isStore ? 'store' : 'rest'), storeOnly: @js($storeChoices) }">
        @if ($open)
            <x-admin.callout type="warning" icon="arrow-path" :title="'Import #'.$open->id.' is '.$open->status">
                <a href="{{ route('admin.import.woo.show', $open) }}">Follow its progress</a>. One import runs at a time.
            </x-admin.callout>
        @endif

        <x-admin.card title="1. Connect to the shop" id="connection">
            <form method="POST" action="{{ route('admin.import.woo.connection') }}" class="stack-fields" autocomplete="off">
                @csrf
                <x-admin.radio-cards name="mode" label="Read the shop through" :value="$isStore ? 'store' : 'rest'" x-model="mode" :options="[
                    'rest' => ['label' => 'REST API with a key', 'help' => 'Everything: catalogue, customers, orders, coupons, reviews, shipping and tax.', 'icon' => 'key'],
                    'store' => ['label' => 'Public catalogue only', 'help' => 'No key: the public Store API – published products, categories, attributes, pages, posts, images.', 'icon' => 'globe-alt'],
                ]" />
                <x-admin.input name="url" label="Shop address" :value="$connection['url']" placeholder="https://shop.example.com" required
                               help="The WordPress site's address (as in Settings › General › Site Address)." />
                <div x-show="mode === 'rest'" class="stack-fields">
                    <div class="form-grid">
                        <x-admin.input name="key" label="Consumer key" type="password" :placeholder="$saved('key') ? 'Saved – leave empty to keep it' : 'ck_…'"
                                       help="WooCommerce › Settings › Advanced › REST API › Add key (permission: Read)." />
                        <x-admin.input name="secret" label="Consumer secret" type="password" :placeholder="$saved('secret') ? 'Saved – leave empty to keep it' : 'cs_…'"
                                       help="Shown once by WooCommerce. Stored encrypted; never shown here again." />
                    </div>
                    @if ($saved('key') || $saved('secret'))
                        <x-admin.checkbox name="secret_clear" label="Forget the saved key and secret" :unchecked="null"
                                          x-on:change="document.querySelector('[name=key_clear]').checked = $event.target.checked" />
                        <input type="checkbox" name="key_clear" value="1" hidden>
                    @endif
                    <div class="form-grid">
                        <x-admin.select name="auth" label="Authentication" :value="$connection['auth']" :options="[
                            'auto' => 'Automatic (HTTPS: Basic auth, HTTP: OAuth 1.0a)',
                            'basic' => 'HTTP Basic auth (HTTPS only)',
                            'query' => 'Keys in the query string (hosts that strip the Authorization header)',
                            'oauth' => 'OAuth 1.0a signatures',
                        ]" />
                        <div class="field"><x-admin.toggle name="verify_tls" label="Verify the TLS certificate" :checked="$connection['verify_tls']"
                                       help="Switch off only for a staging shop with a self-signed certificate." /></div>
                    </div>
                </div>
                <details @if ($connection['wp_user']) open @endif>
                    <summary class="link text-sm">Private pages and draft posts (optional WordPress application password)</summary>
                    <div class="form-grid mt-2">
                        <x-admin.input name="wp_user" label="WordPress user name" :value="$connection['wp_user']" optional />
                        <x-admin.input name="wp_password" label="Application password" type="password" optional
                                       :placeholder="$saved('wp_password') ? 'Saved – leave empty to keep it' : 'xxxx xxxx xxxx xxxx xxxx xxxx'"
                                       help="Users › Profile › Application Passwords on the shop. HTTPS only." />
                    </div>
                    @if ($saved('wp_password'))
                        <x-admin.checkbox name="wp_password_clear" label="Forget the saved application password" :unchecked="null" />
                    @endif
                </details>
                <div class="form-actions">
                    <span class="text-sm text-muted">Secrets are encrypted with this site's key and never displayed again.</span>
                    <span class="flex-1"></span>
                    <x-admin.button type="submit" name="action" value="save">Save</x-admin.button>
                    <x-admin.button type="submit" name="action" value="test" variant="primary" icon="signal">Save &amp; test connection</x-admin.button>
                </div>
            </form>
        </x-admin.card>

        @if ($probe)
            <x-admin.card :title="'Connection test'.($probe['store']['name'] ?? '' ? ' – '.$probe['store']['name'] : '')" id="test"
                          :subtitle="'Tested '.\Illuminate\Support\Carbon::parse($probe['tested_at'])->diffForHumans().'.'">
                @if ($probe['error'])
                    <x-admin.callout type="danger" title="Not connected">{{ $probe['error'] }}</x-admin.callout>
                @endif
                @if ($probe['store'])
                    <dl class="kv">
                        <dt>Shop</dt><dd><strong>{{ $probe['store']['name'] }}</strong> <span class="text-muted">{{ $probe['store']['home'] }}</span></dd>
                        @if ($probe['versions'])
                            <dt>WooCommerce</dt><dd>{{ $probe['versions']['woocommerce'] ?: '?' }} <span class="text-muted">(WordPress {{ $probe['versions']['wordpress'] ?: '?' }}, currency {{ $probe['versions']['currency'] ?: '?' }})</span></dd>
                        @endif
                        <dt>Reading via</dt><dd>{{ $probe['auth'] === 'none' ? 'public Store API (no key)' : 'REST API, '.$probe['auth'].' authentication' }}{{ $probe['route_style'] === 'rest_route' ? ', ?rest_route= URLs (no pretty permalinks)' : '' }}</dd>
                    </dl>
                @endif
                @if ($probe['counts'])
                    <x-admin.table compact class="mt-4">
                        <x-slot:head><th scope="col">What</th><th scope="col" class="num">Items on the shop</th><th scope="col">Access</th></x-slot:head>
                        @foreach ($probe['counts'] as $entity => $count)
                            <tr>
                                <td>{{ \Illuminate\Support\Str::headline($entity) }}</td>
                                <td class="num">{{ $count === null ? '—' : number_format($count) }}</td>
                                <td>
                                    @if (isset($probe['problems'][$entity]))
                                        <x-admin.badge color="danger" size="sm">No access</x-admin.badge> <span class="text-xs">{{ $probe['problems'][$entity] }}</span>
                                    @else
                                        <x-admin.badge color="success" size="sm">OK</x-admin.badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-admin.table>
                @endif
                @foreach (array_diff_key($probe['problems'], $probe['counts']) as $what => $problem)
                    <p class="text-sm mt-2"><x-admin.badge color="warning" size="sm">{{ $what }}</x-admin.badge> {{ $problem }}</p>
                @endforeach
            </x-admin.card>
        @endif

        <x-admin.card title="2. Choose what to import" id="start">
            <form method="POST" action="{{ route('admin.import.woo.start') }}" class="stack-fields">
                @csrf
                <fieldset class="field">
                    <legend class="field__label">Import</legend>
                    <div class="form-grid">
                        @foreach ($choices as $key => $label)
                            <div x-bind:class="{ 'is-disabled': mode === 'store' && ! storeOnly.includes('{{ $key }}') }">
                                <x-admin.checkbox name="entities[]" :value="$key" :label="$label" :checked="in_array($key, old('entities', array_keys($choices)), true)" :unchecked="null"
                                                  x-bind:disabled="mode === 'store' && ! storeOnly.includes('{{ $key }}')" />
                            </div>
                        @endforeach
                    </div>
                    <p class="field__help" x-show="mode === 'store'">The public Store API has the catalogue, pages, posts and images only.</p>
                    @error('entities')<p class="field__error">{{ $message }}</p>@enderror
                </fieldset>

                <div class="form-grid">
                    <div class="field"><x-admin.toggle name="images" label="Download images" :checked="true" help="Product, category, variation and post images (and the media library) into this shop, with the image sizes." /></div>
                    <div class="field"><x-admin.toggle name="notes" label="Order notes" :checked="true" help="One extra request per order." /></div>
                </div>
                <x-admin.radio-cards name="existing" label="Items imported before" value="update" :options="[
                    'update' => ['label' => 'Update them', 'help' => 'Re-import overwrites them with the shop’s current data.'],
                    'skip' => ['label' => 'Leave them alone', 'help' => 'Only new items are added.'],
                ]" />
                <div class="form-grid">
                    <x-admin.input name="orders_after" label="Only orders placed from" type="date" optional help="Leave empty for every order." />
                    <x-admin.input name="since" label="Only items changed since" type="datetime-local" optional :value="null"
                                   :help="$sinceDefault ? 'Incremental re-sync. Your last full import started '.$lastCompleted->started_at->format('j M Y H:i').' UTC.' : 'Incremental re-sync of products, orders, coupons, pages and posts (UTC).'" />
                </div>
                <x-admin.checkbox name="same_site" :checked="$sameSite" :unchecked="null"
                                  label="This is the shop this site was imported from with the database importer"
                                  :help="$importedSite ? 'The database import came from '.$importedSite.'. Ticked: those products, orders … are updated instead of added a second time.' : 'Tick only when the same shop was imported before with commerce:import-wordpress; its records are then updated instead of duplicated.'" />
                <x-admin.checkbox name="dry_run" :unchecked="null" label="Dry run" help="Read and check everything and show what would be created or updated – nothing is written, no images are downloaded." />
                <div class="form-actions">
                    <span class="text-sm text-muted">Runs in the background – you can leave the page. Interrupted imports can be resumed.</span>
                    <span class="flex-1"></span>
                    <x-admin.button type="submit" variant="primary" icon="play" :disabled="(bool) $open">Start import</x-admin.button>
                </div>
            </form>
        </x-admin.card>

        <x-admin.card flush title="History" subtitle="Every import run, who started it and what it did.">
            @if ($history->isEmpty())
                <div class="card__body"><x-admin.empty icon="clock" title="No imports yet" size="sm" /></div>
            @else
                <x-admin.table compact stack>
                    <x-slot:head>
                        <th scope="col">Import</th><th scope="col">Shop</th><th scope="col">Who</th><th scope="col">Result</th><th scope="col" class="num">Warnings / errors</th><th scope="col"><span class="sr-only">Log</span></th>
                    </x-slot:head>
                    @foreach ($history as $row)
                        @php
                            $done = collect((array) ($row->progress['entities'] ?? []))->map(fn ($p) => ($p['created'] ?? 0) + ($p['updated'] ?? 0))->sum();
                        @endphp
                        <tr>
                            <td class="stack-title">
                                <a class="row-link" href="{{ route('admin.import.woo.show', $row) }}">#{{ $row->id }}{{ $row->dry_run ? ' (dry run)' : '' }}</a>
                                <div class="text-xs text-muted"><x-admin.time :value="$row->created_at" /></div>
                            </td>
                            <td data-label="Shop" class="break">{{ $row->site_url }}<div class="text-xs text-muted">{{ $row->mode === 'store' ? 'public Store API' : 'REST API' }}{{ $row->source === null ? ' · same site as the database import' : '' }}</div></td>
                            <td data-label="Who">{{ $row->actor() }}</td>
                            <td data-label="Result"><x-admin.badge :color="$statusColor[$row->status] ?? 'gray'" size="sm">{{ ucfirst($row->status) }}</x-admin.badge>
                                <span class="text-xs text-muted">{{ number_format($done) }} {{ $row->dry_run ? 'would be written' : 'written' }}</span></td>
                            <td class="num" data-label="Warnings / errors">{{ $row->warnings }} / {{ $row->errors }}</td>
                            <td><a class="link text-sm" href="{{ route('admin.import.woo.log', $row) }}">Log</a></td>
                        </tr>
                    @endforeach
                </x-admin.table>
                <x-admin.pagination :paginator="$history" />
            @endif
        </x-admin.card>
    </div>
@endsection
