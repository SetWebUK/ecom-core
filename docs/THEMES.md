# Themes

A storefront theme is a directory with a `theme.json`: Blade views, plain CSS/JS/images/fonts (no build step), optional
config files and an optional `Theme.php`. Core controllers never change per theme. Contract: ARCHITECTURE.md §7–§8.

| Theme | Where | Notes |
|---|---|---|
| `default` | `resources/themes/default` (in the package) | Complete, neutral, modern storefront – implements every contract view and email. Brand is driven by settings. |
| `{client}` | `themes/{client}` (client project) | A client theme: a child of `default`, or a full fork for a like-for-like rebuild (`public_path` can keep the old site's asset URLs, e.g. `assets`). |

## Resolution

```
view paths:  resources/views (client one-off overrides)  →  themes/{active}  →  its parent(s)  →  default
active theme: staff preview (?preview_theme=)  >  setting theme.active (Admin › Settings › Theme)  >  COMMERCE_THEME  >  "default"
```

- Bare view names (`@include('partials.header')`, `emails.orders.*`, `errors::404`) resolve through the chain; the
  `theme::` namespace is the same chain without `resources/views`.
- A child theme overrides **one file** by creating it at the same relative path. Everything else is inherited.
- `theme_config('menus.main')` reads `config/menus.php` of the chain, child over parent per top-level key.
- `theme_setting('primary_color')` = `setting('theme.{slug}.primary_color')` ?? theme.json default (lineage) ?? argument.
  Settings are edited in Admin › Settings › Theme (any installed theme, `?theme={slug}`). `"settings_inherit": false`
  in theme.json hides the parent's settings.
- `theme_asset('css/app.css')` = the first theme in the chain whose **published** copy exists, `?v=filemtime`.
- `commerce_feature('wishlist')` = config flag **and** the theme `supports` the view-bearing feature.
- `Pine\Commerce\Theme\Storefront` (used by the default theme): store details, basket count, brand CSS variables,
  font faces, inline icons and payment marks.

## PDF documents

Invoices and packing slips are Blade views too: `pdf/invoice.blade.php` and `pdf/packing-slip.blade.php` in a theme
(or `resources/views/pdf/…`) replace the core templates (`commerce::pdf.*`). They are rendered by dompdf, so use
tables rather than flex/grid and the embedded `DejaVu Sans` font. Optional – every theme inherits the core ones.
Details: [INVOICES.md](INVOICES.md).

## Commands

```bash
php artisan commerce:theme:make acme --parent=default   # themes/acme: theme.json, Theme.php, config/, views/README.md, assets/css/theme.css
php artisan commerce:theme:publish [slug] [--all] [--prune]   # COPY assets to public/{public_path} (default themes/{slug}); never symlinks
php artisan commerce:theme:check [slug]                  # theme.json, parent chain, public_path, Theme.php, every contract view
php artisan commerce:theme:cache | commerce:theme:clear  # manifest cache (run by optimize / optimize:clear)
```

`publish` writes `public/{public_path}/.commerce-theme` and refuses a directory owned by another theme; unchanged
files keep their mtime so `?v=` cache-busting only changes for edited files. Run it after every deploy.

## Building a client theme on `default`

1. `commerce:theme:make client --parent=default`, then `commerce:theme:publish client`.
2. Brand first: Admin › Settings › Theme (`?theme=client`) – colours, fonts, corner style, logo – and
   `assets/css/theme.css` (loaded automatically after the default stylesheet; override the `--c-*`, `--font-*`,
   `--radius` custom properties or any class).
3. Override only the partials you need (see `views/README.md` for the list), e.g. `partials/header.blade.php`.
4. Preview privately: sign in to /admin, open `/?preview_theme=client` (or Admin › Settings › Theme › Preview).
   The preview is per staff session, sends `no-store` + `noindex`, shows an “Exit preview” bar and never affects
   customers. Then switch it on (administrators) or set `COMMERCE_THEME=client` in `.env`.

## Rules the default theme follows (keep them in forks)

- Checkout posts the core field names (`billing_email`, `shipping_*`, `billing_*`, `bill_to_different_address`,
  `shipping_method`, `payment_method`, `order_comments`, `terms`, `createaccount`, `account_password`) and uses the core
  JSON endpoints (`/checkout/update`, `/cart/coupon`, `/checkout`, `/checkout/order-pay/{order}`).
- Basket: `POST /cart/add|update|remove` with `Accept: application/json` returns `{ok, message, count, html}` where
  `html` is the theme's `cart.side-cart`; `GET /cart/fragment` refreshes it.
- Catalogue AJAX: send the header named by `commerce.catalog.ajax_header` = `1` to get `{sidebar, results, title, total}`.
- Contact form fields are `{commerce.forms.contact.prefix}name|email|phone|subject|message` (+ `…company` honeypot).
- Accessibility: skip link, labelled controls, focus-visible styles, focus trap in drawers, `aria-live` updates.
- Images: render uploads with `<x-media-image>` (or `media_url($path, $size)` + `image_srcset()`), never a bare
  `media_url($path)` for a photo – see "Images" below.
- Views added in 1.1 (the default theme has them; a child theme inherits them): `checkout/verify-email` (order-received
  opened outside the placing session asks for the billing email: POST `key` + `email` to `$action`) and `errors/410`
  (redirect rules of type 410; falls back to `errors/404`). Product pages: show `$review->reply` under a review
  ("Response from {store}") and a banner when `$product->status !== 'published'` (only staff get there). Account frame:
  show `session('account_notice')` (e.g. "Please log in … to continue to the payment form").
- SEO partial: the meta/OG description is printed in full (like Rank Math); config `seo.description_limit` in the
  theme's `config/seo.php` caps it (characters, 0/absent = no limit).
- JSON-LD: Laravel 13 has a `@context` Blade directive, so `'@context'` inside `{!! json_encode([...]) !!}` is compiled
  to PHP. Write `'@@context'` there (Blade prints `@context`), or build the array inside `@php … @endphp`.

## Images

Uploads (admin media library, product photos, category / post / theme images) are stored on the public disk
(`public/storage/uploads/…`) and get **size variants next to the original**, named like WordPress's, so imported
WordPress media and new uploads work the same way:

```
uploads/2026/05/photo.jpg            original (EXIF-rotated and capped to images.max_dimension on upload)
uploads/2026/05/photo-150x150.jpg    "thumbnail" (150×150 crop)
uploads/2026/05/photo-400x300.jpg    "card"      (fit 400×400)       – the real pixel size is in the name
uploads/2026/05/photo-400x300.webp   WebP twin of each variant (JPEG/PNG/GIF originals, when the driver can write WebP)
uploads/2026/05/photo.webp           WebP twin of the original
```

Sizes are `config('commerce.images.sizes')` – package defaults `thumbnail` 150×150 crop, `card` 400 fit, `medium` 800
fit, `large` 1600 fit (a size is `['width' => …, 'height' => …, 'crop' => bool]`, 0 = unbounded, or an int N = fit
N×N). Generation uses PHP Imagick or GD (`images.driver`: auto | imagick | gd), never upscales, keeps animated GIFs as
uploaded, strips EXIF/GPS (keeps the colour profile) and writes `quality.jpg/webp/avif/png`. Existing media:
`php artisan commerce:images:generate --missing` (PLAYBOOK §6.1).

**Asking for a size** – always returns the best file that EXISTS: the exact variant, else the smallest variant of the
same shape that covers the requested size (e.g. a WordPress `-768x576`), else the original. Nothing breaks before the
generator has run, for URLs (`https://…`) or theme assets (`/assets/…`).

| API | Returns |
|---|---|
| `media_url($path)` | the original (unchanged since v1.0) |
| `media_url($path, 'card')`, `media_url($path, 240)` | URL of the best variant of a named size / of a 240×240 fit box |
| `image_srcset($path, 'card')` | `url 300w, url 768w, …` – every existing file with the shape of that size ('' when there is no choice) |
| `image_srcset($path, 'card', 'webp')` | the same list of WebP twins |
| `$media->sizeUrl('card')`, `$media->srcset('card')`, `$media->variants()` | the same on a `Media` model |
| `Pine\Commerce\Services\Media\Images::resolve($path, 'card')` | `['path', 'width', 'height']` of the chosen file |

**`<x-media-image>`** renders all of it:

```blade
<x-media-image :path="$image->path" size="card" sizes="(min-width: 1100px) 280px, 46vw" :alt="$image->alt" class="card__img" />
<x-media-image :path="$post->featured_image" size="large" sizes="100vw" :priority="true" />   {{-- LCP image: fetchpriority, no lazy --}}
```

→ `<picture><source type="image/webp" srcset="…" sizes="…"><img src="…-400x300.jpg" srcset="…" sizes="…" alt="…"
width="400" height="300" loading="lazy" decoding="async" class="card__img"></picture>`. The `<picture>` wrapper is
only added when every srcset candidate has a WebP twin (`images.picture_webp`, `:picture="false"` per call); give it
`picture { display: contents; }` in the theme CSS so the `<img>` stays the layout box. Props: `path`, `size`, `sizes`,
`alt`, `lazy` (true), `priority`, `picture` (true), `width`/`height` (override), `fallback` (URL when `path` is empty;
default the placeholder); other attributes (`class`, `data-*`) go on the `<img>`. JS that swaps the image (variation
pictures) must drop the `<source>` elements and the `srcset`/`sizes` attributes – the default theme's `app.js` does.

Where the default theme uses it: product cards (`card`, both images), product gallery (`large` slides, `thumbnail`
thumbs, zoom links to the original), quick view (`medium`), home hero (`large`, priority) / "why" image (`medium`) /
category tiles (`card`), blog cards (`medium`) and post hero (`large`, priority). Admin thumbnails
(`<x-admin.thumb>`) use `media_url($src, 240)`.

A client that must not change can keep the pre-v1.1 upload behaviour in its `config/commerce.php` (an `images` block
with only the 150×150 crop, no WebP, originals stored as sent) and keep using the WordPress sizes its theme always
used (`ProductPresenter::sized($path, '300x300')`), so its storefront HTML is unchanged. New clients get the package
defaults above.

## 1.1 contract changes at a glance

Everything is optional except `checkout/verify-email` for themes that are not based on `default`; run
`php artisan commerce:theme:check <slug>` after upgrading.

| Area | What a theme can use | Default theme files to copy from |
|---|---|---|
| VAT display | `Cart::totals()` adds `*_display`, `tax_lines`, `display_incl` (the old keys stay, without tax); post the address fields to `/checkout/update` so zones and tax follow the customer; show "we don't deliver to …" when no option applies | `checkout/partials/summary`, `checkout/partials/order-summary`, `cart/side-cart`, `emails/partials/order-details` |
| Images | `<x-media-image>`, `media_url($path, $size)`, `image_srcset()` ("Images" above) | `partials/product-card`, `product/partials/gallery`, `product/quick-view`, `pages/home`, `blog/show`, `blog/partials/card` |
| Invoices | PDF link on View order / order-received from `Invoices::customerUrl($order)` (null when downloads are off or the order has no invoice); `pdf/invoice`, `pdf/packing-slip` overrides ("PDF documents" above) | `account/view-order`, `checkout/thankyou` |
| Abandoned carts | `cart/unsubscribe` (core default used when the theme has none) | `cart/unsubscribe` |
| Bug-fix views | `checkout/verify-email` (required), `errors/410` (falls back to `errors/404`), `$review->reply`, draft banner, `session('account_notice')`, `seo.description_limit` | see "Rules the default theme follows" |

