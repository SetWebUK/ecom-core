# Back office (/admin): how to build a page

Read this before you add anything to `/admin`. The foundation (layout, design system, components, auth,
dashboard, discounts, profile) is done; your job is to add your area's pages **in the same style**, reusing what is here.

> **No Filament, no Livewire, no Nova/Backpack/Voyager or any admin-panel package.** The store owner removed Filament
> on purpose and does not want it back.
> Stack: Laravel controllers + Blade + Alpine.js + the hand-written CSS/JS in the package's `resources/assets/admin/`
> (copied to the client's `public/{commerce.admin.assets_url}` by `php artisan commerce:publish` – edit the package copy,
> then publish). No npm/Vite. The back office is part of the `pine/commerce` package (namespace `Pine\Commerce\`); paths
> below are relative to the package root.

**See every component live at `/admin/ui-kit`** (source: `resources/views/admin/dashboard/ui-kit.blade.php`).
The reference CRUD is **Discounts** – copy `CouponController`, `CouponRequest`, `resources/views/admin/coupons/*`
and `tests/Feature/Admin/CouponsTest.php`.

---

## 1. Map

| What | Where |
|---|---|
| Route files | `routes/admin/{core,sales,catalogue,content}.php` – edit **only your area's file**. Loaded from `routes/admin.php` by `Pine\Commerce\CommerceServiceProvider` with prefix `/admin` (`commerce.admin.path`), names `admin.*`, middleware `web` + `admin`. |
| Auth routes | `routes/admin/auth.php` (login, logout, forgot/reset password) |
| Staff gate | `Pine\Commerce\Http\Middleware\EnsureStaff` (alias `admin`) – guests → `admin.login`; customers → admin 403 page; `->middleware('admin:admin')` = administrators only |
| Controllers | `src/Http/Controllers/Admin/` (area subfolders welcome, e.g. `Admin/Catalogue/ProductController`) |
| Form requests | `src/Http/Requests/Admin/` |
| Views | `resources/views/admin/<area>/…`, referenced as `commerce::admin.<area>.…` (a client may override one in `resources/views/vendor/commerce/admin/…`) |
| Components | `resources/views/components/admin/*.blade.php` → `<x-admin.name>` (+ `src/View/Components/Admin/Sidebar.php`) |
| CSS / JS | `resources/assets/admin/css/admin.css`, `resources/assets/admin/js/admin.js` |
| Vendored libs | `resources/assets/admin/vendor/` – Alpine 3.17.4 (+collapse, focus), SortableJS 1.15.7, Chart.js 4.5.1, TinyMCE 6.8.6 (MIT), Inter font |
| Reusable logic | `src/Services/Admin/*` (OrderManager, SalesReport, OrderStatus, CouponStatus, LocalTime, PaymentMethods, Countries, CategoryTree, CatalogueTools, ProductDuplicator, NoteFormatter, StaffPassword), `src/Exports/*` |
| Tests | `tests/Feature/Admin/*Test.php` extending `Tests\Feature\Admin\AdminTestCase` |

Components that other areas need but don't exist yet? Ask for them / add a new `components/admin/*.blade.php` file –
don't restyle the shared ones for one page.

## 2. Canonical route names (the sidebar links to these)

The menu (`Pine\Commerce\View\Components\Admin\Sidebar`) links to each route when it exists (`Route::has`) and shows a muted
item until then. **Use exactly these names** for your index pages; resource routes bind models **by id**
(`route('admin.orders.show', $order)`), because global search links to them.

| Menu | Route name | Owner |
|---|---|---|
| Home | `admin.dashboard` | core ✔ |
| Orders | `admin.orders.index`, `admin.orders.show` (search links here) | sales |
| Customers | `admin.customers.index`, `admin.customers.show` (search links here) | sales |
| Analytics | `admin.reports.index` | sales |
| Products › All products | `admin.products.index`, `admin.products.edit` (search links here) | catalogue |
| Products › Categories / Attributes / Reviews / Stock alerts | `admin.categories.index`, `admin.attributes.index`, `admin.reviews.index`, `admin.stock-alerts.index` | catalogue |
| Products › Import / Export | `admin.products.csv` (+ `admin.products.csv.*`, switch `product_csv`, [PRODUCT-CSV.md](PRODUCT-CSV.md)) | catalogue |
| Discounts | `admin.coupons.*` | core ✔ |
| Content › Pages / Blog posts / Blog categories / Menus / Media / Redirects | `admin.pages.index`, `admin.posts.index`, `admin.post-categories.index`, `admin.menus.index`, `admin.media.index`, `admin.redirects.index` | content |
| Inbox › Form submissions / Newsletter | `admin.form-submissions.index` (+ `.show`), `admin.newsletter.index` | content |
| Settings (bottom) | `admin.settings.index` (+ `admin.staff.*`, `admin.shipping.*`, `admin.payments.*` highlight it) | settings owner |
| User menu › Staff accounts | `admin.staff.index` (shown to administrators only) | settings owner |

Handy query params the dashboard links use (please support them on your index pages): orders `?status=processing`,
`?q=` (search, e.g. a coupon code); products `?stock=outofstock` and `?stock=low`.

Core already defines (don't redefine): `admin.search`, `admin.search.suggest`, `admin.api.products`,
`admin.api.categories`, `admin.api.customers`, `admin.api.media`, `admin.media.upload` (POST `admin/media/upload`),
`admin.ui-kit`, `admin.profile.*`, and a GET fallback that renders the admin 404 for unknown `/admin/…` URLs.
Keep your JSON endpoints under `admin/api/…` or your own prefix. `admin.print` (invoices / packing slips) lives in `routes/admin/sales.php`.

## 3. Adding a page – the recipe

**Route** (`routes/admin/catalogue.php`):

```php
use Pine\Commerce\Http\Controllers\Admin\Catalogue\CategoryController;

Route::post('categories/reorder', [CategoryController::class, 'reorder'])->name('categories.reorder');
Route::post('categories/bulk', [CategoryController::class, 'bulk'])->name('categories.bulk');
Route::resource('categories', CategoryController::class)->except('show');   // -> admin.categories.index …
// Administrators only:
Route::middleware('admin:admin')->group(fn () => Route::resource('staff', StaffController::class));
```

**Controller** – thin, validated input only, redirect with a toast:

```php
class CategoryController extends Controller
{
    use \Pine\Commerce\Http\Controllers\Admin\Concerns\AdminIndex;     // sorting(), perPage(), searchTerm(), like(), filterValue()

    public function index(Request $request): View
    {
        $q = $this->searchTerm($request);
        [$sort, $direction] = $this->sorting($request, ['name', 'created_at'], 'name');
        $categories = Category::query()
            ->withCount('products')                                   // no N+1: eager load / withCount
            ->when($q !== '', fn ($query) => $query->where('name', 'like', $this->like($q)))
            ->orderBy($sort, $direction)
            ->paginate($this->perPage($request))->withQueryString();

        return view('commerce::admin.categories.index', compact('categories', 'q'));
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        $category = Category::create($request->categoryData());     // never $request->all(): models are $guarded = ['id']
        return redirect()->route('admin.categories.edit', $category)->with('success', 'Category created.');
    }
}
```

**FormRequest** – `authorize()` returns `$this->user()?->canAccessAdmin()` (or `->isAdmin()`), `rules()`,
friendly `messages()`/`attributes()`, cross-field checks in `after()`, and a `…Data()` method that returns the exact,
normalised attributes to save (see `CouponRequest::couponData()` – money strings like "£1,025.50" are cleaned in
`prepareForValidation()`, datetimes go through `LocalTime::fromInput()`).

**Views** – start from `admin/coupons/index.blade.php` (list) or `admin/coupons/form.blade.php` (create/edit).

**Test** – `tests/Feature/Admin/<Area>Test.php` (section 11). Run `php artisan test --filter=Admin`.

**Check it for real**: `curl` the page with a staff session and read `storage/logs/laravel.log`.

## 4. Layout

```blade
@extends('commerce::admin.layouts.app', ['width' => 'wide'])   {{-- width: (default 1000px detail/forms) | wide (lists) | narrow (settings) | full --}}

@section('title', 'Categories')                        {{-- browser tab: "Categories · {store name} admin" --}}

@section('content')
    <x-admin.page-header title="Categories" subtitle="…">
        <x-slot:actions><x-admin.button variant="primary" icon="plus" :href="route('admin.categories.create')">Add category</x-admin.button></x-slot:actions>
    </x-admin.page-header>
    …
@endsection

@push('vendor')  <script defer src="…"></script> @endpush     {{-- extra vendor libs (deferred, load before admin.js/Alpine) --}}
@push('scripts') <script> document.addEventListener('alpine:init', () => { Alpine.data('myThing', () => ({…})) }) </script> @endpush
@push('head')    <style>…</style> @endpush
```

- **Flash toasts**: `->with('success' | 'error' | 'warning' | 'info', 'Message')` (also `status`). Validation errors
  automatically show inline under each field **and** as a toast ("Please fix the 3 highlighted fields"); the first
  invalid field is scrolled into view.
- Detail pages: `<div class="layout"><div class="layout__main">…cards…</div><div class="layout__aside">…cards…</div></div>`
  (Shopify-style main + side column; collapses to one column under 1024px). Add `layout__aside--sticky` to keep the
  aside in view on tall screens.
- Footer actions under a form: `<div class="form-actions">` with a destructive `<x-admin.confirm>` on the left,
  `<span class="flex-1"></span>`, Cancel + primary Save (`form="the-form-id"`) on the right.
- Errors inside admin routes (403/404/500, missing models) render the admin error page automatically.

## 5. Page patterns

**Index (list) page** – one flush card: status tabs → filter bar → selectable table → pagination. Empty states:
"nothing yet" (with the create button) vs "no results for these filters" (with a clear-filters button).

```blade
<x-admin.card flush>
    <x-admin.status-tabs :tabs="$tabs" :current="$status" />       {{-- ['all' => ['label' => 'All', 'count' => 12], …] --}}
    <x-admin.filters placeholder="Search categories" :chips="$chips" keep="status">
        <x-admin.filter-select name="visibility" :options="['1' => 'Visible', '0' => 'Hidden']" placeholder="Any visibility" />
    </x-admin.filters>
    <x-admin.table :ids="$categories->pluck('id')" selectable :bulk-action="route('admin.categories.bulk')" stack>
        <x-slot:bulk>
            <x-admin.button type="submit" name="action" value="hide" size="sm">Hide</x-admin.button>
            <x-admin.button type="submit" name="action" value="delete" size="sm" variant="ghost-danger"
                            data-confirm-title="Delete the selected categories?" data-confirm="Products stay, but lose this category." data-confirm-button="Delete">Delete</x-admin.button>
        </x-slot:bulk>
        <x-slot:head>
            <x-admin.th sort="name">Name</x-admin.th>
            <x-admin.th align="right" sort="products_count" first="desc">Products</x-admin.th>
        </x-slot:head>
        @foreach ($categories as $category)
            <tr>
                <x-admin.row-check :id="$category->id" :label="'Select '.$category->name" />
                <td class="stack-title"><a class="row-link" href="{{ route('admin.categories.edit', $category) }}">{{ $category->name }}</a></td>
                <td class="num" data-label="Products">{{ number_format($category->products_count) }}</td>
            </tr>
        @endforeach
    </x-admin.table>
    <x-admin.pagination :paginator="$categories" />
</x-admin.card>
```

Bulk endpoint: type-hint `Pine\Commerce\Http\Requests\Admin\BulkActionRequest` (validates `ids[]` + `action`; subclass it to
change `$actions`), then `return back()->with('success', "3 categories hidden.")`.

**Form page** – `<x-admin.form … dirty>` gives the "unsaved changes" warning + Shopify save bar; cards in a
`.layout`; one `<x-admin.card title>` per topic; `.form-grid` for 2-up fields; `.stack-fields` for vertical spacing.

## 6. Components (`<x-admin.*>`)

Every component has a props comment at the top of its file. All form controls handle `old()` input, validation
errors (`name="meta[title]"` → error key `meta.title`), labels, help text, `required`/`optional` markers and ARIA.
Extra attributes (e.g. `x-model`, `placeholder`, `min`) go onto the underlying control.

> Alpine bindings **on a component tag** must be written `x-bind:attr`/`x-on:event` (or `::attr`), because `:attr`
> on `<x-…>` is Blade's PHP binding. Plain HTML elements can use `:attr`/`@event` as usual.

> Dynamic text in a component **prop** must be PHP-bound: `:title="'Delete '.$product->name.'?'"`, never
> `title="Delete {{ $product->name }}?"` – Blade escapes the `{{ }}` once when passing it and the component escapes it again
> (`15.6"` shows as `15.6&quot;`). Values inside Alpine/JS expressions go through `@js($value)` (or `Js::from`), never
> `'{{ $value }}'` – HTML escaping doesn't stop a quote from ending a JS string.

### Structure
| Component | Example |
|---|---|
| `page-header` | `<x-admin.page-header :title="$order->number" :back="route('admin.orders.index')" subtitle="…">` slots `badges`, `actions`, `meta` |
| `card` | `<x-admin.card title="Customer" subtitle="…" flush sectioned subdued>` slots `actions`, `header`, `footer` |
| `callout` | `<x-admin.callout type="info|success|warning|danger|neutral" title="…" icon="…">text</x-admin.callout>` |
| `tabs` + `tab-panel` | `<x-admin.tabs :tabs="['general' => 'General', 'seo' => 'SEO']"><x-admin.tab-panel name="general">…` (opens the tab holding a validation error; remembers `#tab-…`) |
| `modal` | `<x-admin.modal name="add-note" title="Add note" size="sm|md|lg|xl" drawer open>…<x-slot:footer>…</x-slot:footer>` open with `x-on:click="$dispatch('open-modal', 'add-note')"` / `Admin.openModal('add-note')`; inside: `hide()` |
| `dropdown` + `dropdown-item` | `<x-admin.dropdown label="More actions" icon="…" icon-only variant size align="left" up>` → `<x-admin.dropdown-item href icon danger type>` and `<div class="dropdown__sep"></div>` |
| `empty` | `<x-admin.empty icon="inbox" title="No orders yet" description="…" size="sm">buttons</x-admin.empty>` |

### Buttons & display
| Component | Example |
|---|---|
| `button` | `<x-admin.button variant="primary|secondary|danger|dark|ghost|ghost-danger|plain" size="sm|lg" icon="plus" icon-right href type="submit" label="aria label for icon-only" block disabled>` |
| `confirm` | `<x-admin.confirm :action="route('admin.x.destroy', $x)" method="DELETE" title="Delete?" message="Can’t be undone." confirm-label="Delete" variant="ghost-danger" icon="trash" as="menu-item">Delete</x-admin.confirm>` – every destructive action uses this (or `data-confirm`) |
| `badge` | `<x-admin.badge color="gray|success|warning|attention|danger|info|primary|dark|outline" dot icon size="sm">` |
| `status-badge` | `<x-admin.status-badge :status="$order->status" />`, `type="stock"` (instock/outofstock/onbackorder), `type="product"` (published/draft/private) – colours from `OrderStatus` |
| `icon` | `<x-admin.icon name="truck" variant="outline|mini" size="xs|sm|md|lg|xl" label="…" />` – **every** Heroicon v2 name (heroicons.com) |
| `thumb` | `<x-admin.thumb :src="$image?->path" :alt="…" size="xs|sm|md|lg|xl" cover />` (public-disk path or URL; placeholder icon when empty) |
| `time` | `<x-admin.time :value="$order->created_at" />` → "Today at 10:32" in **UK time** (title shows full date); `format="relative|date|datetime|<php format>"` |
| `stat` | `<x-admin.stat label="Revenue" :value="money($x)" :delta="12.5" hint="vs previous 30 days" icon="banknotes" href invert />` |
| `timeline` + `timeline-item` | `<x-admin.timeline-item icon="chat-bubble-left" color="primary" :time="$note->created_at" :author="$note->user?->full_name" bubble customer>{!! NoteFormatter::html($note->note) !!}</x-admin.timeline-item>` |

### Tables & lists
| Component | Notes |
|---|---|
| `table` | `ids`, `selectable`, `bulk-action`, `stack` (cards on phones: cells need `data-label`, title cell `class="stack-title"`, hide with `stack-hide`), `compact`, `wide` (many columns: scrolls sideways instead of sticky header). Slots `head`, `bulk`, `foot`. |
| `th` | `<x-admin.th sort="created_at" first="desc" align="right">` – toggles `?sort=&direction=`, resets page. Whitelist columns in the controller with `sorting()`. |
| `row-check` | first cell of a selectable row: `<x-admin.row-check :id="$row->id" :label="'Select '.$row->name" />` (shift-click selects a range) |
| `pagination` | `<x-admin.pagination :paginator="$rows" />` – "Showing 1–25 of 312", pages, 25/50/100 per page (`?per_page`). The app's default paginator view is the storefront's – always use this component (or `->links('admin.partials.pagination')`). |
| `status-tabs` | `:tabs="['all' => ['label' => 'All', 'count' => 9], 'draft' => 'Draft']" :current param="status" default="all"` |
| `filters` + `filter-select` | GET form; Enter submits search, selects submit on change; `:chips="['type' => 'Type: Percentage']"` removable chips; `keep="status"` carries params; `/` focuses its search box |
| `sortable-list` | drag to reorder; `:url="route('admin.menus.reorder', $menu)"` posts `{ids: [...]}` JSON after each drop (respond `{message?}`); without url renumber `<input type="hidden" name="positions[ID]" data-position>` for a normal form post |

### Form controls
| Component | Example |
|---|---|
| `form` | `<x-admin.form :action="…" method="PUT" dirty id="product-form" save-label="Save" files>` – CSRF + method spoof; `dirty` = leave-page warning + top save bar (Discard / Save); `:savebar="false"` keeps just the warning |
| `field` | wrapper for custom controls: `<x-admin.field label for help error="key" bag required optional hint>` slots `labelExtra`, `helpSlot` |
| `input` | `<x-admin.input name label type value help required optional prefix="/shop/" suffix="kg" counter="60" hint error bag wrapper="span-2" />` |
| `money` | `<x-admin.money name="price" label="Price" :value="$product->regular_price" />` – £ prefix, 2 decimals; validate `numeric|decimal:0,2` after stripping "£" and "," |
| `textarea` | `<x-admin.textarea name label rows counter code />` |
| `select` | `<x-admin.select name label :options="[value => label]" (or grouped) :value placeholder="—" multiple />` |
| `checkbox` / `toggle` | `<x-admin.toggle name="is_active" label="Visible" help="…" :checked="$x->is_active" />` – posts `1`/`0` (hidden input) so "off" is saved; `:unchecked="null"` to post nothing |
| `radio-cards` | `<x-admin.radio-cards name="type" :value :options="['simple' => ['label' => 'Simple', 'help' => '…', 'icon' => 'cube']]" />` |
| `datetime` | `<x-admin.datetime name="published_at" label="Publish" :value="$post->published_at" type="datetime|date" />` – shows UK time; save with `LocalTime::fromInput($request->input('published_at'))` (UTC Carbon) |
| `product-picker` | `<x-admin.product-picker name="related_ids" label="Related products" :value="$ids" :multiple="true" />` → posts `related_ids[]`; empty = `[]`/null. Validate `'related_ids' => ['nullable', 'array'], 'related_ids.*' => ['integer', Rule::exists('products', 'id')]` |
| `category-picker` | same, whole tree searched client-side; `exclude="$category->id"` hides a subtree (parent pickers) |
| `customer-picker` | `<x-admin.customer-picker name="user_id" label="Customer" :value="$order->user_id" />` (single by default) |
| `image-picker` | single: `<x-admin.image-picker name="image" :value="$category->image" />` → `"uploads/2026/09/x.jpg"` or null. Multiple: `<x-admin.image-picker name="images" multiple :value="$product->images" />` → `[['path' => …, 'alt' => …], …]` in display order (first = main), `[]` when all removed. Library browse + upload + drag-and-drop. |
| `rich-editor` | `<x-admin.rich-editor name="content" :value="$page->content" height="600" content-css="/assets/css/site.css" />` – TinyMCE 6, keeps any HTML (classes, styles, data-*, iframes, empty icon tags), "Source code" view, image upload/browse. Save the value as-is (trusted staff HTML) and output with `{!! !!}` on the storefront. |
| `seo-fields` | `<x-admin.seo-fields :title="$p->meta_title" :description="$p->meta_description" title-source="#f-name" description-source="#f-short_description" :fallback-title="$p->name" :url="$p->url" />` live Google preview + counters; `slug-source="#f-slug" base-url="…"` rebuilds the URL live |

Slug that follows the name until edited: `<x-admin.input name="slug" label="URL handle" x-data="slugField('#f-name')" />`
(field ids are `f-` + name with brackets turned into dashes, e.g. `meta[title]` → `f-meta-title`).

Named error bags (two forms on one page): `$request->validateWithBag('password', …)` + `<x-admin.input … bag="password" />`.

## 7. CSS reference (`admin.css`)

Tokens on `:root`: `--primary` (#1976d2, AA on white; brand `--brand` #1e88e5, accent `--brand-accent` #ff6700),
greys `--bg --surface --surface-subdued --border --text --text-muted --text-subtle`, status
`--success/-bg/-text`, `--warning…`, `--danger…`, `--info…`, spacing `--s-1`(4) `--s-2`(8) `--s-3`(12) `--s-4`(16)
`--s-5`(20) `--s-6`(24) `--s-8`(32)…, radii `--radius`(8) `--radius-lg`(12), `--topbar-h`, `--sidebar-w`. Font: Inter.

| Area | Classes |
|---|---|
| Page | `.page` `.page--wide/--narrow`, `.page-header`, `.layout` `.layout__main` `.layout__aside(--sticky)` `.layout--equal`, `.stack(--xs/sm/md/lg)`, `.row(--between/--end/--top/--nowrap)`, `.grid-2/3/4`, `.span-2`, `.form-grid(--3)`, `.stack-fields`, `.form-actions` |
| Card | `.card` `.card__header` `.card__title` `.card__subtitle` `.card__actions` `.card__body` `.card__section(--subdued)` `.card__section-title` `.card__footer(--between)` `.card--flush/--subdued/--critical` |
| Buttons | `.btn` + `--primary --danger --dark --ghost --ghost-danger --plain --sm --lg --icon --block`, `.is-loading`, `.btn-group`, `.link`, `.row-link` |
| Badges | `.badge` + `--success --warning --attention --danger --info --primary --gray --dark --outline --sm`, `.badge__dot` |
| Forms | `.field` `.field__label` `.field__help` `.field__error` `.field__counter`, `.input` `.select` `.textarea(--code)` `.input--sm/--mono/--num`, `.input-group` `.input-group__addon(--box)`, `.search-input`, `.check` `.checkbox` `.radio`, `.toggle` `.switch`, `.radio-cards` `.radio-card`, `.chip`, `.is-invalid` |
| Tables | `.table-wrap(--sticky-off)` `.table` `.table--compact/--stack/--clickable/--static-head`, `th/td.num`, `.table__check` `.table__actions` `.table__thumb`, `.th-sort`, `.cell-main` `.cell-title` `.cell-sub`, `.bulk-bar`, `.truncate` `.truncate-2` |
| Navigation | `.tabs` `.tab` `.tab__count`, `.segmented` `.segmented__item`, `.filter-bar` `.filter-chips`, `.pagination` |
| Overlays | `.modal` `.modal__panel(--sm/lg/xl)` `.modal__header/__title/__body(--flush)/__footer`, `.drawer` `.drawer__panel(--lg)`, `.dropdown` `.dropdown__menu` `.dropdown__item(--danger)` `.dropdown__sep`, `.toast`, `.savebar` |
| Feedback | `.empty`, `.spinner(--sm/--lg)`, `.skeleton(--text/--title/--thumb)`, `.loading-block`, `.is-busy`, `.callout(--success/--warning/--danger/--neutral)`, `.progress(--success/--danger)` + `.progress__bar` |
| Data | `.stats` `.stat` `.delta--up/--down`, `.chart-box(--sm)` `.legend`, `.timeline…`, `.list` `.list__item(.is-unread)` `.list__main/__title/__sub/__meta`, `.kv` (dl), `.summary-list`, `.thumb(--xs…--xl)`, `.image-grid`, `.media-library`, `.drag-handle`, `.sortable-list(__item)`, `.serp` |
| Utilities | `.text-muted/-subtle/-success/-danger/-warning`, `.text-xs/sm/base/lg`, `.text-right/center`, `.fw-400…700`, `.mono`, `.num`, `.nowrap`, `.break`, `.mt-1…8`, `.mb-2/4/6`, `.gap-1…4`, `.flex-1`, `.w-full`, `.hidden`, `.hidden-mobile`, `.only-mobile`, `.sr-only`, `.divider` |

Breakpoints: ≥1200 sticky table headers; <1024 sidebar becomes a drawer + single-column layouts; <768 form grids
stack; <640 phone (stacked tables, 16px inputs). Keep new CSS in the matching section of `admin.css`; prefer
existing classes over inline styles.

## 8. JavaScript (`admin.js`)

```js
Admin.toast('Saved')                         // type: 'success' (default) | 'error' | 'warning' | 'info'
await Admin.confirm({ title, message, confirmText, danger: true })   // -> true/false
const json = await Admin.fetch(url)                                   // GET, data -> query string
await Admin.fetch(url, { method: 'POST', data: { ids } })             // JSON body, CSRF header, Accept: JSON
await Admin.fetch(url, { method: 'POST', data: formData })            // multipart
//   rejects with Error(message) – message is user-friendly (validation message, 419 session expired…), .status, .errors
Admin.money(12.5) // "£12.50"   Admin.slugify('Linen Shirt XL') // "linen-shirt-xl"   Admin.randomCode(8)   Admin.debounce(fn, 250)
Admin.openModal('name') / Admin.closeModal('name')
Admin.markDirty(el)       // after changing a form value from JS, so the unsaved-changes bar notices
Admin.sortable(el, opts)  // SortableJS with admin defaults (load via <x-admin.sortable-list> or push the vendor script)
Admin.editor(textarea, { height, uploadUrl, contentCss }) // TinyMCE (load via <x-admin.rich-editor>)
```

Global behaviours (no code needed):
- `data-confirm="Message"` on a `<form>` or submit `<button>` (optional `data-confirm-title`, `data-confirm-button`,
  `data-confirm-danger="false"`) asks before submitting; on `<a>` asks before navigating.
- Submitting any form shows a spinner on the clicked button and disables its buttons (no double submits). Opt out
  with `<form data-no-loading>` (e.g. CSV downloads, forms with `target="_blank"` are skipped automatically).
- `/` focuses the page search (`[data-page-search]`, set by `<x-admin.filters>`) or the global search; Ctrl/⌘+K the global search.
- Unsaved-changes tracking compares the whole form; editors/pickers/image pickers already notify it. A form saved via
  AJAX can reset it: `form.dispatchEvent(new Event('dirty-reset'))`.

Alpine components you can use directly: `dirtyForm`, `bulkTable(ids)`, `picker(config)`, `imagePicker(config)`,
`seoFields(config)`, `sortableList(config)`, `richEditor(config)`, `charCount(max)`, `slugField(selector)`,
`modal(name)`, `dropdown`, `tabs(initial)`. Page-specific components: register inside
`document.addEventListener('alpine:init', () => Alpine.data('name', () => ({ … })))` in `@push('scripts')`
(see the `couponForm` script at the bottom of `admin/coupons/form.blade.php`). Keep heavy objects (Chart instances,
editors) **outside** Alpine's reactive state (closure variables).

Charts: `@push('vendor') <script defer src="{{ commerce_admin_asset('vendor/chartjs/chart-4.5.1.umd.min.js', false) }}"></script> @endpush`,
then build the chart in an Alpine component (see `admin/dashboard/index.blade.php`). One y-axis per chart; compare
periods with a dashed grey line; provide a "View as table" fallback.

## 9. Icons

`<x-admin.icon name="…">` accepts **every** Heroicons v2 name (outline 24px default, `variant="mini"` 20px solid).
Commonly used: `home inbox-stack tag user-group receipt-percent document-text envelope chart-bar cog-6-tooth
plus pencil-square trash eye eye-slash magnifying-glass funnel arrow-left arrow-right chevron-down chevron-right
check check-circle x-mark exclamation-triangle exclamation-circle information-circle printer truck banknotes
credit-card shopping-bag cube photo arrow-up-tray arrow-down-tray arrow-path document-duplicate clipboard-document
ellipsis-horizontal bars-2 star link globe-alt calendar-days clock chat-bubble-left user user-plus lock-closed`.
In JS templates use the sprite: `<svg class="icon"><use :href="Admin.assetBase + 'img/icons.svg#truck'"></use></svg>` (outline set; `Admin.assetBase` = the published asset URL).

## 10. Services & helpers you should reuse

- **Dates**: stored UTC, shown UK time. Display with `<x-admin.time>` or `LocalTime::format($date, 'j M Y, H:i')`;
  parse form input with `LocalTime::fromInput($value)`; `LocalTime::toInput($date)` for `datetime-local` values.
- **Money**: `money($amount)` → "£1,234.00"; inputs via `<x-admin.money>`.
- **Order statuses**: `Order::STATUSES`, `Order::PAID_STATUSES`, `OrderStatus::label/color/icon()`, `OrderStatus::STOCK_STATUSES`,
  `OrderStatus::PRODUCT_STATUSES`; always change status with `$order->updateStatus($status, $note)` (fires emails).
- **Sales numbers**: `SalesReport::lastDays(30)` → `totals()`, `series()`, `hourlySeries()`, `topProducts()`, `byCategory()`,
  `newCustomers()`, `previous()`, `SalesReport::trend($now, $before)`. Revenue = paid statuses, net of refunds, UK days.
- **Orders**: `OrderManager` (notes, refunds via gateway when available, manual orders, stock adjustments, re-send emails).
- **Picker option shapes**: `SearchController::productOption($p)`, `::categoryOption($c)`, `::customerOption($u)`.
- **Media**: upload endpoint response / `MediaUploadController::present($media)` → `{id, path, url, location, thumb, filename, alt, width, height}`
  (URLs are root-relative `/storage/…` so saved HTML works on any domain). Files land in `public/storage/uploads/YYYY/MM/`.
- **Passwords**: `StaffPassword::check($user, $plain)` (accepts + upgrades WordPress hashes), `StaffPassword::rule()` for new passwords.
- **Settings**: `setting('key', $default)` / `Setting::set()`. Keys read by core: `store.name`, `seo.site_name`, `seo.title_suffix`,
  `inventory.low_stock_threshold` (dashboard low-stock list, default 2).
- **CSV**: `Pine\Commerce\Exports\CsvExport::stream($filename, $headings, $rows)` (+ `OrdersCsvExport`, `NewsletterCsvExport`); give the
  export form `data-no-loading`.

## 11. Testing

```php
namespace Tests\Feature\Admin;

class CategoriesTest extends AdminTestCase          // real MySQL data inside a transaction – rolled back after each test
{
    public function test_pages_render(): void
    {
        $this->actingAsStaff('manager');              // throwaway admin|manager user (rolled back too)
        $this->get(route('admin.categories.index'))->assertOk();
        $this->get(route('admin.categories.index', ['q' => 'shirt', 'sort' => 'name', 'direction' => 'desc']))->assertOk();
    }

    public function test_store(): void
    {
        $this->actingAsStaff()->post(route('admin.categories.store'), [...])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('categories', [...]);
    }
}
```

- **Never** `RefreshDatabase` / `migrate:fresh` – the database holds the imported live shop. `AdminTestCase` switches
  phpunit's SQLite settings back to the `.env` MySQL connection and wraps each test in a transaction.
- Fake side effects: `Storage::fake('public')` for uploads, `Notification::fake()` / `Mail::fake()` for emails.
- Cover: every page 200 for staff, customers get 403, guests redirect to `admin.login`, validation errors, each action's
  database effect, bulk actions. Run: `php artisan test --filter=Admin`.
- Then check the real thing with curl and a cookie jar, and read `storage/logs/laravel.log` (APP_DEBUG is off).
  There is no shared test login: create a temporary staff user, use it, **delete it afterwards**:

  ```bash
  php artisan tinker --execute='App\Models\User::forceCreate(["name"=>"Tmp","first_name"=>"Tmp","email"=>"tmp-admin@example.invalid","password"=>Hash::make("Tmp-pass-12345"),"role"=>"admin","is_active"=>true]);'
  B=$(php artisan tinker --execute='echo config("app.url");'); J=/tmp/jar   # the site's APP_URL
  T=$(curl -s -c $J $B/admin/login | grep -o 'name="_token" value="[^"]*"' | head -1 | cut -d'"' -f4)
  curl -s -b $J -c $J -X POST $B/admin/login --data-urlencode _token=$T --data-urlencode email=tmp-admin@example.invalid --data-urlencode password=Tmp-pass-12345 -o /dev/null
  curl -s -b $J $B/admin/your-page -o /dev/null -w '%{http_code}\n'
  php artisan tinker --execute='$u=App\Models\User::where("email","tmp-admin@example.invalid")->first(); DB::table("sessions")->where("user_id",$u->id)->delete(); $u->delete();'
  ```
  The three imported WordPress administrators keep their own WordPress passwords – never change them.

## 12. Security & quality checklist

- CSRF on every form (`<x-admin.form>` / `@csrf`); JSON calls through `Admin.fetch` send the token.
- Validate with a FormRequest; save only the request's normalised data array (no `$request->all()`, no `fill($request->input())`).
- Staff check is automatic (`admin` middleware); administrators-only pages use `admin:admin` or `abort_unless($request->user()->isAdmin(), 403)`.
- Escape everything with `{{ }}`. Only trusted staff-authored HTML (editor content) may use `{!! !!}`; order notes go through `NoteFormatter::html()`.
- Uploads only via `admin.media.upload` (images only, sanitised unique names, public disk).
- No N+1: eager load (`with`, `withCount`, `->with(['images' => fn ($q) => $q->orderBy('sort_order')->limit(1)])`), paginate every list, filter on indexed columns.
- Accessible: real `<label>`s (components do this), `aria-label` on icon-only buttons (`label=` prop), visible focus, keyboard-usable menus.
- Friendly copy for a non-technical shop manager: say what happened ("3 orders marked as completed."), explain consequences in confirm dialogs.
- Destructive actions: `<x-admin.confirm>` or `data-confirm`. Empty states for every list. Loading states are automatic.

## 13. Sign-in & accounts (for reference)

`/admin/login` (5 attempts/minute per email+IP, 20 per IP), only active admin/manager users; imported WordPress
admins sign in with their existing WordPress passwords (hash upgraded on first sign-in, password unchanged).
`/admin/forgot-password` emails staff a link to `/admin/reset-password/{token}` (`Pine\Commerce\Notifications\AdminResetPassword`;
storefront resets unaffected). Logout is `POST admin.logout`. `/admin/profile` edits name/email/phone/password.

## Content additions (content & settings area)

Built on everything above – same layout, components, CSS tokens and `admin.js`. The pieces below were added for
pages, blog, menus, media, redirects, inbox and settings; reuse them anywhere.

### Routes (`routes/admin/content.php`)

| Area | Route names |
|---|---|
| Pages (+ home page builder) | `admin.pages.*` (resource, no show), `admin.pages.bulk`, `admin.pages.duplicate` |
| Blog | `admin.posts.*`, `admin.posts.bulk`, `admin.post-categories.*` (param `{postCategory}`) |
| Menus | `admin.menus.*` (edit = builder, update = whole tree as JSON) |
| Media | `admin.media.index`, `.show` (JSON detail + where used), `.update` (JSON alt/title), `.destroy`, `.bulk`, `.scan` |
| Redirects | `admin.redirects.*`, `.bulk`, `.import` (CSV), `.export` |
| Inbox | `admin.form-submissions.index/.show/.destroy/.bulk/.export/.unread` (param `{submission}`), `admin.newsletter.index/.toggle/.destroy/.bulk/.export` |
| Settings | `admin.settings.index`, `admin.settings.edit/update` (`{group}` = general, checkout, emails, seo), `admin.shipping.*` + `.reorder`, admin-only: `admin.payments.edit/update`, `admin.staff.*` + `admin.staff.password` |
| JSON | `admin.api.links` (`GET admin/api/links?q=` → `{groups: [{label, items: [{label, url, sub}]}]}`: pages, categories, products, posts; no `q` = common shop links) |

### Components

| Component | Use |
|---|---|
| `<x-admin.link-input name label value help required />` | URL field + **Browse** button (link search dialog). Stores site-relative paths (`/contact-us/`), full URLs, `mailto:`/`tel:`. |
| `<x-admin.link-picker />` | The link search dialog (one per page; `link-input` includes it). JS: `Admin.pickLink(function (url, item) {…}, current)`. |
| `<x-admin.media-bridge />` | Hidden media library for JS: `Admin.pickImage(function (item) { item.path … })`. Shares its `@once` key with `<x-admin.rich-editor>`, so there is never a second copy. |
| `<x-admin.html-editor />` | Rich-text dialog (TinyMCE, small toolbar) for short HTML inside repeaters (FAQ answers): `Admin.editHtml({ value, title }, function (html) {…})`. No focus trap, so TinyMCE's own dialogs work. |

All three load `resources/assets/admin/js/content.js` (pushed to the `vendor` stack; its components register on `alpine:init`).

### JavaScript (`resources/assets/admin/js/content.js`)

- `Admin.mediaUrl(path)` – `uploads/2026/09/x.jpg` → `/storage/uploads/2026/09/x.jpg` (URLs and `/…` paths kept).
- `blocksEditor({ blocks, errors, blanks })` – structured data bound to `b` with named inputs (`blocks[hero][title]`); helpers
  `add(list, blankKey)`, `remove`, `move(list, i, ±1)`, `sortable(el, () => list)`, `pickImage(obj, prop)`, `pickLink(obj, prop)`,
  `editHtml(obj, prop, title)`, `err(key)`, `hasErrors(prefix)`. Rendered generically from a schema by
  `admin/pages/_blocks.blade.php` → `_block-list` (repeater) → `_block-field` (text, multiline, textarea, html, link, image, int, bool, ids).
- `menuBuilder({ items, maxDepth })` – nested SortableJS tree (3 levels) on Alpine-owned `x-for` lists. **Pattern for any
  Sortable + `x-for` list:** on drop put the DOM node back and reorder the data array instead (`bindSortable()` in content.js).
- `mediaManager(config)` – media page: upload queue (drop anywhere, 3 parallel uploads through `admin.media.upload`), detail drawer.
- `listInput(items, max)` – short list of texts posting `name[]` (settings “trust badges”); `linkInput` – the Browse button.

### CSS (section 20 of `admin.css`)

`.repeater` (`__list __item __head __title __index __tools __body __grid __fields __foot __empty`), `.builder-section`
(+ `__toggle`), `.media-slot` (`__preview(--sm) __path`), `.html-preview`, `.link-picker` (`__results __group __option __label __url __custom`),
`.menu-tree` (`__list`, `.is-dragging`), `.menu-node` (`__card __row __main __text __label __url __meta __actions __edit __thumb`),
`.settings-layout` + `.settings-nav(__link)`, `.settings-grid` + `.settings-tile(__icon __title __text)`, `.drives` (the “what this
setting changes on the site” line), `.secret-state`, `.copy-field`, `.media-grid` + `.media-card(__img __name __meta __type)`,
`.media-drop(__msg)`, `.media-detail__preview`, `.usage-list`, `.message-body`, `tr.is-unread`, `.unread-dot`.
`css/editor-site.css` (admin assets) is loaded after the storefront CSS inside the page/post editor.

### Services

- `Pine\Commerce\Services\Admin\StoreSettings` – every setting key the storefront reads, grouped (label, type, default, “drives”), with
  `rules()`, `value()`, `save()`; add a field there and it appears on the settings screen. Input names use `__` for dots
  (`store.phone` → `store__phone`).
- `Pine\Commerce\Services\Admin\PageBlocks` – `$page->blocks` schema per template (home = `HomeController::defaults()` keys, faq, show_title),
  validation rules, cleaning, defaults merge (`forEditing`, `withSkeleton`, `blanks`, `extra`). Unknown keys are edited as JSON.
- `Pine\Commerce\Http\Requests\Admin\Content\RedirectRequest::normaliseFrom()/normaliseTarget()/problem()` – redirect paths are stored the
  way `ResolveController` looks them up (lower-case, no slashes); loops, self-redirects and addresses answered by a page,
  category or shop route are refused.
- `Pine\Commerce\Providers\StoreMailServiceProvider` – applies Settings › Emails sender name/address to all outgoing mail.

### Behaviour notes

- Page/post HTML is only replaced when the editor reports an edit (`content_changed=1`), so saving SEO fields never re-serialises
  imported Elementor markup. The editor loads the storefront CSS (+ the page's legacy `post-{wp_id}.css`).
- Payment secrets (`payments.stripe.secret_key`, `.webhook_secret`, `payments.paypal.secret`) are stored with `Crypt::encryptString`,
  never rendered back; blank = keep, “Remove it” = clear.
- Menus: saving touches the menu (new cache stamp for the storefront's `MenuComponent`), keeps item ids, deletes removed ones.

## Sales additions (orders, customers, abandoned checkouts, analytics)

Built on everything above – same layout, components, tokens and `admin.js`. Page-specific extras live in two small files,
loaded on Sales pages only with `@include('commerce::admin.orders._assets')` (reuse it on any page that needs them).

### Routes (`routes/admin/sales.php`)

| Area | Route names |
|---|---|
| Orders | `admin.orders.*` (resource, `{order}` numeric), `.bulk` (processing / completed / on-hold), `.export` (GET, same query as the list + `format=orders|lines`, `ids=1,2,3`), `.quote` (POST JSON, live totals), `.status` (PUT), `.fulfil`, `.address` (PUT), `.email`, `.notes.store`, `.notes.destroy`, `.refunds.store` |
| Printing | `admin.print` – `GET admin/print/{invoice|packing-slip}?orders=1,2,3` (≤ 200, one A4 page each). Moved here from `routes/web.php`. |
| Customers | `admin.customers.*` (resource minus edit, `{customer}` = `User`), `.bulk` (marketing on/off), `.export`, `.address` (PUT), `.note` (PUT), `.password-reset`, `.toggle-active` |
| Abandoned checkouts | `admin.carts.index` (Orders › Abandoned checkouts in the menu) |
| Analytics | `admin.reports.index` (`?range=today|yesterday|7d|30d|90d|month|last_month|year|last_year|12m|custom&from=&to=&compare=previous|year|none&by=day|week|month`), `admin.reports.export` (`&table=sales|products|categories|payments|customers`) |
| JSON | `admin.api.sales.products` (`?q=` → products with price, stock, variations), `admin.api.sales.customer` (`/{id}` → contact + saved addresses) |

List query params: orders `?status=processing|on-hold|pending|completed|cancelled|refunded|failed|all` (no status = To fulfil, or All
while searching/filtering), `q`, `from`, `to` (UK dates), `payment`, `refunds=yes|no`, `via=checkout|admin`, `customer={userId}`;
customers `q`, `orders=yes|no`, `account=registered|guest|disabled`, `marketing=yes|no`, `sort=name|created_at|orders_count|total_spent|last_order_at`.

### JavaScript (`resources/assets/admin/js/sales.js`)

- `<tr data-href="…">` – the whole row opens the link (Ctrl/⌘/middle click = new tab; clicks on links, buttons, inputs and the row checkbox are ignored).
- `Sales.print(url, ids)` – open invoices / packing slips for the selected rows (`Sales.print(route('admin.print', ['document' => 'invoice']), selected)` inside a `bulkTable`).
- `Sales.copy(text, label)` – clipboard + toast (addresses, emails, tracking numbers, payment links).
- Alpine: `orderForm(initial, config)` (create / edit order: product + customer search, lines, live server quote), `refundForm(config)`,
  `reportChart(jsonScriptId, hasCompare)` (Chart.js line, compare period dashed grey, metric switch).

### CSS (`resources/assets/admin/css/sales.css`)

`tr[data-href]`, `.date-range` (+ `__sep`) compact from–to inputs for filter bars, `.money-was` (struck-out original total above a
refunded amount), `.line-items` / `.line-item` (`__name __meta __price __total`, `.is-refunded`), `.sum-rows` / `.sum-row`
(`__label __hint`, `--total --muted --danger`) for totals, `.address` (pre-line), `.aside-section` (+ `__title`), `.contact-line`,
`.pager-btns`, `.composer` (+ `__bar`, `.is-customer`) note box, `.input--qty`, `.input--price`, `.line-editor` (editable lines table,
stacks on phones), `.search-results`, `.customer-chip`, `.report-toolbar`, `.stats--8`, `.share-cell`, `.split-stat`, `.table-scroll`, `.cart-items`.

### Services

- `OrderFilters` – the orders list filters/search/tab counts (shared by the list and its CSV export); `OrderFilters::forCustomer($q, $user)`
  = the customer's orders + guest orders with the same email (used everywhere a customer's orders are counted).
- `OrderPricing::quote([...])` – prices a back-office order: product lines (+ price override), custom lines, coupon codes (validated/applied with
  the storefront `CouponEngine` when present), extra discount, shipping method + cost override, tax at `setting('tax.rate')`.
  The form's live totals and the saved order use the same call.
- `OrderManager` – `changeStatus`, `fulfil` (tracking + completed), `addNote` (customer notes email `Pine\Commerce\Mail\CustomerNote`), `refund`
  (row-locked; gateway refunds through `Pine\Commerce\Services\Payments\PaymentManager::refund()` when it supports the gateway, otherwise manual;
  restock; shipping refunds; fully refunded → status refunded), `createManualOrder` (stock + coupon usage via the checkout's `OrderStock`, so
  cancelling restores stock), `updateOrder` (items only while pending / on hold / failed), `saveAddress`, `delete` (unpaid only, soft),
  `resendableEmails()` / `resendEmail()` / `sendInvoice()`.
- `Pine\Commerce\Mail\Admin\CustomerInvoice` – “Order details / invoice” email; while unpaid it carries a *Pay for this order* link
  (`route('checkout.pay', ['order' => number, 'key' => order_key])`). Uses the storefront email layout when present.
- `SalesReport` (extended) – `totals()` adds `gross_sales` (subtotal), `tax`; `buckets('day'|'week'|'month')` full per-period figures;
  `topProducts()` / `byCategory()` are net of refunded units; `byPaymentMethod()`, `customerTypes()` (new vs returning by email),
  `previousYear()`, `granularityFor($days)`. Rules: paid statuses only, refunds subtracted, by UK order date (DST-safe).
- `CustomerQuery` – customers list with `orders_count`, `total_spent` (paid, net of refunds), `last_order_at`, `location` as SQL subqueries.
- `PaymentMethods::transactionLink($gateway, $id)` – Stripe / PayPal dashboard link for a transaction id.
- Exports: `OrdersCsvExport::download($query, $name, 'orders'|'lines')` (UK times), `CustomersCsvExport`.
- `users.admin_note` (migration `2026_09_23_000910_add_sales_admin_columns`) – private staff note on a customer.

### Behaviour notes

- Forms with several address modals on one page prefix their fields (`billing_first_name`, `shipping_first_name`, hidden `address_type`)
  and use the `address` error bag, so a failed save re-opens the right modal with its own old input.
- Refund / note / customer forms use the `refund`, `note` and `customer` error bags for the same reason.
- Staff accounts show on the customer page read-only unless you are an administrator.

## Catalogue additions (products, variants, categories, attributes, reviews, stock alerts, inventory)

Built on everything above – same layout, components, tokens and `admin.js`. Page-specific extras live in two small files,
loaded on catalogue pages with `@include('commerce::admin.products.partials.assets')` (`['sortable' => true]` also loads SortableJS).

### Routes (`routes/admin/catalogue.php`)

| Area | Route names |
|---|---|
| Products | `admin.products.*` (resource minus show, `{product}` numeric), `.bulk` (publish · draft · instock · outofstock · feature · unfeature · add_category · remove_category · adjust_price · set_sale · end_sale · delete · restore), `.price-preview` (POST JSON dry run of `adjust_price`), `.quick` (PATCH JSON prices/stock), `.featured` (POST JSON star), `.duplicate`, `.restore` (soft-deleted), `.export` (CSV, same query as the list or `?ids=1,2`), `.images.store` (POST photo upload → `uploads/products/YYYY/MM`, same JSON as `admin.media.upload`) |
| Variants | `admin.variations.update` (PATCH JSON, Inventory page), `admin.variations.destroy` – the product editor saves variants with the product form |
| Inventory | `admin.products.inventory` (`/admin/inventory`, `?q=&stock=low|outofstock|instock|onbackorder|untracked&category=`), `.inventory.export`, `.inventory.import` (upload) → `.inventory.preview` (`/admin/inventory/import/{token}`, dry run) → `.inventory.apply` (names sit under `admin.products.*` so the Products menu stays open) |
| Categories | `admin.categories.*` (resource minus show), `.reorder` (POST JSON `{nodes:[{id,parent_id}], redirects}` – whole tree in order), `.visibility` (POST JSON) |
| Attributes | `admin.attributes.*` (resource minus show; `store` also answers JSON), `.values.store` (form or JSON – an existing value with the same name is reused), `.values.update` (rename, JSON or form; slug kept), `.values.destroy` (unused only), `.values.reorder` (JSON ids), `.values.merge` (`ids[]` + `target_id`) |
| Reviews | `admin.reviews.index` (`?status=pending|approved|all&rating=&q=`), `.approve`, `.unapprove`, `.reply` (PUT), `.destroy`, `.bulk` |
| Stock alerts | `admin.stock-alerts.index` (`?status=waiting|all&q=`), `.notify` (`product_id` or `all=1`), `.bulk` (ids = product ids: notify · delete), `.destroy` |

Products list query params: `status=all|active|draft|outofstock|onsale|featured|trashed`, `q` (name, SKU, variant SKU), `category`
(includes sub-categories), `stock=instock|low|outofstock|onbackorder`, `type=simple|variable`, `price_min`, `price_max`, `condition`,
`brand`, `sort=name|price|stock|updated_at|total_sales`.

### JavaScript (`resources/assets/admin/js/catalogue.js`)

- `productForm(config)` – the product editor: title → URL handle (+ full URL preview from the main category), category checklist
  (search, “show selected”, main-category radio, chips), price/sale/cost with live profit + margin, attribute rows (token field: pick or
  create values inline via `admin.attributes.values.store`), variants (options → “Create variants from options” = every missing
  combination, bulk price/stock, per-variant image from the product’s photos, active switch), reorderable specification rows.
- `quickEdit(config)` – Products list inline editor (price, sale price, track quantity/quantity or status); its popover is teleported and
  positioned `fixed`, so it is never clipped by a scrolling table. `featuredToggle(config)` – optimistic star.
- `bulkPrice(config)` – “Change prices” dialog inside a `bulkTable` (reads `selected`), server preview while typing.
- `categoryTree(config)` – nested SortableJS lists (`[data-tree-list]` / `[data-tree-item]`), confirm before a move changes URLs,
  keyboard up/down buttons, visibility toggle.
- `valueRow(config)` – attribute value: inline rename / delete. `stockCell(config)` – Inventory row inputs that save on change.
- `Catalogue.sortArray(el, { draggable, handle, onMove(from, to) })` – SortableJS for lists Alpine renders with `x-for` (puts the DOM back
  and lets you reorder the array).
- **Untouched rich-text fields keep their original HTML**: TinyMCE re-serialises content on load (adds `<tbody>`, re-indents…). On pages
  that load `catalogue.js`, a rich-text field the user didn’t change submits the original markup byte-for-byte
  (`Catalogue.restoreUntouchedEditors(form)` runs on every submit). Other areas can copy this if they need exact round-trips.

Gotchas we hit (worth knowing everywhere):
- Never name a form field `attributes` (or `action`, `submit`, `id`…) inside an Alpine `x-data` form – `form.attributes` gets
  clobbered by the input and Alpine can’t read the form’s directives (we post `product_attributes[]`).
- Don’t name component methods after `Object.prototype` members (`valueOf`, `toString`…) or after methods of an enclosing component
  (`toggle` inside a `bulkTable` row) – Alpine resolves names through the whole scope chain.

### CSS (`resources/assets/admin/css/catalogue.css`)

`.product-cell` (`__text __name __meta`), `.price-stack` (`__was __sale`), `.cell-button` (click-to-edit cell), `.star-btn(.is-on)`, `.qe__pop`
(`__foot __hint`) quick-edit popover, `.preview-table` / `.preview-box` / `.diff-old` / `.diff-new` (before → after tables), `.url-preview`,
`.money-summary` (price / profit / margin), `.subsection(__title)`, `.media-card` (bigger main photo), `.check-tree` (`__item __name
__primary`, indent with `--depth`), `.chip--main`, `.token-field` (`__input __menu __option(--create)`), `.attr-row(s)`, `.spec-row(s)`,
`.variant-table` (+ `.variant-image` picker popover, `.variant-bulk`), `.cat-tree` (`__list __row __main __name __path __meta __count
__move`), `.vis-btn`, `.value-row`, `.review` (`__head __text __reply __actions`), `.stars`, `.alert-emails`, `.inv-row--variant`,
`.inv-qty`, `.save-state(--saved/--error)`, `.count-pill`, `.filters-wrap` (many filter selects: two per row on phones), plus a fix that
keeps `<x-admin.seo-fields>`’ Google preview from widening the page on phones.

### Services

- `Catalogue\ProductSaver::save($product, ProductRequest)` – one transaction: columns, categories + main category, photos (by path),
  specs, attributes (Condition/Brand from the side panel are matched to – or create – values of the `condition` / `make` attributes and
  mirrored into `products.condition` / `products.brand`), variants (update/create/delete; parent price, regular price and stock status
  recalculated by `refreshVariable()`), related products. Then clears storefront caches, adds a 301 when the URL handle changed and
  emails waiting back-in-stock customers if the product (or a variant) became available. `quickUpdate($productOrVariant, $changes)` for
  inline edits.
- `Catalogue\ProductFilter` – list filters, tab counts (one query), on-sale / low-stock SQL. `Catalogue\PriceAdjuster` – bulk price
  preview/apply, sale %, end sale (variants included). `Catalogue\InventoryCsv` – parse (header aliases, `;`/tab delimiters, BOM),
  plan (dry run), apply. `Catalogue\UrlRedirects` – `snapshot($categoryIds)` before / `fromSnapshot()` after a URL change writes one
  301 per moved category/product URL into `redirects` (drops rules that would loop, flattens chains).
- `CategoryTree` – `flat()` (+ depth), `options()`, `nested()`, `descendantIds()`, `ancestors()`, `productCounts()`, `wouldCycle()` – all
  from one query. `CatalogueTools` – `stock($item)` label/colour, `lowStockThreshold()`, `refreshReviewStats()`, `notifyBackInStock()`,
  `sendStockAlert()`, `flushStorefrontCaches()` (facet options, Google feed, sitemap). `ProductDuplicator` (copies specs’ key/description,
  unique SKUs for variants, never copies `deleted_at`).
- `Pine\Commerce\Mail\BackInStock` (`new BackInStock($stockNotification)`) – branded email (`admin/emails/back-in-stock.blade.php` on the
  `emails.layouts.base` layout).
- Migration `2026_09_23_001300_add_reply_to_product_reviews` – `product_reviews.reply` + `replied_at` (public reply from the shop; the
  storefront may show it under the review).

## 1.1 additions (tax, shipping zones, invoices, scheduled tasks, abandoned carts, product CSV, images)

All built with the components and patterns above – no new admin library. Settings groups (`admin.settings.edit`,
`{group}` below) use the shared settings form, which since 1.1 also supports read-only section panels and fields
without help text; a group tied to a feature (`'feature' => …` in `StoreSettings`) returns 404 while it is off.

| Screen | Route names | Notes |
|---|---|---|
| Settings › Tax | `admin.settings.edit` (`tax`), `admin.tax.classes.store` / `.destroy`, `admin.tax.rates.save` (PUT, whole table of one class), `admin.tax.export` / `admin.tax.import` (WooCommerce tax-rate CSV) | options + tax classes + rate table per class ([TAX-AND-SHIPPING.md](TAX-AND-SHIPPING.md)); replaces the old "VAT rate" field of Settings › Checkout |
| Settings › Shipping | `admin.shipping.index`, `admin.shipping.zones.*` (resource minus show) + `.reorder`, `admin.shipping.zones.methods.reorder`, `admin.shipping.*` (method create/edit per type), `admin.shipping.classes.*`, `admin.shipping.countries.update`, `admin.shipping.reorder` | zones list with drag-to-reorder (SortableJS), zone editor, method editor per type (flat, free, weight/price bands, pickup), shipping classes, countries you sell to |
| Settings › Invoices | `admin.settings.edit` (`invoices`) | numbering, prefix/suffix/padding, next number (forward only), attachments, customer download, notes/footers, paper ([INVOICES.md](INVOICES.md)) |
| Order › Print menu | `admin.orders.pdf` (`/admin/orders/{order}/pdf/{invoice\|packing-slip}`, `?inline=1`), `admin.orders.invoice.regenerate` (POST) | invoice number under Payment details |
| Orders list bulk | `admin.pdf` (`/admin/pdf/{document}?orders=1,2&format=pdf\|zip`, ≤ 200) | merged PDF or ZIP; `admin.print` pages have a Download PDF button |
| Settings › Scheduled tasks | `admin.settings.edit` (`automation`) | cron heartbeat, each task's last/next run and result (read-only panel), low-stock email, guest basket retention |
| Settings › Abandoned carts | `admin.settings.edit` (`abandoned_carts`, feature `abandoned_carts`) | send reminders (off), consent, max age, minimum value, three steps with subject/intro/coupon |
| Orders › Abandoned checkouts | `admin.carts.index` (Reminders column + recovery stats), `admin.carts.show` (basket timeline), `admin.carts.stop` (POST) | dashboard card once reminders are on or used |
| Products › Import / Export | `admin.products.csv`, `.csv.export`, `.csv.upload` → `.csv.mapping` / `.csv.map` → `.csv.run` (page drives `.csv.start` / `.csv.step` batches) → `.csv.report`, `.csv.destroy` | feature `product_csv`; each batch is one request, pause/resume, per-row CSV report ([PRODUCT-CSV.md](PRODUCT-CSV.md)) |
| Media / uploads | `admin.media.upload` (unchanged URL) | every upload goes through `ImageGenerator` (sizes + WebP per `commerce.images`); `<x-admin.thumb>` shows the 240px-or-nearest size via `media_url($src, 240)` |
| Settings › System | `admin.settings.system` | doctor checks now include "Image sizes" and the cron heartbeat |
