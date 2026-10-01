<?php

namespace Pine\Commerce\Services\Admin;

use Pine\Commerce\Extensions\ExtensionRegistry;
use Pine\Commerce\Models\Page;
use Pine\Commerce\Support\Features;
use Pine\Commerce\Models\TaxClass;
use Pine\Commerce\Services\Tax\TaxSettings;
use Pine\Commerce\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The back office "Settings" screens: every setting key the storefront reads, grouped, with its label, input type,
 * the default the storefront falls back to, and a note on what it drives. SettingsController renders and saves these
 * (Setting::set, which clears the 'settings.all' cache). Payments, Shipping and Staff have their own screens.
 *
 * Field shape: ['key', 'label', 'type' (text|textarea|email|url|link|image|bool|int|decimal|money|select|list|emails),
 *               'default', 'help', 'drives', 'options', 'required', 'min', 'max', 'rows', 'items' (list), 'placeholder']
 * Empty text values are saved as '' – setting() then returns the storefront's own default again.
 */
class StoreSettings
{
    /** Order notification emails that Pine\Commerce\Listeners\SendOrderStatusEmails lets you switch off ("emails.{key}.enabled"). */
    public const ORDER_EMAILS = [
        'new_order' => ['New order', 'To the shop', 'When an order is paid (or placed by bank transfer).'],
        'cancelled_order' => ['Cancelled order', 'To the shop', 'When a processing or on-hold order is cancelled.'],
        'failed_order' => ['Failed order', 'To the shop', 'When a payment fails.'],
        'customer_processing' => ['Order received (processing)', 'To the customer', 'Confirmation once the order is paid.'],
        'customer_on_hold' => ['Order on hold', 'To the customer', 'Bank transfer orders – includes the bank details.'],
        'customer_completed' => ['Order completed', 'To the customer', 'When you mark the order completed – includes the tracking number.'],
        'customer_refunded' => ['Order refunded', 'To the customer', 'When an order is refunded in full.'],
    ];

    /**
     * Settings screens in the left sub-navigation. 'route' = own screen; 'admin' = administrators only.
     *
     * @return array<string, array{label:string, icon:string, description:string, route:string, admin?:bool}>
     */
    public static function groups(): array
    {
        $groups = static::coreGroups();
        // client screens (Commerce::settings()) go before System; a group tied to a switched-off feature is hidden
        $system = ['system' => $groups['system']];
        unset($groups['system']);
        foreach (app(ExtensionRegistry::class)->settingsGroups() as $key => $entry) {
            $groups[$key] ??= $entry['group'];
        }
        $groups += $system;

        return array_filter($groups, fn (array $g) => empty($g['feature']) || Features::enabled((string) $g['feature'], false));
    }

    /** @return array<string, array{label:string, icon:string, description:string, route:string, admin?:bool}> */
    protected static function coreGroups(): array
    {
        return [
            'general' => ['label' => 'Store details', 'icon' => 'building-storefront', 'route' => 'admin.settings.edit',
                'description' => 'Name, logos, contact details, social links and the header and footer text.'],
            'checkout' => ['label' => 'Checkout & orders', 'icon' => 'shopping-cart', 'route' => 'admin.settings.edit',
                'description' => 'Order numbers, terms, accounts, stock holding and basket behaviour.'],
            'tax' => ['label' => 'Tax', 'icon' => 'receipt-percent', 'route' => 'admin.settings.edit',
                'description' => 'Prices with or without VAT, tax classes, rates per country and how tax is shown.'],
            'payments' => ['label' => 'Payments', 'icon' => 'credit-card', 'route' => 'admin.payments.edit', 'admin' => true,
                'description' => 'Card payments (Stripe), PayPal and bank transfer.'],
            'shipping' => ['label' => 'Shipping', 'icon' => 'truck', 'route' => 'admin.shipping.index',
                'description' => 'Shipping zones, delivery options and prices, shipping classes and the countries you sell to.'],
            'emails' => ['label' => 'Emails', 'icon' => 'envelope', 'route' => 'admin.settings.edit',
                'description' => 'Sender details, who is told about new orders, and which emails are sent.'],
            'invoices' => ['label' => 'Invoices', 'icon' => 'document-text', 'route' => 'admin.settings.edit',
                'description' => 'Invoice numbers, PDF invoices on order emails, customer downloads and the text on invoices and packing slips.'],
            'abandoned_carts' => ['label' => 'Abandoned carts', 'icon' => 'shopping-bag', 'route' => 'admin.settings.edit', 'feature' => 'abandoned_carts',
                'description' => 'Reminder emails to shoppers who left items in their basket, with optional discount codes.'],
            'automation' => ['label' => 'Scheduled tasks', 'icon' => 'clock', 'route' => 'admin.settings.edit',
                'description' => 'Background jobs (cron), the daily low-stock email and how long old baskets are kept.'],
            'seo' => ['label' => 'SEO & tracking', 'icon' => 'magnifying-glass', 'route' => 'admin.settings.edit',
                'description' => 'Google defaults, robots.txt, Tag Manager / Analytics, cookie banner and the product feed.'],
            'theme' => ['label' => 'Theme', 'icon' => 'swatch', 'route' => 'admin.settings.theme',
                'description' => 'Storefront theme, brand colours, fonts and logo – with a private preview for staff.'],
            'staff' => ['label' => 'Staff accounts', 'icon' => 'user-group', 'route' => 'admin.staff.index', 'admin' => true,
                'description' => 'Who can sign in to this back office.'],
            'system' => ['label' => 'System', 'icon' => 'server-stack', 'route' => 'admin.settings.system', 'admin' => true,
                'description' => 'Platform version, storefront theme, feature switches and health checks.'],
        ];
    }

    /** Core groups edited with the generic settings form (SettingsController@edit). */
    public const FORM_GROUPS = ['general', 'checkout', 'tax', 'emails', 'invoices', 'seo', 'abandoned_carts', 'automation'];

    /** Every group edited with the generic settings form: the core FORM_GROUPS + client groups (Commerce::settings()). */
    public static function formGroups(): array
    {
        $client = array_keys(array_filter(static::groups(), fn (array $g) => ($g['route'] ?? '') === 'admin.settings.edit'));

        // a core group tied to a switched-off feature (abandoned_carts) is not a form group either
        $core = array_intersect(self::FORM_GROUPS, array_keys(static::groups()));

        return array_values(array_unique(array_merge($core, array_intersect($client, array_keys(app(ExtensionRegistry::class)->settingsGroups())))));
    }

    /** URL of a settings screen. */
    public static function url(string $group): string
    {
        $config = static::groups()[$group] ?? null;
        if (! $config) {
            return route('admin.settings.index');
        }

        return $config['route'] === 'admin.settings.edit' ? route('admin.settings.edit', $group) : route($config['route']);
    }

    /**
     * Cards of fields for one of FORM_GROUPS.
     *
     * @return list<array{title:string, description?:string, fields:list<array>}>
     */
    public static function sections(string $group): array
    {
        $registry = app(ExtensionRegistry::class);
        $sections = match ($group) {
            'general' => static::general(),
            'checkout' => static::checkout(),
            'tax' => static::tax(),
            'emails' => static::emails(),
            'invoices' => static::invoices(),
            'seo' => static::seo(),
            'abandoned_carts' => static::abandonedCarts(),
            'automation' => static::automation(),
            default => $registry->settingsGroups()[$group]['sections'] ?? [],
        };
        // extra cards from Commerce::settingsFields(); cards tied to a switched-off feature are left out
        $sections = array_values(array_filter(array_merge($sections, $registry->settingsSections($group)),
            fn (array $section) => empty($section['feature']) || Features::enabled((string) $section['feature'], false)));

        // client defaults (config commerce.settings.defaults) replace the neutral package defaults
        $defaults = (array) config('commerce.settings.defaults', []);
        if ($defaults) {
            foreach ($sections as &$section) {
                foreach ($section['fields'] as &$field) {
                    if (array_key_exists($field['key'], $defaults)) {
                        $field['default'] = $defaults[$field['key']];
                    }
                }
                unset($field);
            }
            unset($section);
        }

        return $sections;
    }

    /** Client default of a setting (config commerce.settings.defaults, keyed by the full setting key), else $fallback. */
    public static function defaultFor(string $key, mixed $fallback = null): mixed
    {
        $defaults = (array) config('commerce.settings.defaults', []);

        return array_key_exists($key, $defaults) ? $defaults[$key] : $fallback;
    }

    /** @return Collection<int, array> every field of a group, flattened */
    public static function fields(string $group): Collection
    {
        return collect(static::sections($group))->flatMap(fn ($section) => $section['fields'])->values();
    }

    /** Current value as the storefront sees it (stored value, or the storefront's default). */
    public static function value(array $field): mixed
    {
        // a field whose current value is computed (e.g. the next invoice number) rather than read from one setting
        if (isset($field['value']) && $field['value'] instanceof \Closure) {
            return ($field['value'])();
        }
        $value = setting($field['key'], $field['default'] ?? null);
        if (($field['type'] ?? '') === 'bool') {
            return filter_var($value, FILTER_VALIDATE_BOOL);
        }
        if (($field['type'] ?? '') === 'list') {
            if (is_string($value)) {
                $value = json_decode($value, true);
            }

            return is_array($value) ? array_values(array_map('strval', $value)) : [];
        }

        return is_array($value) ? json_encode($value) : $value;
    }

    /** Validation rules for a group's fields, keyed by input name (dots become [..] in the form). */
    public static function rules(string $group): array
    {
        $rules = [];
        foreach (static::fields($group) as $field) {
            $name = static::inputName($field['key']);
            $required = ! empty($field['required']) ? 'required' : 'nullable';
            $rules[$name] = match ($field['type']) {
                'bool' => ['nullable', 'boolean'],
                'email' => [$required, 'string', 'email:rfc', 'max:190'],
                'emails' => [$required, 'string', 'max:1000'],
                'url' => [$required, 'string', 'max:500', 'url:http,https'],
                'link' => [$required, 'string', 'max:500', 'not_regex:/^\s*(javascript|data|vbscript):/i'],
                'image' => [$required, 'string', 'max:500', 'regex:#^(uploads/|https?://|/)[^\s<>"\']*$#i'],
                'int' => [$required, 'integer', 'min:'.($field['min'] ?? 0), 'max:'.($field['max'] ?? 1000000000)],
                'decimal', 'money' => [$required, 'numeric', 'min:'.($field['min'] ?? 0), 'max:'.($field['max'] ?? 1000000)],
                'select' => [$required, 'string', Rule::in(array_map('strval', array_keys(static::options($field))))],
                'list' => ['nullable', 'array', 'max:'.($field['items'] ?? 12)],
                'textarea' => [$required, 'string', 'max:'.($field['max'] ?? 5000)],
                default => [$required, 'string', 'max:'.($field['max'] ?? 255)],
            };
            if ($field['type'] === 'list') {
                $rules[$name.'.*'] = ['nullable', 'string', 'max:120'];
            }
            if (! empty($field['pattern'])) {
                $rules[$name][] = 'regex:'.$field['pattern'];
            }
        }

        return $rules;
    }

    /** Friendly attribute names for validation messages. */
    public static function attributes(string $group): array
    {
        return static::fields($group)->mapWithKeys(fn ($f) => [static::inputName($f['key']) => mb_strtolower($f['label'])])->all();
    }

    /** Form input name for a setting key: "store.phone" -> "store__phone" (dots would be read as nesting). */
    public static function inputName(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    /** @var array<string, string>|null published pages for the page pickers (memoised per request) */
    protected static ?array $pageOptions = null;

    /** Options of a select field (static list or page picker). */
    public static function options(array $field): array
    {
        if (($field['options'] ?? null) === 'pages') {
            // one query per request, however many page pickers the group has
            $pages = static::$pageOptions ??= Page::query()->where('status', 'published')->where('path', '!=', '')
                ->orderBy('title')->get(['title', 'path'])
                ->mapWithKeys(fn (Page $p) => [$p->path => $p->title.' (/'.$p->path.'/)'])->all();

            return ($field['none'] ?? null ? ['0' => $field['none']] : []) + $pages;
        }

        return $field['options'] ?? [];
    }

    /**
     * Normalise one submitted value for storage (bool -> true/false, list -> array, trimmed strings).
     */
    public static function normalise(array $field, mixed $input): mixed
    {
        return match ($field['type']) {
            'bool' => filter_var($input, FILTER_VALIDATE_BOOL),
            'list' => array_values(array_filter(array_map(fn ($v) => trim((string) $v), (array) $input), fn ($v) => $v !== '')),
            'int' => $input === null || $input === '' ? '' : (string) (int) $input,
            'decimal', 'money' => $input === null || $input === '' ? '' : rtrim(rtrim(number_format((float) $input, 4, '.', ''), '0'), '.'),
            'emails' => implode(', ', static::emailList((string) $input)),
            'textarea' => str_replace("\r\n", "\n", trim((string) $input)),
            default => trim((string) $input),
        };
    }

    /** @return list<string> */
    public static function emailList(string $raw): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($e) => mb_strtolower(trim($e)), preg_split('/[\s,;]+/', $raw) ?: []))));
    }

    /**
     * Save a group's validated input. Only values that differ from what the storefront currently uses are written.
     *
     * @return int number of settings changed
     */
    public static function save(string $group, array $input): int
    {
        $changed = 0;
        foreach (static::fields($group) as $field) {
            $name = static::inputName($field['key']);
            if (! array_key_exists($name, $input) && $field['type'] !== 'list') {
                continue;
            }
            $new = static::normalise($field, $input[$name] ?? null);
            $current = static::value($field);
            if ($field['type'] === 'bool' ? $new === (bool) $current : ($field['type'] === 'list' ? $new === $current : (string) $new === (string) $current)) {
                // unchanged – but an empty list/text means "use the default" and must not be stored
                continue;
            }
            Setting::set($field['key'], $new === [] ? '' : $new);
            $changed++;

            // keep the keys other code reads in step
            foreach ($field['mirror'] ?? [] as $mirror) {
                Setting::set($mirror, is_string($new) && $field['type'] === 'emails' ? (static::emailList($new)[0] ?? '') : $new);
            }
        }

        return $changed;
    }

    // ------------------------------------------------------------------------------------------------ groups

    protected static function general(): array
    {
        return [
            ['title' => 'Store', 'description' => 'The shop’s name and logos.', 'fields' => [
                ['key' => 'store.name', 'label' => 'Store name', 'type' => 'text', 'default' => config('app.name'), 'required' => true,
                    'drives' => 'Browser tab titles, emails, invoices and the checkout.'],
                ['key' => 'store.company_name', 'label' => 'Company name', 'type' => 'text',
                    'drives' => 'Footer legal line and copyright.'],
                ['key' => 'store.logo', 'label' => 'Logo', 'type' => 'image',
                    'drives' => 'Site header, checkout, emails and printed invoices.', 'help' => 'Transparent PNG or WebP, about 400px wide.'],
                ['key' => 'store.footer_logo', 'label' => 'Footer logo', 'type' => 'image',
                    'drives' => 'Footer (dark background) – use a light version of the logo.'],
                ['key' => 'store.favicon', 'label' => 'Browser icon (favicon)', 'type' => 'image',
                    'drives' => 'Icon in the browser tab and phone home screens.', 'help' => 'Square PNG, at least 192 × 192 pixels.'],
            ]],
            ['title' => 'Contact details', 'description' => 'Shown in the header, footer, contact page, emails and invoices.', 'fields' => [
                ['key' => 'store.phone', 'label' => 'Phone number', 'type' => 'text', 'max' => 40,
                    'drives' => 'Header, mobile menu, footer, contact page, emails, invoices.'],
                ['key' => 'store.email', 'label' => 'Email address', 'type' => 'email',
                    'drives' => 'Footer, contact page and emails. Contact form enquiries are sent here.'],
                ['key' => 'store.address', 'label' => 'Address', 'type' => 'textarea', 'rows' => 2, 'max' => 500,
                    'drives' => 'Footer, contact page and invoices.'],
            ]],
            ['title' => 'Company & legal', 'fields' => [
                ['key' => 'store.company_number', 'label' => 'Company number', 'type' => 'text', 'max' => 40,
                    'drives' => 'Footer legal line and invoices.'],
                ['key' => 'store.vat_number', 'label' => 'VAT number', 'type' => 'text', 'max' => 40,
                    'drives' => 'Footer legal line and invoices.'],
                ['key' => 'store.registered_office', 'label' => 'Registered office', 'type' => 'textarea', 'rows' => 2, 'max' => 500,
                    'drives' => 'Footer legal line (only when the legal line below is blank).'],
                ['key' => 'store.legal_line', 'label' => 'Legal line', 'type' => 'textarea', 'rows' => 2, 'max' => 1000,
                    'drives' => 'Small print at the very bottom of the footer and emails.', 'help' => 'Leave blank to build it from the company name, number, VAT number and registered office.'],
                ['key' => 'store.copyright', 'label' => 'Copyright line', 'type' => 'text',
                    'drives' => 'Bottom of the footer. The year is kept up to date automatically.'],
            ]],
            ['title' => 'Social media', 'description' => 'Links for the footer icons. Leave X, YouTube and TikTok blank to hide them.', 'fields' => [
                ['key' => 'store.facebook', 'label' => 'Facebook', 'type' => 'url', 'drives' => 'Footer icon and Google business details.'],
                ['key' => 'store.instagram', 'label' => 'Instagram', 'type' => 'url', 'drives' => 'Footer icon and Google business details.'],
                ['key' => 'store.twitter', 'label' => 'X (Twitter)', 'type' => 'url', 'drives' => 'Footer icon.'],
                ['key' => 'store.youtube', 'label' => 'YouTube', 'type' => 'url', 'drives' => 'Footer icon.'],
                ['key' => 'store.tiktok', 'label' => 'TikTok', 'type' => 'url', 'drives' => 'Footer icon.'],
            ]],
            ['title' => 'Announcement bar', 'description' => 'The thin strip above the header on every page.', 'fields' => [
                ['key' => 'store.top_bar_text', 'label' => 'Message', 'type' => 'text', 'max' => 200,
                    'drives' => 'Announcement bar text.'],
                ['key' => 'store.top_bar_link_text', 'label' => 'Link text', 'type' => 'text', 'default' => 'Explore Deals', 'max' => 60,
                    'drives' => 'Announcement bar link.'],
                ['key' => 'store.top_bar_link_url', 'label' => 'Link goes to', 'type' => 'link', 'default' => '#',
                    'drives' => 'Announcement bar link.'],
            ]],
            ['title' => 'Footer', 'fields' => [
                ['key' => 'store.footer_about', 'label' => 'About text', 'type' => 'textarea', 'rows' => 3, 'max' => 1000,
                    'drives' => 'Paragraph under the footer logo.'],
                ['key' => 'store.footer_tagline', 'label' => 'Tagline', 'type' => 'text',
                    'drives' => 'Line under the footer social icons.'],
                ['key' => 'store.trust_badges', 'label' => 'Trust badges', 'type' => 'list', 'items' => 8,
                    'drives' => 'Row of badges at the top of the footer (icons repeat every 4).', 'help' => 'Remove them all to go back to the original four.'],
                ['key' => 'store.newsletter_heading', 'label' => 'Newsletter heading', 'type' => 'text', 'default' => 'Subscribe to Our Newsletter',
                    'drives' => 'Footer newsletter box.'],
                ['key' => 'store.newsletter_text', 'label' => 'Newsletter text', 'type' => 'text', 'default' => 'Subscribe for exclusive news, training tips, and event updates!',
                    'drives' => 'Footer newsletter box.'],
                ['key' => 'newsletter.success_message', 'label' => 'Newsletter thank-you message', 'type' => 'text', 'default' => 'Your submission was successful.',
                    'drives' => 'Shown after someone subscribes.'],
            ]],
        ];
    }

    protected static function checkout(): array
    {
        return [
            ['title' => 'Orders', 'fields' => [
                ['key' => 'orders.starting_number', 'label' => 'Lowest order number', 'type' => 'int', 'default' => 1000, 'min' => 1, 'max' => 999999999,
                    'drives' => 'New orders are numbered from the highest existing order number + 1, and never below this.'],
                ['key' => 'checkout.hold_stock_minutes', 'label' => 'Hold stock for unpaid orders (minutes)', 'type' => 'int', 'default' => 60, 'min' => 0, 'max' => 10080,
                    'drives' => 'How long stock stays reserved for an order waiting for card/PayPal payment before it is released.'],
                ['key' => 'inventory.low_stock_threshold', 'label' => 'Low stock warning at', 'type' => 'int', 'default' => 2, 'min' => 0, 'max' => 10000,
                    'drives' => 'Products with this many or fewer in stock appear in the dashboard’s “Low stock” list.'],
            ]],
            ['title' => 'Checkout', 'fields' => [
                ['key' => 'checkout.terms_page', 'label' => 'Terms and conditions page', 'type' => 'select', 'options' => 'pages', 'none' => 'No terms checkbox', 'default' => 'terms-conditions',
                    'drives' => 'Customers must tick “I have read and agree to the terms” linking to this page.'],
                ['key' => 'checkout.privacy_page', 'label' => 'Privacy policy page', 'type' => 'select', 'options' => 'pages', 'default' => 'privacy-policy',
                    'drives' => 'Linked from the checkout privacy notice.'],
                ['key' => 'account.registration', 'label' => 'Customers can create an account', 'type' => 'bool', 'default' => true,
                    'drives' => 'Registration form on My account. Customers can always check out as a guest.'],
            ]],
            ['title' => 'Shop & basket', 'fields' => [
                ['key' => 'catalog.hide_out_of_stock', 'label' => 'Hide out-of-stock products from category pages', 'type' => 'bool', 'default' => true,
                    'drives' => 'Product listings and search results.'],
                ['key' => 'cart.floating_button', 'label' => 'Show the floating basket button', 'type' => 'bool', 'default' => true,
                    'drives' => 'Round basket button in the corner of every page.'],
            ]],
        ];
    }

    /** Settings › Tax (the classes and rates are edited below the form: admin/settings/extra/tax.blade.php). */
    protected static function tax(): array
    {
        $include = TaxSettings::pricesIncludeTax();
        $classes = ['inherit' => 'Same as the items in the basket'] + TaxClass::options();
        $display = ['incl' => 'Including tax', 'excl' => 'Excluding tax'];

        return [
            ['title' => 'Prices and tax', 'description' => 'How the rates below are applied. Changing these affects new orders only.', 'fields' => [
                ['key' => 'tax.enabled', 'label' => 'Charge tax', 'type' => 'bool', 'default' => (bool) config('commerce.tax.enabled', true),
                    'drives' => 'Off = no tax is added or shown anywhere, whatever the rates say.'],
                ['key' => 'tax.prices_include_tax', 'label' => 'Prices are entered including tax', 'type' => 'bool', 'default' => (bool) config('commerce.tax.prices_include_tax', false),
                    'drives' => 'On (usual for UK shops): the tax is worked out from the prices you enter. Off: tax is added on top at checkout.'],
                ['key' => 'tax.based_on', 'label' => 'Work out tax from', 'type' => 'select', 'default' => (string) config('commerce.tax.based_on', 'shipping'),
                    'options' => ['shipping' => 'Customer’s delivery address', 'billing' => 'Customer’s billing address', 'base' => 'The shop’s address'],
                    'drives' => 'Which address picks the rate. Until the customer types an address, the shop’s country is used.'],
                ['key' => 'tax.rounding', 'label' => 'Rounding', 'type' => 'select', 'default' => (string) config('commerce.tax.rounding', 'line'),
                    'options' => ['line' => 'Round tax on each line (recommended)', 'order' => 'Round tax once on the order total'],
                    'drives' => 'Per line matches WooCommerce’s default and most accounting packages.'],
                ['key' => 'tax.adjust_non_base_prices', 'label' => 'Re-price for other tax rates', 'type' => 'bool', 'default' => (bool) config('commerce.tax.adjust_non_base_prices', true),
                    'drives' => 'Prices including tax only: a customer whose address has a different rate pays the price without your tax plus their own (e.g. no VAT outside the UK). Off = everyone pays the same price.'],
            ]],
            ['title' => 'Shipping', 'fields' => [
                ['key' => 'tax.shipping_taxable', 'label' => 'Charge tax on shipping', 'type' => 'bool', 'default' => (bool) config('commerce.tax.shipping_taxable', true),
                    'drives' => 'Each delivery option can also be set to “not taxable” (Settings › Shipping).'],
                ['key' => 'tax.shipping_tax_class', 'label' => 'Shipping tax class', 'type' => 'select', 'default' => (string) config('commerce.tax.shipping_tax_class', 'inherit'),
                    'options' => $classes, 'drives' => '“Same as the items” uses the standard rate when any item has it, otherwise the highest rate in the basket.'],
                ['key' => 'tax.shipping_prices_include_tax', 'label' => 'Shipping prices are entered', 'type' => 'select', 'default' => (string) config('commerce.tax.shipping_prices_include_tax', ''),
                    'options' => ['' => 'The same way as product prices', 'yes' => 'Including tax', 'no' => 'Excluding tax (WooCommerce)'],
                    'drives' => 'How the prices in Settings › Shipping are read.'],
            ]],
            ['title' => 'Showing prices', 'fields' => [
                ['key' => 'tax.display_shop', 'label' => 'Prices in the shop', 'type' => 'select', 'options' => $display,
                    'default' => (string) (config('commerce.tax.display_shop') ?: ($include ? 'incl' : 'excl')),
                    'drives' => 'Product pages, category pages, search and the product feed (at the shop’s own rate).'],
                ['key' => 'tax.display_cart', 'label' => 'Prices in the basket and checkout', 'type' => 'select', 'options' => $display,
                    'default' => (string) (config('commerce.tax.display_cart') ?: ($include ? 'incl' : 'excl')),
                    'drives' => 'Basket, checkout, order confirmation and emails. Tax is listed per rate either way.'],
                ['key' => 'tax.price_suffix', 'label' => 'Price suffix', 'type' => 'text', 'default' => (string) config('commerce.tax.price_suffix', ''), 'max' => 60,
                    'help' => 'e.g. “inc. VAT”. {price_excl} and {price_incl} show the other price.', 'drives' => 'Small text after shop prices. Blank = none.'],
                ['key' => 'tax.label', 'label' => 'Tax name', 'type' => 'text', 'default' => (string) config('commerce.tax.label', 'VAT'), 'max' => 30,
                    'drives' => 'Used for rates without their own name and for older orders.'],
            ]],
            ['title' => 'Your shop’s address for tax', 'description' => 'The country is the store country ('.TaxSettings::baseLocation()->country.'). Add a postcode or county only when a rate depends on it.', 'fields' => [
                ['key' => 'tax.base_state', 'label' => 'County / state', 'type' => 'text', 'default' => '', 'max' => 100, 'drives' => 'Only for rates limited to a county/state.'],
                ['key' => 'tax.base_postcode', 'label' => 'Postcode', 'type' => 'text', 'default' => '', 'max' => 20, 'drives' => 'Only for rates limited to postcodes.'],
            ]],
        ];
    }

    protected static function emails(): array
    {
        $toggles = [];
        foreach (static::ORDER_EMAILS as $key => [$label, $to, $when]) {
            $toggles[] = ['key' => "emails.{$key}.enabled", 'label' => $label, 'type' => 'bool', 'default' => true, 'to' => $to, 'drives' => $when];
        }

        return [
            ['title' => 'Sender', 'description' => 'Who emails from the shop appear to come from.', 'fields' => [
                ['key' => 'emails.from_name', 'label' => 'Sender name', 'type' => 'text', 'default' => setting('store.name', config('mail.from.name')), 'max' => 100,
                    'drives' => 'The “From” name on every email the shop sends.'],
                ['key' => 'emails.from_address', 'label' => 'Sender email address', 'type' => 'email', 'default' => config('mail.from.address'),
                    'drives' => 'The “From” address. Use an address on your own domain so emails aren’t marked as spam.'],
            ]],
            ['title' => 'Notifications', 'fields' => [
                ['key' => 'emails.admin_address', 'label' => 'Send order notifications to', 'type' => 'emails', 'default' => setting('store.email'), 'mirror' => ['emails.admin_recipient'],
                    'drives' => 'New, cancelled and failed order emails. Separate several addresses with commas.'],
                ['key' => 'emails.footer_text', 'label' => 'Email footer', 'type' => 'text', 'max' => 255,
                    'drives' => 'Line at the bottom of every customer email.', 'help' => 'Blank = store name and website address.'],
            ]],
            ['title' => 'Order emails', 'description' => 'Switch off an email to stop it being sent.', 'toggles' => true, 'fields' => $toggles],
        ];
    }

    /** Settings › Invoices (Pine\Commerce\Services\Invoices\Invoices reads these; defaults = config commerce.invoices). */
    protected static function invoices(): array
    {
        $numbers = app(\Pine\Commerce\Services\Invoices\InvoiceNumbers::class);
        $next = $numbers->peek();
        $config = fn (string $key, mixed $default = null) => config('commerce.invoices.'.$key, $default);

        return [
            ['title' => 'Invoice numbers', 'description' => 'Invoices get their own numbers, one after the other, separate from order numbers. A number is issued once and never reused.', 'fields' => [
                ['key' => 'invoices.numbering', 'label' => 'Give invoices their own sequential numbers', 'type' => 'bool', 'default' => (bool) $config('numbering', true),
                    'drives' => 'Invoice number on PDFs and printed invoices.', 'help' => 'Off: the invoice number is the order number.'],
                ['key' => 'invoices.assign_on', 'label' => 'Issue the invoice number when the order is', 'type' => 'select', 'default' => $config('assign_on', 'paid') === 'completed' ? 'completed' : 'paid',
                    'options' => ['paid' => 'Paid (processing or completed)', 'completed' => 'Completed'],
                    'drives' => 'When a new order gets its invoice number and invoice date.'],
                ['key' => 'invoices.prefix', 'label' => 'Prefix', 'type' => 'text', 'default' => (string) $config('prefix', ''), 'max' => 20, 'placeholder' => 'INV-',
                    'value' => fn () => (string) \Pine\Commerce\Services\Invoices\Invoices::option('prefix', ''),
                    'pattern' => '/^[A-Za-z0-9 _\/.{}#-]*$/', 'drives' => 'Text before the number. {Y}, {y} and {m} become the year and month of the invoice.'],
                ['key' => 'invoices.suffix', 'label' => 'Suffix', 'type' => 'text', 'default' => (string) $config('suffix', ''), 'max' => 20,
                    'value' => fn () => (string) \Pine\Commerce\Services\Invoices\Invoices::option('suffix', ''),
                    'pattern' => '/^[A-Za-z0-9 _\/.{}#-]*$/', 'drives' => 'Text after the number.'],
                ['key' => 'invoices.padding', 'label' => 'Minimum digits', 'type' => 'int', 'default' => (int) $config('padding', 0), 'min' => 0, 'max' => 12,
                    'drives' => 'Pads the number with zeros, e.g. 5 digits = 00042.'],
                ['key' => 'invoices.next_number', 'label' => 'Next invoice number', 'type' => 'int', 'default' => $next, 'min' => $next, 'max' => 999999999,
                    'value' => fn () => $numbers->peek(),
                    'drives' => 'The number the next invoice gets.', 'help' => 'Can only be moved forward, so no number is ever used twice.'],
            ]],
            ['title' => 'Sending and downloads', 'fields' => [
                ['key' => 'invoices.attach_customer_processing', 'label' => 'Attach the invoice PDF to the “order received” email', 'type' => 'bool',
                    'default' => (bool) $config('attach.customer_processing', false), 'drives' => 'Email sent when the order is paid (processing).'],
                ['key' => 'invoices.attach_customer_completed', 'label' => 'Attach the invoice PDF to the “order completed” email', 'type' => 'bool',
                    'default' => (bool) $config('attach.customer_completed', false), 'drives' => 'Email sent when you mark the order completed.'],
                ['key' => 'invoices.attach_customer_invoice', 'label' => 'Attach the invoice PDF to the “Order details / invoice” email', 'type' => 'bool',
                    'default' => (bool) $config('attach.customer_invoice', false), 'drives' => 'Email you send from an order page (Send email). Unpaid orders have no invoice yet, so they get no PDF.'],
                ['key' => 'invoices.customer_download', 'label' => 'Customers can download their invoice', 'type' => 'bool', 'default' => (bool) $config('customer_download', false),
                    'drives' => '“Download invoice” in My account › Orders and on the order confirmation page (guests use their private order link).'],
            ]],
            ['title' => 'Text on documents', 'fields' => [
                ['key' => 'invoices.notes', 'label' => 'Invoice notes', 'type' => 'textarea', 'rows' => 3, 'max' => 2000,
                    'drives' => 'Printed under the totals of every invoice – payment terms, returns policy, bank details.'],
                ['key' => 'documents.invoice_footer', 'label' => 'Invoice footer', 'type' => 'textarea', 'rows' => 2, 'max' => 1000,
                    'drives' => 'Text printed at the bottom of invoices.'],
                ['key' => 'documents.packing_slip_footer', 'label' => 'Packing slip footer', 'type' => 'textarea', 'rows' => 2, 'max' => 1000,
                    'drives' => 'Text printed at the bottom of packing slips.'],
                ['key' => 'invoices.paper', 'label' => 'Paper size', 'type' => 'select', 'default' => $config('paper', 'a4') === 'letter' ? 'letter' : 'a4',
                    'options' => ['a4' => 'A4', 'letter' => 'US Letter'], 'drives' => 'Page size of the PDF invoices and packing slips.'],
            ]],
        ];
    }

    protected static function seo(): array
    {
        return [
            ['title' => 'Search engines', 'fields' => [
                ['key' => 'seo.site_name', 'label' => 'Site name', 'type' => 'text', 'default' => setting('store.name', config('app.name')),
                    'drives' => 'Site name shown by Google and in shared links.'],
                ['key' => 'seo.title_suffix', 'label' => 'Title ending', 'type' => 'text', 'default' => '| '.setting('seo.site_name', setting('store.name', config('app.name'))), 'max' => 80,
                    'drives' => 'Added after every page title, e.g. “Summer Sale | Your Store”.'],
                ['key' => 'seo.default_description', 'label' => 'Default meta description', 'type' => 'textarea', 'rows' => 2, 'max' => 320,
                    'drives' => 'Used for pages that have no description of their own.'],
                ['key' => 'seo.default_image', 'label' => 'Default sharing image', 'type' => 'image',
                    'drives' => 'Image shown when a page without its own image is shared on social media.'],
                ['key' => 'seo.discourage_search_engines', 'label' => 'Ask search engines not to index this site', 'type' => 'bool', 'default' => str_contains((string) request()->getHost(), 'staging'),
                    'drives' => 'Blocks the whole site in robots.txt and adds “noindex” to every page. Only for staging sites – never on the live shop.'],
                ['key' => 'seo.robots_txt', 'label' => 'Custom robots.txt', 'type' => 'textarea', 'rows' => 7, 'max' => 10000, 'code' => true, 'mirror' => ['seo.robots'],
                    'drives' => 'Replaces the automatic /robots.txt. Leave blank for the standard rules and sitemap link.'],
            ]],
            ['title' => 'Tracking', 'fields' => [
                ['key' => 'tracking.gtm_id', 'label' => 'Google Tag Manager ID', 'type' => 'text', 'max' => 30, 'placeholder' => 'GTM-XXXXXXX', 'pattern' => '/^(GTM-[A-Z0-9]{4,12})?$/i',
                    'drives' => 'Loads your Tag Manager container on every page (after cookie consent).'],
                ['key' => 'tracking.ga4_id', 'label' => 'Google Analytics 4 ID', 'type' => 'text', 'max' => 30, 'placeholder' => 'G-XXXXXXXXXX', 'pattern' => '/^(G-[A-Z0-9]{4,15})?$/i',
                    'drives' => 'Only needed if Analytics isn’t already set up inside Tag Manager.'],
                ['key' => 'tracking.cookie_banner', 'label' => 'Cookie banner', 'type' => 'select', 'default' => 'auto',
                    'options' => ['auto' => 'Show when tracking is set up (recommended)', 'always' => 'Always show', 'never' => 'Never show'],
                    'drives' => 'The cookie consent banner at the bottom of the site.'],
                ['key' => 'tracking.cookie_text', 'label' => 'Cookie banner text', 'type' => 'textarea', 'rows' => 3, 'max' => 1000,
                    'default' => 'To provide the best experiences, we use technologies like cookies to store and/or access device information. Consenting to these technologies will allow us to process data such as browsing behaviour or unique IDs on this site. Not consenting or withdrawing consent, may adversely affect certain features and functions.',
                    'drives' => 'Message in the cookie banner.'],
            ]],
            ['title' => 'Google Shopping feed', 'feed' => true, 'feature' => 'google_feed', 'fields' => [
                ['key' => 'feeds.google_product_category', 'label' => 'Default Google product category', 'type' => 'text', 'default' => config('commerce.feeds.google.default_category'), 'max' => 255,
                    'drives' => 'Used for products without their own category (Google taxonomy ID or path, e.g. 222 = Electronics).'],
                ['key' => 'feeds.shipping_price', 'label' => 'Shipping price in the feed', 'type' => 'money', 'default' => 0, 'max' => 1000,
                    'drives' => 'Delivery cost Google shows next to your products.'],
            ]],
        ];
    }

    /** Settings › Abandoned carts – Services\Recovery\AbandonedCartRecovery (scheduled task carts.abandoned-emails). */
    protected static function abandonedCarts(): array
    {
        $recovery = \Pine\Commerce\Services\Recovery\AbandonedCartRecovery::class;
        $sections = [
            ['title' => 'Reminder emails', 'view' => 'commerce::admin.settings._abandoned-carts-intro', 'fields' => [
                ['key' => 'abandoned_carts.emails_enabled', 'label' => 'Send reminder emails', 'type' => 'bool', 'default' => false,
                    'drives' => 'Emails shoppers who left products in their basket after entering their email address at checkout (or while signed in). Needs cron or the web fallback – see Scheduled tasks.'],
                ['key' => 'abandoned_carts.consent', 'label' => 'Who can receive them', 'type' => 'select', 'default' => 'marketing', 'options' => $recovery::CONSENT,
                    'drives' => 'Marketing consent = a customer account with marketing ticked, or an active newsletter subscription. Every email has an unsubscribe link.'],
                ['key' => 'abandoned_carts.max_age_days', 'label' => 'Ignore baskets older than (days)', 'type' => 'int', 'default' => 7, 'min' => 1, 'max' => 90,
                    'drives' => 'Baskets left longer ago than this are never emailed (also when you first switch reminders on).'],
                ['key' => 'abandoned_carts.min_value', 'label' => 'Minimum basket value', 'type' => 'money', 'default' => 0, 'max' => 100000,
                    'drives' => 'Only email baskets worth at least this much at today’s prices (0 = any).'],
            ]],
        ];
        foreach ($recovery::STEPS as $n) {
            $d = $recovery::STEP_DEFAULTS[$n];
            $sections[] = ['title' => 'Email '.$n, 'description' => $n === 1 ? 'Placeholders: {name} = first name (or “there”), {store} = store name.' : null, 'fields' => [
                ['key' => "abandoned_carts.step{$n}.enabled", 'label' => 'Send this email', 'type' => 'bool', 'default' => $d['enabled'],
                    'drives' => 'Switched off, the sequence skips this email and moves on to the next one.'],
                ['key' => "abandoned_carts.step{$n}.delay_hours", 'label' => 'Hours after the last basket activity', 'type' => 'int', 'default' => $d['delay_hours'], 'min' => 1, 'max' => 720,
                    'drives' => 'Each email waits at least this long after the shopper last touched the basket, and the gap between steps after the previous email.'],
                ['key' => "abandoned_carts.step{$n}.subject", 'label' => 'Subject', 'type' => 'text', 'default' => $d['subject'], 'max' => 150],
                ['key' => "abandoned_carts.step{$n}.intro", 'label' => 'Opening text', 'type' => 'textarea', 'rows' => 3, 'default' => $d['intro'], 'max' => 1000],
                ['key' => "abandoned_carts.step{$n}.coupon", 'label' => 'Discount code', 'type' => 'select', 'default' => $d['coupon'], 'options' => $recovery::COUPON_TYPES,
                    'drives' => 'A new single-use code for this shopper’s email address only, applied automatically when they return to the basket.'],
                ['key' => "abandoned_carts.step{$n}.coupon_amount", 'label' => 'Discount (% or amount)', 'type' => 'decimal', 'default' => $d['coupon_amount'], 'min' => 0, 'max' => 100000],
                ['key' => "abandoned_carts.step{$n}.coupon_days", 'label' => 'Code valid for (days)', 'type' => 'int', 'default' => $d['coupon_days'], 'min' => 1, 'max' => 365],
            ]];
        }

        return $sections;
    }

    /** Settings › Scheduled tasks – Pine\Commerce\Scheduling\Scheduler. */
    protected static function automation(): array
    {
        return [
            ['title' => 'Background jobs', 'view' => 'commerce::admin.settings._scheduler-status', 'fields' => []],
            ['title' => 'Daily low-stock email', 'fields' => [
                ['key' => 'scheduler.low_stock_email', 'label' => 'Email a stock report every morning', 'type' => 'bool', 'default' => false,
                    'drives' => 'At 7am: products at or below their low-stock level and sold-out products, sent to the order notification addresses (Settings › Emails). Nothing is sent when nothing is low.'],
            ]],
            ['title' => 'Tidying up', 'fields' => [
                ['key' => 'scheduler.cart_retention_days', 'label' => 'Keep abandoned guest baskets for (days)', 'type' => 'int', 'default' => 90, 'min' => 0, 'max' => 3650,
                    'drives' => 'Guest baskets untouched for longer are deleted each night (0 = keep them all). Customers’ baskets and baskets that became orders are always kept. Expired sessions and password-reset links are removed too.'],
            ]],
        ];
    }
}
