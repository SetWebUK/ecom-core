# Changelog

All notable changes to `pine/commerce` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses [Semantic Versioning](https://semver.org/)
(what counts as public API and as a breaking change: `docs/UPGRADING.md`).

Every entry lists, where relevant: **Added / Changed / Fixed / Removed**, **config keys added** (with their defaults),
**theme contract changes**, **migrations**, and **client actions required** after `composer update pine/commerce`.

## [Unreleased]

## [1.11.0] - 2026-10-30

A drag-and-drop page builder: staff who don't write code can lay out any content page with banners, columns,
pictures, buttons, reviews, questions and live products, in the shop's own colours and fonts. Also: Meta Pixel and
Google Ads purchase conversions, purchases tracked from the order-received page, and imported products with sizes
can be saved again.

### Added
- **Visual page builder** (Content › Pages › *page* › **Design page**, or "Save and design" on a new page): a
  full-screen designer built on [GrapesJS](https://grapesjs.com) 0.23.6 (BSD-3-Clause, vendored in
  `resources/assets/admin/vendor/grapesjs-0.23.6`). The canvas loads the active theme's CSS, `css/page-design.css` and
  the Admin › Settings › Theme colours and fonts, so it looks like the live page; computer / tablet / phone views; undo /
  redo; preview; Save, and Save and publish for drafts; Ctrl+S; a warning before leaving with unsaved changes.
  - **Blocks**: ready-made sections (banner, picture and text, text and picture, three highlights, call to action,
    customer review, questions and answers, picture gallery, text section, products, contact), layout (section, two /
    three / wide-and-narrow columns, space, line), basics (heading, text, list, button, picture, quote, YouTube / Vimeo
    video, Google map) and shop blocks (**Products**: best sellers, newest, on sale, featured or a category, 4–24
    products, 2–4 per row; **Contact form**).
  - **Plain-language settings** for whatever is clicked: link search ("Goes to"), open in a new tab, button style,
    section background (light panel / brand colour / dark / a picture from the media library), banner shade, picture
    description; **Style** with brand colour swatches (saved as `var(--c-…)`, so designs follow later theme colour
    changes), sizes in words (Small … Giant) instead of pixels, spacing, width, corners, shadow, and "More options".
    Styles apply to the clicked part only; set in the phone or tablet view, they apply to that screen size.
  - Pictures come from the media library (double-click a picture, or "Change picture"); links from the link search.
  - First open: start with the page's current text, a story page, a promotion page or a blank page.
- **`pages.design`, `pages.design_html`, `pages.design_css`** (migration `2026_10_28_000200_add_design_to_pages`):
  the builder's project data, the cleaned page body and its styles. A designed page (`Page::isDesigned()`) shows the
  design instead of its text, full width, whatever its template (home and blog pages can't be designed). Its text is
  kept: **"Use the text editor instead"** on the page form removes the design and the text shows again.
- `Content\PageDesign`: `cleanHtml()` (removes scripts, styles, event handlers, `javascript:` / `data:` links and any
  iframe but YouTube, Vimeo and Google Maps embeds; lazy-loads pictures after the first), `cleanCss()` (scopes every
  rule to `.pb-page`, drops `@import`, `expression()`, `javascript:` URLs and `<`), `render()`, `starter()`,
  `products()`.
- **`[product_grid source=best-sellers|newest|sale|featured|category category=ID limit=8 columns=4]`** shortcode and
  `<div data-component="product-grid" …>` placeholder (theme partial `partials.product-grid`).
- Admin routes `admin.pages.design` (GET/PUT/DELETE `pages/{page}/design`) and `admin.pages.design.css` (the theme
  colours and fonts for the canvas).

- **Meta Pixel and Google Ads purchase conversions** (Admin › Settings › SEO & tracking: "Facebook (Meta) Pixel ID",
  "Google Ads purchase conversions" as the event snippets' `send_to`, `AW-…/label`, comma-separated for several
  accounts, e.g. the shop's own and an agency's: each account gets the tag and counts the sale). The Pixel loads only once a visitor
  accepts marketing cookies (on load, or straight from the cookie banner) and sends PageView, ViewContent (from the
  product page's Product JSON-LD, so any theme), AddToCart (app.js's new `store:added-to-cart` event) and Purchase
  (value, currency, SKUs, the order number as `eventID`). The Google Ads tag loads through gtag.js under Consent Mode
  and counts each paid order once, with its value and order number. The cookie banner's "auto" mode shows when either
  is set. The tracking head moved to `partials.tracking` (used by `layouts.app` and `checkout.layout`).
- **Go-live sync** (WooCommerce API import: "Items imported before" › **Keep this site's changes**, `--keep-local`;
  the default once a shop has had a completed import). For syncs after the first import and the last one before the
  switch-over: new products, customers, orders and coupons come in, orders take the shop's latest status, products and
  variations only its latest stock and prices (`Importer::KEEP_LOCAL_FIELDS`). Everything edited on the new site stays:
  product names, descriptions, SKUs, pictures, categories, linked products and added sizes, categories, customers,
  coupons, pages and posts; Shipping and Tax are not read (that step overwrote delivery prices even with "Leave them
  alone"). The import page warns when orders placed on the new site (test orders) hold order numbers the shop's own
  orders need.
- **Settings › SEO › Category page title** (`seo.category_title`): the default title of category pages without their
  own SEO title, `%name% Archives` (WordPress / Yoast, as before) or e.g. `%name%` (Rank Math), before the title ending.
  Later pages of a category keep "| Page n of m".
- **`MAIL_MAILER=smtp2go`**: email through SMTP2GO's web API (`Mail\Support\Smtp2GoTransport`) with `SMTP2GO_API_KEY`
  (a key allowed to send emails; config `commerce.mail.smtp2go`). Attachments such as invoice PDFs, inline images,
  Cc / Bcc, Reply-To and `X-` / `List-Unsubscribe` headers are sent; a refused message throws like a failed SMTP send.
  The skeleton's `.env.example` lists the key.
- **Page sections: picture help.** A theme's `image` block field can carry `'help'` (e.g. the size it needs: "Square,
  at least 800 × 800 px"); the page editor shows it under the picture, in sections and list items.
- **Register page**: `?email=` pre-fills the email (for "create an account" boxes elsewhere on a theme), and with
  loyalty on and welcome points set, the lead names them ("Get 200 points – worth £5.00 off your first order – …").
- **Shop events for GA4, Google Ads remarketing, the Meta Pixel and Tag Manager, like for like with a WooCommerce
  shop's plugins** (Google Analytics for WooCommerce, Pixel Manager, GTM Kit). `Services\Marketing\Ecommerce` (set by
  the controllers) feeds `partials.tracking`: `view_item_list` (shop, categories, search and theme listings through
  `CatalogController::render`), `view_item`, `add_to_cart` (the add-to-basket JSON's `tracking`), `begin_checkout` (a
  click on a link to the checkout; every basket JSON carries `tracking_cart`) and `purchase` (moved from
  `checkout.thankyou`, once per order). Each goes to Tag Manager's dataLayer (GA4 ecommerce format), to GA4 (item_id =
  the SKU, else "#" + the product ID, as the WooCommerce plugin's "product SKU" setting), to the Google Ads accounts
  with **Settings › SEO & tracking › Google Ads dynamic remarketing** (items with the feed's IDs and
  `google_business_vertical: retail`, as Pixel Manager), and to the Meta Pixel (ViewContent, AddToCart,
  InitiateCheckout, Purchase; `content_ids` are now the feed's IDs, not SKUs, so they match a catalogue built from the
  feed). Each is also the document event `store:track` for a theme's own tags.
- **`Services\Marketing\ProductIds`**: one product ID for the Google Shopping feed and every tag. An imported product
  keeps its WordPress post ID (catalogues, audiences and history built on the old shop still match); one made on this
  site uses its own ID, with a "p" in front in a shop that has imported products, where a plain number could be an old
  product's ID (feed `g:id` too); a variation its WordPress ID, else "v" + its ID.
- **Settings › SEO & tracking › Send page views to Google Analytics** (`tracking.ga4_page_views`, default on): untick
  when Tag Manager already sends GA4 page views; the shop's events still go to the GA4 ID.
- With Tag Manager on the page too, the shop's gtag.js keeps its own queue (`storeGtagLayer`, `window.storeGtag()`), as
  WooCommerce's plugins did with `dataLayerPYS`, so neither library reads the other's commands and no conversion can be
  counted twice; consent and `set` commands go to both. With Google Ads set, `url_passthrough` keeps ad clicks
  attributed when a visitor declines cookies.
- **Google Shopping feed: clothing fields, clean descriptions, delivery left to Merchant Center.** Google requires
  colour, size, gender and age group for clothing: `g:color` comes from the colour attribute, `g:size` from a
  variation's size option plus its fitting / length option ("+2XL Short", so sizes of one product don't look like
  duplicates), `g:gender` / `g:age_group` from the product's gender / age attributes, else the new **Settings › SEO &
  tracking › Google Shopping feed › Gender / Age group** (config `commerce.feeds.google.*_attributes` names the
  attributes). Descriptions lose page-builder shortcodes (`[vc_row]` …) as well as HTML. **Send the delivery cost in
  the feed** (`feeds.include_shipping`, default on): untick to leave delivery to Merchant Center's delivery settings
  (e.g. free over a certain spend, which a per-item feed price can't say).
- **Loyalty points: pause spending** (Admin › Settings › Loyalty points › Spending › **Customers can spend points**,
  `loyalty.spending`, config `commerce.loyalty.spending`, default on; `Loyalty::canSpend()`). Untick it and customers
  still earn points (paid orders, welcome bonus) and see their balance, but can't use them: checkout says using them is
  paused for now, My account › Points and the register page stop promising money off, `POST /cart/points` refuses, and
  points chosen in a basket before the pause are not taken off.
- With a GA4 ID set, the order-received page also sends GA4's `purchase` event through gtag.js (it was only pushed to
  Tag Manager's dataLayer, which nothing read without Tag Manager).

### Fixed
- **Purchases are tracked again: the order-received page loads Tag Manager / Analytics.** `checkout.layout` loaded no
  tracking at all, so the `purchase` dataLayer event on the order-received page never reached Tag Manager or GA4. That
  page now gets `partials.tracking`; the checkout and order-pay pages, where card details are entered, still load no
  third-party scripts.
- **Products imported through the WooCommerce REST API can be saved again.** The API shows a variation that has no
  SKU of its own with its parent's SKU, and the importer stored that copy on every size, so the admin refused to save
  the product ("The SKU … is used more than once"). The importer now leaves such variations without a SKU, and
  migration `2026_10_30_000100_clear_copied_variant_skus` clears the copies already imported. Orders, emails and
  reports fall back to the product's SKU, as WooCommerce did. Imports read straight from the WordPress database were
  not affected.

### Theme contract
- Optional view **`partials.tracking-page`** (`$tracking`, `$trackingOrder`, `$adsAccounts`) for a theme's own tags,
  included before the shop's events are sent (listen for `store:track`); send Google tags through `window.storeGtag()`.
  `checkout.thankyou` no longer sends the purchase itself: a child theme overriding it must drop its copy of that
  script, or purchases are sent twice.
- Child themes overriding `checkout.partials.summary`, `account.points` or `auth.register` can check
  `Loyalty::canSpend()` to word the paused state; points can't be spent while it is off whatever the view shows.
- New partial **`partials.tracking`** (`['part' => 'body']` for the noscript frame). Child themes overriding
  `layouts.app` or `checkout.layout` should include it in place of their own Tag Manager / GA4 snippet; the layouts
  no longer compute `$gtmId` / `$ga4Id`. `app.js` dispatches `store:added-to-cart` (detail: the cart response) after
  a successful add to basket.
- New optional view **`page.designed`** → `pages.designed` (falls back to `pages.full-width`, then `pages.default`,
  which get the same variables). The default theme adds `pages/designed.blade.php`, `partials/product-grid.blade.php`
  and `assets/css/page-design.css`; child themes inherit them and can restyle the builder's `pb-*` classes.

### Migrations
- `2026_10_28_000200_add_design_to_pages` (additive: three nullable long-text columns on `pages`).
- `2026_10_30_000100_clear_copied_variant_skus` (data: clears a variation's SKU where it equals its product's; not
  reversible, the values were copies).

### Client actions
- None beyond the usual: `php artisan migrate`, `commerce:publish`, `commerce:theme:publish` (Admin › Updates runs
  them all).

## [1.10.0] - 2026-10-28

Easier page editing: imported pages open as readable text instead of the old page builder's code, and the editor's
everyday buttons are always on screen.

### Added
- **Old page-builder code hidden in the editor.** Pages and blog posts imported with WPBakery / Impreza shortcodes
  (`[vc_row bg_type=”no_bg” …]`, `[vc_column_text]`, `[us_text]` …) open in Content › Pages / Blog posts as the
  HTML customers already see (`PageContent::tidyForEditor()`, the storefront's `cleanLegacyShortcodes()`), with a
  note saying so. The tidy version is stored the first time the content is changed and saved; the storefront output
  is the same before and after. Saving only the title or SEO fields leaves the stored content alone, as before.
- **Redirect notice on a page.** When an active redirect rule starts at a page's own address, the page form says
  so: a draft page is skipped and visitors are sent to the target (with a link to edit that page instead), and a
  published page is shown instead of the redirect. Typical case: an imported copy of the contact page whose old
  address now redirects to `/contact/`.

### Changed
- **Rich-text toolbar** (`<x-admin.rich-editor>`, everywhere): only the everyday buttons (paragraph/heading, bold,
  italic, link, bullet and numbered lists, image, video, table, alignment, clear formatting, source code, full
  screen), wrapping onto a second row instead of hiding behind "•••" (phones keep one row with "•••"). Underline,
  strikethrough, colours and indents are in the Format menu, visual blocks in View. The product short description
  editor is 40px taller to make up for the second row.
- Page and blog post content editors **grow with the text** (new `grow` prop / `Admin.editor({grow: true})`:
  TinyMCE `autoresize`, from the given `height` up), so the page has a single scroll bar, and the toolbar stays
  under the top bar while scrolling.
- `PageContent::cleanLegacyShortcodes()` unpacks the old builder's encoded blocks instead of leaving their code as
  text: `[vc_raw_html]` / `[us_html]` (base64 of URL-encoded HTML) become that HTML, and `[vc_gmaps]` becomes its
  Google Maps iframe (in `<div class="wp-legacy-map">`; only `google.*/maps/` embeds are kept).

### Fixed
- The Google preview in the SEO fields (pages, posts, products, categories) showed shortcode code and HTML entities
  (`[vc_row bg_type=&#8221;no_bg&#8221;…`) as the description when the meta description was blank. It now uses the
  same plain text as the storefront (`PageContent::excerpt()`).
- `PageContent::excerpt()` (storefront meta descriptions, cards) no longer runs words together across block tags
  (`<h4>Store</h4>Unit 2` read "StoreUnit 2").

### Client actions
- None. Run `php artisan commerce:publish` after updating (admin JS changed); Admin › Updates does it.

## [1.9.1] - 2026-10-28

Updates no longer depend on GitHub's API rate limit.

### Fixed
- `commerce:new-client` (and so the client skeleton) adds `"no-api": true` to a GitHub `vcs` repository. Composer then
  reads the release tags with git instead of the GitHub API, which allows 60 calls an hour per server IP without a
  token. On a shared server that limit ran out, composer fell back to an SSH clone with no key, and Admin › Updates
  failed with "Could not authenticate against github.com" or "Permission denied (publickey)". Release zips download
  as before.
- Admin › Updates: the pre-flight log warns when `composer.json` still reads GitHub through the API, and a failed
  `composer update` that mentions github.com says how to fix it.

### Client actions
- Existing sites: add `"no-api": true` by hand to the pine/commerce entry under `repositories` in `composer.json`, e.g.
  `{"type": "vcs", "url": "https://github.com/SetWebUK/ecom-core.git", "no-api": true}`, and commit it
  (`composer config` mangles array-style repositories). `composer validate` then says the lock file is out of date
  until the next `composer update pine/commerce` rewrites it; that is harmless.

## [1.9.0] - 2026-10-28

The back office in the shop's own brand: the sidebar, buttons and charts take the storefront theme's colours and the
store logo, with no set-up. (Built with 1.8.0 but released after it was tagged.)

### Added
- **Brand shell** (`Services\Admin\AdminBrand`). It takes the storefront theme's `primary_color` / `accent_color`
  (Settings › Theme) and Settings › Store details › Logo.
  - Full-height sidebar in the brand colour, darkened until white text reads on it (10:1). The shop's logo sits on a
    white tile at the top (or its initials and name), with **View shop** and a **Create** menu (order, product,
    discount, page) under it. The page you're on is marked in the accent colour, lightened in HSL until it shows (3:1).
  - Buttons, links, focus rings and the sales charts use the brand colour when white text reads on it (4.5:1).
    Otherwise they keep the default blue.
  - The top bar is white and holds search and the account menu. On phones it shows the logo, and the sidebar is a
    drawer under it.
  - The unsaved-changes bar is in the brand colour with a white Save button. The admin login page shows the shop's
    logo.
- `Admin.color(token, alpha)` in admin.js gives a colour token (e.g. `--primary`) for canvas drawing.

### Changed
- Sidebar: **Analytics** moved up next to Discounts. Content and Inbox are now under a **Website** heading. Client
  menu entries added with `after:` join that entry's section; entries without one are listed last, under **More**.

### Config keys added
- `commerce.admin.brand.color` and `commerce.admin.brand.accent` (default `null` = the theme's colours; `color: false`
  = the neutral look). A client `config/commerce.php` that overrides the whole `admin` key gets `null` for both,
  which is the default anyway.

### Client actions
- `php artisan commerce:publish` (admin CSS and JS changed: `admin.css`, `admin.js`, `sales.js`); `composer deploy`
  and Admin › Updates already run it.
- Check the back office once: it now uses the theme's colours and the store logo. Set `commerce.admin.brand.color`
  if the storefront colour doesn't suit it.
- Sites that override `admin/partials/sidebar`, `admin/partials/topbar`, `admin/layouts/app`, `admin/layouts/auth`,
  `admin/dashboard/index` or `admin/reports/index` in `resources/views/vendor/commerce/` should diff them.

## [1.8.0] - 2026-10-28

Back-office journeys, from a walk through the admin as a shop owner: what needs doing is on the home page, stock
warnings see sizes, sending a batch of orders goes from one to the next, and two screens that were hard to use are fixed.

### Added
- Home › **To do** card (`Services\Admin\StoreTasks`), above the sales figures. It lists only what needs action, most
  urgent first, each item linking to the screen that fixes it. It is hidden when there's nothing to do.
  - For administrators, go-live problems: no payment method switched on, a method switched on without its keys,
    payments in test mode (Stripe test keys or PayPal sandbox, one entry for PayPal and its card/wallet methods), no
    active delivery option, emails not being sent (`MAIL_MAILER=log|array`), a placeholder sender address, no products.
  - For all staff, work waiting elsewhere: orders on hold, reviews waiting for approval, unread contact-form messages,
    and customers waiting for back-in-stock emails. Switched-off features (`reviews`, `contact_form`, `stock_alerts`)
    are skipped.
- Order page: after **Mark as completed**, a "N more orders to send" box links straight to the next paid order
  waiting (oldest first, like the home page), or says "All caught up" after the last one.

### Changed
- Home › Stock now uses the same rules as Products › Low stock / Out of stock, so its numbers match the lists it
  links to. Low stock now includes **variants** (the list shows "5XL · SKU …  1 left"), not only simple products,
  and a new line counts sold-out variants on products still for sale, linking to Inventory › Out of stock.
- Product editor › Variants: the **Tax class** column only shows when a variant already has its own tax class, or
  after "Tax some variants differently from the product". The table now fits the editor from 1280px wide, so
  **Active** and **Remove** no longer need sideways scrolling. Cells are slightly tighter, and the SKU box is 120px.
- Orders list on phones: one compact card per order (number, name + total, date + status) instead of four labelled
  rows, so about twice as many orders fit on the screen. The keyboard tip under the list is hidden on phones.
- Buttons inside a callout are no longer underlined like links (`.callout a:not(.btn)`).

### Fixed
- `commerce:doctor` (and Settings › System › Health checks) now flags PayPal in sandbox mode as "TEST mode"; it only
  recognised Stripe's test keys before.

### Client actions
- `php artisan commerce:publish` (admin CSS changed: `admin.css`, `catalogue.css`, `sales.css`); `composer deploy` and
  Admin › Updates already run it.
- Sites that override `admin/dashboard/index`, `admin/orders/show`, `admin/orders/index` or
  `admin/products/partials/variants` in `resources/views/vendor/commerce/` should diff them against the new views.

## [1.7.0] - 2026-10-28

### Added
- Automatic discounts in the shop price: Admin › Discounts › Automatic › "Show the reduced price in the shop". While a
  % off or amount-off-each-item discount runs, every product it covers (the whole shop, chosen categories or products,
  minus exclusions) shows the price after it as a was/now price, like a sale price, on product cards, product
  pages, the sticky bar, the variant picker, the Google feed (`g:sale_price`) and the product schema (`priceValidUntil` = the
  discount's end). Badges ("SAVE 20%") follow. The basket keeps taking it off by its label, and the shown price is
  worked out by the basket's own `CouponEngine`, so the two agree to the penny (it stacks on a sale price unless
  "Don't discount items that are already on sale" is ticked). Only for discounts that need nothing from the basket:
  the form refuses the option next to a minimum/maximum spend, a customer email list or a uses-per-customer limit.
  When a total-uses limit runs out, the price goes back to normal.
- `ProductPresenter::shopDiscounts()`, `discountedPrice()`, `variationPrice()`, `variationSale()`, `discountEndsAt()`;
  `Coupon::showsInPrice()`, `Coupon::PRICE_TYPES`.

### Changed
- `ProductPresenter::price()`, `sale()`, `bestSaving()`, `priceHtml()`, `cardPriceHtml()`, `saveBadge()` and the
  product page's variation data include the discounts shown in the price. Nothing changes until a discount has the
  option ticked.
- `ProductPresenter::offers()` items have `in_price` (true when the shop price already shows that discount; `price`
  is then the price shown). An offer's `price` is now worked out on top of the discounts in the price, as the basket does.

### Known limits
- Sorting by price and the price filter still use the stored `products.price` (before automatic discounts).

### Migrations
- `2026_10_28_000100_add_show_in_price_to_coupons`: `coupons.show_in_price` (boolean, default false: existing
  discounts keep showing as an offer only). **Client action:** `php artisan migrate`.

### Theme contract
- A theme that prints `offers()` with "taken off in your basket" wording should check `in_price` (the price shown
  already includes it). Client presenters that override `sale()` or `price()` should call the parent, or use
  `discountedPrice()`, to show discounts in the price.

## [1.6.1] - 2026-10-23

### Fixed
- Admin › Updates rolled back any release that adds a `commerce:doctor` check which fails on the site (e.g. 1.6.0's
  Database › Time zone): the health step counted it as a new failure. A failing check that did not exist before the
  update now logs a WARNING (meta `doctor_introduced`) and the update completes; a check that passed before and fails
  after still rolls back, and every failure still counts when the doctor could not run before the update. Takes
  effect for updates started from this version on.
- Updater errors quoted the last output line, which for composer and artisan is the command's usage synopsis
  ("update [--with WITH] …"); they now quote the message under Symfony's "In File.php line N:" header
  (`ProcessResult::errorLine()`), e.g. "Could not authenticate against github.com".

## [1.6.0] - 2026-10-15

### Migrations
- `2026_10_15_000100_add_sale_dates_to_product_variations`: `product_variations.sale_starts_at` / `sale_ends_at`
  (nullable). **Client action:** `php artisan migrate`, then re-run the WooCommerce import's products step (or wait for
  the next sync) so imported variations get their sale dates.
- `2026_10_15_000200_add_automatic_discounts_to_coupons`: `coupons.auto_apply`, `label`, `buy_quantity`,
  `show_countdown` (additive; existing codes keep `auto_apply = false`). **Client action:** `php artisan migrate`.
- `2026_10_15_000300_add_labels_to_products`: `products.labels` (JSON, nullable). **Client action:** `php artisan migrate`.
- `2026_10_15_000400_create_loyalty_points`: table `loyalty_points` (the points ledger) and `carts.loyalty_points`.
  **Client action:** `php artisan migrate`.

### Fixed
- Order emails, the thank-you page and PDF invoices name each discount as customers saw it ("Any 2 tees for £25",
  "Points (400)") instead of internal codes; checkout stores the names in order meta `coupon_labels`
  (`Order::discountNames()`). Staff screens keep the codes.
- Order attribution: every order's Origin was "Direct (typed in / bookmark)" – checkout reads the attribution cookie
  (`commerce.checkout.attribution_cookie`) but nothing wrote it. The default theme's `js/app.js` now records it: the last
  campaign link (`utm_*`, Google Ads `gclid`/`gbraid`/`wbraid` → google / cpc) or other website / search engine, the
  landing page, first-visit time and pages viewed, for 30 days; a typed-in visit keeps the earlier source, and the
  checkout / basket pages and returns from PayPal / Stripe / Klarna / Clearpay never replace it. Where the cookie banner
  is shown it waits for statistics consent (and records straight after "Accept all"). The cookie name reaches the
  script through `#store-config` (`attributionCookie`). Child themes keep it as long as they load `js/app.js`.
  Tests: `OrderAttributionTest`.
- Imports (WooCommerce API and database): a product image alt longer than 255 characters (a whole description pasted
  into it) no longer stops the import with "Data too long for column 'alt'" on MySQL – it is cut to 250 characters,
  like the media library's. Regression test added.
- MySQL/MariaDB: the connection's session time zone now follows the app (UTC). Without it MySQL used the server's own
  clock: on a server set to UK time every summer TIMESTAMP was stored an hour out, and a UTC time inside the hour the
  clocks skip in March (e.g. `2025-03-30 01:39:36`) was refused with "Incorrect datetime value" – which stopped a
  WooCommerce import half-way through the customers. Both importers now refuse to start (before writing anything) while
  the session and app time zones differ, with the fix in the message.
- Default theme: the header now reads the **Header menu** (location `mega`) before **Header menu (backup)** (`main`),
  as Admin › Menus labels them – a site with both showed the backup and ignored the menu the admin marks "in use". The
  mobile drawer falls back to the header menu (`mega`, then `main`) when no mobile menu has items. Regression test added.
- WooCommerce API import: a page of results that came back empty no longer ends the list. WordPress drops the items a
  request may not read *after* paging (media attached to a draft or private post), so a whole page can be empty with
  more after it: on a live shop the media step stopped at page 514 of 691 and ~1,700 images were never downloaded,
  with no error. Paging now ends at `X-WP-TotalPages` only. Regression test added (`FakeWooShop::$hidden`).
- Admin › Orders › Ready to send (and Edit tracking): **Carrier** is now a drop-down of the store's carriers plus any
  used before, with "Other…" for a typed name. It was a typed box pre-filled with "DPD" whose browser suggestions only
  match what is already typed, so staff only ever saw DPD. The carriers come from `commerce.shipping.carriers` (it was
  only documentation before; the admin used a hard-coded list), and their `{number}` URLs now drive the tracking link
  in the completed-order email, order tracking and My account ("Royal Mail Tracked 48" matches "Royal Mail"), before the
  built-in list. Tests: `FulfilmentCarrierTest`.
- Scheduled sales on products with variants: a variant's sale price now only applies between its sale dates. Variants
  had no dates – the schedule only covered simple products, and both WooCommerce importers dropped each variation's
  `date_on_sale_from/to`, so a sale scheduled on the old shop went live the moment it was imported and never ended.
  The editor's **Schedule the sale** now shows for products with variants (copied onto every variant with a sale
  price), the bulk **Put on sale** gains **Sale starts** and dates the variants too, the "On sale" filter and the
  scheduled price refresh (`RefreshSalePrices`) follow variant dates. Tests: `ScheduledVariantSaleTest`.

### Added
- Search mode **precise** (config `commerce.catalog.search` = `precise`; default `broad` = unchanged): every word
  must be in the name, SKU, brand, a category name or a filterable attribute's value (e.g. Colour "Black" – not an
  imported "Tags" attribute of search phrases); descriptions are only
  searched when that finds nothing. "black" no longer returns navy products whose description says "also available in
  black", and finds black products named without the colour. The live suggestions follow the same rule.
  `ProductListing::searchMode()`. Tests: `PreciseSearchTest`. **Config key added:** `catalog.search` – a client config
  with its own `catalog` block adds it to switch.
- **Loyalty points** (feature `loyalty`, default off; `Services\Loyalty\Loyalty`), like WooCommerce Points & Rewards:
  signed-in customers earn points on paid orders (per £1 of items after discounts, VAT included, delivery not), spend
  them at checkout ("Use my points", shown as a discount line "Points (400)"), get a welcome bonus for a new account;
  a cancelled / failed / refunded order takes back what it earned and gives back what it spent. A ledger
  (`LoyaltyPoint`: signup, earn, redeem, refund, reverse, adjust, import), so every balance is explained. My account ›
  Points (balance, rules, history), a points card with adjust on each admin customer, Admin › Settings › Loyalty points
  (points per £1, points for £1 off, welcome bonus, minimum, maximum share of an order, name), and
  `php artisan commerce:loyalty:import --from-wordpress [--dry-run]` for balances straight from WooCommerce Points &
  Rewards in the WordPress database (read only; the importer's WP_DB_* settings or `--wp-path`; the REST API doesn't
  expose them) or `commerce:loyalty:import file.csv` (re-runnable: adds only the difference). Exports:
  `php artisan commerce:loyalty:export [file] [--all]` (email, points, worth – importable again) and a Points column in
  Admin › Customers › Export. The basket stores the points chosen as at most the balance. On phones the checkout summary stays open when there are points to use. Points are checked under lock at checkout so they can't be spent twice. **Config keys added:**
  `features.loyalty` (false), `loyalty.*` defaults. Tests: `LoyaltyPointsTest`.
- Payment method **Debit or credit card (via PayPal)** (`paypal_card`, `PaypalCardGateway`): the PayPal app and keys of
  Admin › Settings › Payments › PayPal, with PayPal's page opening on its card form (`landing_page: GUEST_CHECKOUT`) –
  card payments without a separate card provider, as the WooCommerce PayPal Payments plugin's "Debit & Credit Cards".
  Off until switched on; refunds, the order's transaction link and `/webhooks/paypal` work for it (the PayPal webhook
  now completes a payment under the method it was started with). **Config:** `payments.gateways` gains `paypal_card`
  (a client config with its own `gateways` list adds it to offer it). Tests: `PaypalCardGatewayTest`.
- Payment methods **Google Pay** and **Apple Pay through PayPal** (`paypal_googlepay` / `paypal_applepay`,
  `PaypalGooglePayGateway` / `PaypalApplePayGateway`, base `PaypalWalletGateway`): the PayPal app and keys of Admin ›
  Settings › Payments › PayPal, through PayPal's JS SDK (`components=googlepay,applepay`) – what the WooCommerce PayPal
  Payments plugin offers, no Stripe account needed. Off until switched on.
  - The checkout lists a wallet only once PayPal says the account can take it and the browser can pay that way (Google
    Pay set up; Apple Pay on Apple devices, or in other browsers through Apple's QR code). While one is chosen, the
    wallet's own button stands in for Place order.
  - Flow: the customer approves in the sheet → the order is placed → the browser confirms the PayPal order with the
    wallet's payment data (3-D Secure when PayPal asks) → the return URL captures it. `checkout/payment/{gateway}/return`
    now answers `{result, redirect, messages}` when asked over AJAX. Refunds, transaction links and `/webhooks/paypal`
    work as for PayPal (`PaypalGateway::PAYPAL_CODES`).
  - Apple's domain check: `/.well-known/apple-developer-merchantid-domain-association` serves PayPal's own file (sandbox
    or live, following the PayPal sandbox switch; `resources/payments/apple-pay`) while PayPal has its keys.
    `TrailingSlash` leaves `/.well-known/` addresses alone.
  - PayPal account: Google Pay and Apple Pay ticked under the REST app's Features (live: Advanced Checkout onboarding),
    and for Apple Pay each domain registered with PayPal.
  - **Config:** `payments.gateways` gains `paypal_googlepay` and `paypal_applepay`. Theme: `checkout.show` and
    `checkout.pay` load `$wallets['scripts']` and pass `wallets` in `#checkout-config`; `checkout.partials.payment-methods`
    starts methods with `browserWallet()` hidden. Admin labels include the legacy Woo `ppcp-googlepay` / `ppcp-applepay`.
    Tests: `PaypalWalletGatewayTest`.
- **Product labels** ("New Season", "Special offer"): `products.labels`, set in the product editor (Organisation),
  in bulk from the Products list (More actions › Add / remove a label…) and in the product CSV (`labels` column).
  Presenter `labels(Product)` adds automatic labels from attribute values, config `commerce.catalog.labels.attributes`
  (e.g. `['fitting' => ['tall' => 'Available in Tall fitting']]`, default none). Themes choose where to show them.
  Tests: `ProductLabelsTest`. **Config key added:** `catalog.labels` – a client config with its own `catalog` block
  copies it in to use automatic labels.
- **Automatic discounts** (Admin › Discounts › "How customers get it" › Automatic): the basket applies every running
  automatic discount whose rules match – whole shop, chosen categories or products, exclusions, minimum spend, dates,
  usage limits – with no code. Customers see its **label** ("20% off knitwear") in the basket and checkout (no remove
  button); the order records its code like a typed one, so usage counts and reports work. It never combines with a
  code that "can't be combined", and one marked "can't be combined" steps aside for any code. Typing an automatic
  discount's code is refused. The code is generated from the label when left blank (`AUTO-20-OFF-KNITWEAR`).
  Cart totals gain `coupon_labels` and `automatic_coupons`.
- Discount type **Multi-buy** (`multibuy`, "any X for £Y"): eligible items, most expensive first, are grouped into
  sets of `buy_quantity`; each full set costs `amount`, the rest pay their own price. Works as a code too.
- Presenter: `offers(Product)` (automatic discounts covering a product, with the price after a simple % / amount off,
  the condition and the end date / countdown flag) and `bestSaving(Product)` (the biggest % saving, including any
  variant on sale, for "UP TO 40% OFF" badges). Tests: `AutomaticDiscountTest`.
- Config `commerce.shipping.default_carrier` (default `null`): the carrier pre-selected on Ready to send. Replaces the
  hard-coded "DPD". `commerce.shipping.carriers` gains Parcelforce. **Client action:** a shop that relied on DPD
  being pre-filled sets `'default_carrier' => 'DPD'` in its `config/commerce.php`; a shop with its own `shipping` block
  lists its couriers in `carriers` (the drop-down shows those, in that order).
- `php artisan commerce:db:utc [--dry-run] [--connection=]`: re-stores every TIMESTAMP value written in the server's
  clock so it reads the same once the connection runs in UTC. Per table, in a transaction, checksum-verified (rolled
  back on any difference); only the connection's prefixed tables; refuses a second run (marker setting
  `database_timestamps_utc`), SQLite, and an app time zone other than UTC.
- `commerce:doctor` › Database › **Time zone**: fails when the session time zone does not keep the app's times (probed
  in January and July, so it is caught in winter too).
- Missing WordPress image sizes in imported content: `/storage/uploads/…-{W}x{H}.{ext}` that is not on disk 301s to
  the nearest file that is (a square size for a square request, else the smallest variant of the original's shape that
  covers the width, else the original or WordPress's `-scaled` original). Imports download originals and the media
  library makes its own sizes, so `photo-450x206.jpg` in a page, post or category description was a broken image
  (452 of them on one shop). Route `legacy.image`, `LegacyImageController`, feature switch `legacy_content`.

### Client skeleton (`stubs/client-skeleton`)
- `config/database.php`: `'timezone' => env('DB_TIMEZONE')` on `mysql` and `scratch` (null = unchanged behaviour, so
  applying the file can never shift an existing database). `.env.example`: `DB_TIMEZONE=+00:00` for new clients.
- `config/commerce.php`: feature switch `loyalty` (off) in the `features` block; a commented `shipping` example
  (carriers and `default_carrier`).

### Client actions required
- `php artisan migrate --force` (four additive migrations, see Migrations), then `php artisan commerce:publish`,
  `php artisan commerce:theme:publish` (the default theme's `js/app.js`, `js/checkout.js` and `css/app.css` changed) and
  `php artisan optimize`.
- A shop that liked "DPD" pre-filled on Ready to send: `'shipping' => [..., 'default_carrier' => 'DPD']`.
- Optional per shop: `catalog.search = 'precise'`, `features.loyalty = true`, Admin › Settings › Payments › "Debit or
  credit card (via PayPal)", "Google Pay (via PayPal)", "Apple Pay (via PayPal)".
- Child themes that override `checkout/show`, `checkout/pay` or `checkout/partials/payment-methods`: copy the wallet
  parts (see Added) to offer Google Pay / Apple Pay; without them the wallets simply stay hidden.
- Run `php artisan commerce:doctor`. If **Time zone** fails: back up the database, `php artisan down`,
  `php artisan commerce:db:utc --dry-run`, `php artisan commerce:db:utc`, apply the skeleton's `config/database.php`
  (or add `'timezone' => env('DB_TIMEZONE')` to the `mysql` connection), add `DB_TIMEZONE=+00:00` to `.env`,
  `php artisan config:cache`, `php artisan up`. Never set DB_TIMEZONE on an existing database without converting first.

## [1.5.0] - 2026-10-03

Import a WooCommerce shop over its REST API: no database access needed – a shop URL and a read-only API key. One
additive migration.

### Added
- **Admin › Import › WooCommerce API** (sidebar footer, administrators only, feature switch `woo_api_import`): shop
  address, consumer key + secret (encrypted with `Crypt`, never shown again – like the payment secrets), optional
  WordPress application password, authentication mode and TLS switch; **Save & test connection** (store name,
  WooCommerce/WordPress version, currency, items per entity, permission problems per entity); entity checklist
  (categories, attributes, products, customers, coupons, orders, reviews, shipping & tax, pages & posts, media) with
  options (download images, update or leave items imported before, orders from a date, only items changed since,
  order notes, same site as the database import, dry run); runs in the background as a detached
  `commerce:import-woo-api {id}` (no queue / cron, like Admin › Updates) with a live progress page (per-entity
  counters, warnings and errors with the shop's ids, log), cancel, resume from the checkpoint, history and log download.
- `php artisan commerce:import-woo-api [run] --url= --key= --secret= [--only=] [--dry-run] [--since=] [--store] …`
  (keys also from `WOO_API_URL` / `WOO_API_KEY` / `WOO_API_SECRET`); `--test` checks the connection.
- `Pine\Commerce\Import\WooApi`: HTTP client (wc/v3, wp/v2, Store API; `X-WP-TotalPages` pagination at 100 per page,
  polite delay, retries with backoff on 429/5xx honouring `Retry-After`, timeouts, TLS verification on by default,
  `?rest_route=` fallback), authentication by HTTP Basic (HTTPS), query string (hosts that strip `Authorization`) or
  OAuth 1.0a one-legged signatures (plain HTTP, as WooCommerce requires); SSRF guard on every request and image
  download (http/https only, no private/loopback/reserved addresses unless `commerce.woo_api.allow_private_hosts`,
  connection pinned to the checked address); image downloads into the public disk (images only by content, size
  limit, deduplicated by URL and content, core image sizes); SEO from Yoast (`yoast_head_json`) or Rank Math (meta,
  headless `getHead`), else a description excerpt; key-less "public catalogue only" mode via the Store API.
- Imported entities: categories (hierarchy, images, SEO, old URLs → 301), attributes + terms, tags, products (simple,
  variable + variations; grouped/external as simple + warning; primary category from the real permalink so URLs are
  kept), customers + addresses (no passwords) and guests from orders, coupons, orders (line items, shipping, fees,
  coupons, **tax lines → `order_tax_lines`**, refunds via `orders/{id}/refunds`, notes via `orders/{id}/notes`),
  reviews, tax classes/rates, shipping classes/zones/methods, pages and posts (wp/v2), the media library.
- `Pine\Commerce\Import\Mapping` (`ProductRows`, `ProductChildren`, `OrderRows`, `OrderWriter`, `CustomerRows`,
  `CatalogRows`, `ShippingTaxRows`) and `Import\Support\Upserter`: the row mapping and idempotent upserts of the
  database importer, now shared by both importers.
- `WooApiImport` model (table `woo_api_imports`).
- Docs: IMPORTER.md §12 "Importing via the WooCommerce REST API" (creating the key, admin and CLI, what is imported,
  limits compared with the database import, troubleshooting), PLAYBOOK step 6, ADMIN_UI.md, ARCHITECTURE.md §12.9.

### Changed
- Database importer: rows are matched on their WordPress id **within their import source** (`import_source` null),
  so it never touches rows an API import brought in from another shop (id maps, `--fresh` purges, counts).
- Database importer: order `tax` items are now written to `order_tax_lines` (per-rate tax in reports and invoices).
- Database importer: a real run remembers the source site (setting `import.wordpress.site_url`) so a later API
  re-sync of the same site updates those rows instead of duplicating them.

### Fixed
- Tests: the tax report test no longer fails between 23:00 and 00:00 UTC.

### Migrations
- `2026_10_03_000100_add_import_sources_and_woo_api_imports`: nullable indexed `import_source` (varchar 100) on
  `users, categories, attribute_values, products, product_variations, orders, pages, posts, media, tax_rates,
  shipping_classes, shipping_zones`; new table `woo_api_imports`. Additive; existing rows unchanged.

### Config keys added
- `commerce.features.woo_api_import` (`true`).
- `commerce.woo_api`: `allow_private_hosts` (`env WOO_API_ALLOW_PRIVATE_HOSTS`, false), `per_page` (100), `timeout`
  (30), `connect_timeout` (10), `delay_ms` (250), `retries` (4), `max_backoff` (60), `max_image_kb` (10240),
  `image_timeout` (30), `path` (null = `storage/app/private/woo-api-import`), `user_agent`, `credentials.url|key|secret|
  wp_user|wp_password` (`env WOO_API_URL` …, read by the command).

### Client actions required
- None beyond the usual update (`migrate` runs automatically in Admin › Updates). To use the API import: create a
  Read API key on the old shop (IMPORTER.md §12.1) and open Admin › Import.

## [1.4.0] - 2026-10-02

Separate sign-in and registration pages, and a new look for the default theme's account forms. No migrations, no
config changes.

### Added
- **Registration page** `GET /my-account/register/` (route `register.show`, theme view `auth.register`): only when
  registration is enabled (feature `registration` + Settings › "Customers can create an account", else 404); signed-in
  customers are redirected to `/my-account/` (or a safe `?redirect=`). `POST /my-account/register` (route `register`)
  is unchanged.
- Theme contract: `auth.register` (variables `registration: true`, `redirect: string`). A theme without it gets the
  default theme's view. Documented in THEMES.md ("Sign-in and registration pages") and ARCHITECTURE.md §8.
- Default theme: `auth/partials/shell` (centred card + notices, optional aside), `auth/partials/password` (show/hide
  button, strength meter with requirements, confirm-match hint) and `js/auth.js` (vanilla, data-attribute hooks,
  loaded only on these pages; everything works without JavaScript).

### Changed
- `/my-account/` for guests (`auth.login`) is a **sign-in page only** with a “Create an account” link to the register
  page (when registration is enabled). The URL, route names and form fields are unchanged.
- Every registration error (validation, email already registered, rate limit) now returns to the **register page**
  with old input (was `/my-account/`); a safe `redirect` (e.g. back to the checkout) is kept. Sign-in validation errors
  always return to `/my-account/` (or the checkout) instead of the previous URL.
- Default theme: new sign-in, register, lost-password and reset-password design (centred card, clear labels, inline
  errors with `aria-invalid`/`aria-describedby`, show/hide password, strength hint, success state).

### Fixed
- Default theme: the footer newsletter field no longer shows the email typed into another form after a validation
  error (it only refills from its own error bag).

### Client actions required
- Themes that override `auth/login.blade.php` should drop its register form, link to
  `route('register.show', ['redirect' => $redirect])` instead, and may add `auth/register.blade.php`. Without one the
  default theme's register view is used. Run `php artisan commerce:theme:publish` (new `js/auth.js`) and
  `php artisan commerce:theme:check`.

## [1.3.2] - 2026-10-02

### Fixed
- Default theme: Admin › Settings › Theme colours, fonts and corner radius now show on the storefront and checkout.
  The inline `--c-*` variables were printed before `css/app.css`, whose own `:root` defaults then won the cascade.
  Order is now: `app.css` defaults → theme settings → child theme `theme.css`. Regression test added.
- `commerce:theme:make`: the generated `theme.css` example uses the real variable names (`--c-primary`, not
  `--color-primary`).

### Added
- `SECURITY.md`: report vulnerabilities privately via GitHub's "Report a vulnerability".

### Client actions required
- None. Child themes that worked around the bug by setting `--c-*` in `theme.css` keep working (theme.css still loads last).

## [1.3.1] - 2026-10-02

A patch release from the first roll-out of Admin › Updates. No migrations, no config changes.

### Fixed
- Database backup: `mariadb-dump` is preferred over `mysqldump` when both exist (MariaDB 11 prints a "Deprecated
  program name" warning for `mysqldump`, which ended up in the update log).
- `.commerce-skeleton.json`: an empty hash list is written as `{}` (was `[]`).
- Update check: says why no skeleton release was found (`commerce:update:check` and the stored check result) instead
  of an empty value.

### Client actions required
- None. This is the first release that 1.3.0 clients can install from Admin › Updates.

## [1.3.0] - 2026-10-02

A minor release: **Admin › Updates** – the shop finds new platform releases by itself and an administrator installs
one with a click, after approving it with their password. Additive: one new table, one new feature switch (on), one
new scheduled task. Storefront output does not change.

### Added
- **Admin › Updates** (administrators only, sidebar footer; feature switch `updater`, default on):
  - **Update check** – the tags of the repository `composer.json` installs pine/commerce from (GitHub: public API,
    `git ls-remote --tags` as the fallback; other hosts: `git ls-remote`). Offers the newest stable release above the
    installed one **within the project's composer constraint**; newer majors (or releases outside the constraint) are
    listed as "requires a developer". The CHANGELOG.md sections in between are fetched from the new tag and shown,
    with **Client actions required** highlighted. "Check now" button, a daily scheduled check (core task
    `updates.check`, 06:15) – never an automatic install. Dashboard notice and a sidebar badge while an update is
    available.
  - **Approve & install** – password re-entry + confirmation; the run starts in the background
    (`php artisan commerce:update:run {id}` detached with `setsid`/`nohup` – no queue worker needed) and the page
    follows its log through a status endpoint. One run at a time (file lock). Steps: pre-flight (PHP CLI + composer
    found, HOME/COMPOSER_HOME set for web-started processes, disk space, writable directories, git tree warning,
    `commerce:doctor` baseline), database backup (`mysqldump` + gzip or a SQLite copy, outside `public/`, newest 5
    kept), composer.json/composer.lock saved, maintenance mode with a bypass secret for the approver,
    `composer update pine/commerce --with-dependencies` pinned to the approved version (`preferred-install` respected),
    `migrate --force`, `commerce:publish`, `commerce:theme:publish`, `optimize:clear` + `optimize`,
    `commerce:doctor --json` (new failures fail the update), `up`. **On any failure** composer.lock is restored,
    `composer install` brings the previous code back, assets are published again, caches rebuilt and the site comes
    back up; the database backup is kept and the log says how to restore it (never restored automatically).
  - **Skeleton file updates** – `.commerce-skeleton.json` records the skeleton release a project was created from
    (written by `commerce:new-client`, so also present in ecom-skeleton) with a hash of every file. The updater
    compares the baseline with the newest skeleton release for the installed core (shallow git checkouts) and
    classifies each changed file: safe (new, unchanged here, removed upstream) or needs a developer (changed here,
    deleted here …, with line counts and the upstream diff); `.env`, `composer.lock`, `themes/`, README/LICENSE are never
    touched. The administrator ticks the safe files (or all of them); overwritten files are backed up and the
    baseline follows.
  - **History** – every check, approval, update and skeleton apply with who, versions, files, status, timestamps
    and the full log (table `platform_updates`).
- Commands: `commerce:update:check [--json]`, `commerce:update:run [id] [--approve --yes]` (the CLI approves only with
  both flags; without an approved id it refuses), `commerce:skeleton:baseline [ref] [--detect] [--disabled] [--note=]`,
  `commerce:skeleton:check [--apply-safe --yes]`.
- `Pine\Commerce\Updater\*` (UpdateChecker, UpdateManager, UpdateRunner, ReleaseSource, Versions, Changelog,
  ComposerProject, DatabaseBackup, UpdateLock, Environment, Skeleton\*), the `ProcessRunner` interface
  (`SystemProcessRunner`; tests use a fake), model `PlatformUpdate`.
- `bin/export-client-skeleton.sh --tag`: tags the skeleton repository with the core version (pushed with `--push`).
- Dependency `composer/semver` ^3.4 (version constraints).

### Config keys added
- `features.updater` (true).
- `scheduler.tasks.updates.check` (true).
- `updater` block: `repository` (env `COMMERCE_UPDATER_REPOSITORY`, null = from composer.json), `default_repository`,
  `skeleton_repository` (`https://github.com/SetWebUK/ecom-skeleton.git`), `github_token`
  (env `COMMERCE_UPDATER_GITHUB_TOKEN`), `check` (true), `php_binary` (env `COMMERCE_PHP_BINARY`), `composer_binary`
  (env `COMMERCE_COMPOSER_BINARY`), `mysqldump_binary`, `git_binary`, `home` (env `COMMERCE_UPDATER_HOME`),
  `composer_home` (env `COMMERCE_COMPOSER_HOME`), `path`, `backup` (true), `backup_path`, `keep_backups` (5),
  `min_free_mb` (1024), `timeout` (900), `http_timeout` (10), `git_timeout` (120), `project_path`.
  Documented in `docs/EXTENDING.md` "Updates".

### Theme contract
- No changes.

### Migrations
- `2026_10_02_000100_create_platform_updates_table` – new table `platform_updates` (additive).

### Client skeleton (`stubs/client-skeleton`)
- New projects get `.commerce-skeleton.json` from `commerce:new-client`. The skeleton repository is tagged with the
  core version from this release on (`v1.3.0`).

### Client actions required
- After `composer update pine/commerce`: `php artisan migrate --force` (one new table), `commerce:publish` (admin
  CSS), `optimize`. A project on 1.2.x has no updater yet, so **this** update is done the usual way (PLAYBOOK 3.3);
  later releases can be installed from Admin › Updates.
- Optional: record the skeleton baseline once – `php artisan commerce:skeleton:baseline v1.3.0` (`--detect` to
  compare; `--disabled --note="…"` for a project that was not created from the skeleton).
- Optional: a project whose `config/commerce.php` overrides the whole `scheduler` block keeps working (the new task
  defaults to on); add `'updates.check' => false` to its `tasks` to switch the daily check off.
- Web servers that cannot start background processes (`proc_open` disabled): the approved update waits and the page
  shows the command to run on the server (`php artisan commerce:update:run {id}`).

## [1.2.1] - 2026-10-01

1.2.1 - public release: client/infrastructure references removed, MIT licence; no code behaviour changes.

The package repository (`SetWebUK/ecom-core`) is public from this release and starts with a clean history: one
commit, tag `v1.2.1`. Earlier tags (1.0.0 – 1.2.0) were published from the previous, private history and are not in
it; clients should require `^1.2`.

### Changed
- Licence: MIT (copyright SetWeb UK), `composer.json` `"license": "MIT"`. Previously proprietary.
- Docs, tests, comments and stubs name no client and no server: client-specific examples, migration records and
  verification records moved to the client projects they belong to; examples use `acme` / `example.test`.
  `docs/ARCHITECTURE.md` is the generic contract (the original migration plan, §14, is replaced by the generic
  regression check; §3 shows a client project layout).
- `docs/PLAYBOOK.md` Part 1 and Part 3, `docs/UPGRADING.md`, `README.md`: the package is developed directly in its
  own repository – no subtree split; a release is a tag in `ecom-core`; clients run `composer update pine/commerce`;
  local integration testing uses a temporary, uncommitted path repository.
- `tests/Feature/NeutralInstallRenderTest.php`: the strings a neutral install must never render are configurable
  (`COMMERCE_NEUTRALITY_BANNED`, comma-separated, and `COMMERCE_NEUTRALITY_BANNED_REGEX`; empty by default). The
  package-source grep moved to the client project that owns the banned strings.
- Neutral example data in an admin placeholder, a code comment and importer test fixtures.

### Added
- `bin/export-client-skeleton.sh` (export-ignored): renders `stubs/client-skeleton` into the base-system repository
  (`SetWebUK/ecom-skeleton`) from a clone of this repository, booting the package on Orchestra Testbench. The skeleton
  requires `^MAJOR.MINOR` of the current version from `https://github.com/SetWebUK/ecom-core.git` and carries the MIT
  `LICENSE`.

### Client skeleton (`stubs/client-skeleton`)
- `composer.json`: `"license": "MIT"`; `"preferred-install": "dist"` – the source-install rule for `pine/commerce`
  (1.0.1) is only needed for a private repository reached over SSH and is now documented instead (README, PLAYBOOK
  1.6). Existing clients keep whatever they have.

### Migrations
- None.

### Client actions required
- None. Optional: switch the VCS repository URL to `https://github.com/SetWebUK/ecom-core.git` (no credentials
  needed) and require `^1.2`.

## [1.2.0] - 2026-10-01

A minor release that closes four gaps listed under *Known limitations* in 1.1.0. Everything is additive; there are
no migrations. Storefront output does not change.

### Added
- **Client scheduled jobs** – `Commerce::scheduledTask($key, $options)` ([docs/EXTENDING.md](docs/EXTENDING.md)
  "Scheduled tasks"). The schedule is a cron expression or a Laravel frequency method (`'hourly'`, `'dailyAt:02:30'`,
  `'weekdays|dailyAt:09:00'`). The task runs a callable, an artisan command or a `Scheduling\Task` class, and can be
  guarded by a `feature`, `setting` or `skip` callback. Client jobs run like the core ones: Laravel's scheduler, the
  web fallback without cron, and `commerce:schedule:task {key}`. Their last result is recorded, and they are listed by
  `commerce:schedule:status` (marked `(client)`, `"source"` in `--json`) and in Settings › Scheduled tasks (badge
  "Custom"). `commerce.scheduler.tasks.{key}` switches one off. New: `Scheduling\ClientTask`,
  `Scheduler::tasks()` / `has()` / `task()`, `ExtensionRegistry::scheduledTasks()`. The skeleton's
  `ClientServiceProvider` and `ExtensionExamples` show the API.
- **Invoice PDF on the admin "Order details / invoice" email** – `Mail\Admin\CustomerInvoice` attaches the PDF when
  the order has an invoice under the existing numbering rules: an issued number, or a qualifying paid order (its
  number is issued when the email is sent); with numbering off, a paid order. An unpaid order gets no PDF and no
  number. The send modal on the order page, the success message and the order note say whether the PDF was
  attached. New setting Settings › Invoices › "Attach the invoice PDF to the Order details / invoice email"
  (`invoices.attach_customer_invoice`), `Invoices::emailAttachment()`, `CustomerInvoice::invoiceAttachment()` /
  `attachmentNote()`.
- **Product CSV: per-variant tax class and shipping class.** Variant rows export their own `tax_class` (`parent` =
  same as the product, as in WooCommerce) and `shipping_class` (empty = same as the product). The import reads both on
  variation rows, including WooCommerce's `Tax class` / `Shipping class` columns and the `variant_*` / `variation_*`
  aliases. `standard` is kept as an override, and a missing shipping class is created only with "create missing".
- **Importer: shipping zones by continent** – every WooCommerce continent (AF, AN, AS, EU, NA, OC, SA) now expands to
  the countries WooCommerce assigns to it, using a static copy of WooCommerce's continents list
  (`Import\Support\WooCommerceContinents`). Unknown codes still warn.

### Fixed
- Importer: zones defined by a continent other than Europe were skipped with a warning.
- Importer: the Europe list now follows WooCommerce exactly, so `ShippingStep::EUROPE` includes Turkey and leaves
  out Cyprus, which WooCommerce lists under Asia.

### Config keys added (package defaults = new-client defaults)
- `invoices.attach.customer_invoice` true. If a client `invoices.attach` block does not have this key, the
  attachment is off.

### Migrations
- None.

### Client actions required
1. `composer update pine/commerce`, then `php artisan commerce:publish`, `php artisan commerce:theme:publish` and
   `php artisan optimize:clear && php artisan optimize`.
2. If your `config/commerce.php` has its own `invoices.attach` block, add `'customer_invoice' => true` or `false`.
   A client that sets `false` keeps its 1.1 behaviour until the owner switches the attachment on in
   Settings › Invoices.
3. Optional: move client jobs from `routes/console.php` to `Commerce::scheduledTask()` so they get the web fallback
   and show up in the status table.
4. Scripts that read the product CSV export should expect `tax_class` / `shipping_class` values on variant rows.

### Known limitations (still open)
- Grouped and external WooCommerce products are rejected per row. Images: `max_dimension` replaces the original in
  place. The sale-price job covers simple products only. Packing slips have no thumbnails.
- Client scheduled tasks use the store timezone and cannot use `between()`, sub-minute frequencies or
  `onOneServer()`. Use `Schedule::` in `routes/console.php` for those.

## [1.1.0] - 2026-09-30

A minor release: everything is additive. Six areas – VAT done properly, shipping zones, image resizing, full product
CSV import/export, scheduled jobs with abandoned-cart reminder emails, and PDF invoices – plus 16 bug fixes. Every
new behaviour has a config key or owner setting; a client that copies the "keep today's behaviour" blocks listed
under *Client actions required* renders exactly as on 1.0.x. The package defaults switch the new features **on** for
new clients (marked *new-client default* below).

### Added

**Tax (VAT)** – [docs/TAX-AND-SHIPPING.md](docs/TAX-AND-SHIPPING.md)
- Tax classes and rates, Admin › Settings › Tax (options, classes, per-class rate table, CSV import/export in
  WooCommerce's format).
- Prices entered with or without VAT and shown with or without VAT (shop and basket separately); rounding per line or
  per order; compound rates and priorities; rates by country, county, postcode pattern or city; re-pricing for
  customers whose rate differs from the shop's (`adjust_non_base_prices`); shipping tax class ("same as the items" or
  a fixed class); tax status per delivery option; price suffix.
- Tax per order line and per rate (new table `order_tax_lines`); the breakdown is shown on receipts, emails, the admin
  order page, printed and PDF invoices, and reports (new "Tax by rate" card + CSV).
- Products: tax status, tax class, shipping class; variations: tax class.
- Importer: WooCommerce tax settings, classes and rates (new step `extras.tax`).

**Shipping zones**
- Zones matched on country/region/postcode (patterns shared with tax rates: exact, UK outcodes, `BT*`, `HS1-HS9`,
  `10000...19999`, `!` to exclude), drag-to-reorder list, zone and method editors, shipping classes, countries you
  sell to (Admin › Settings › Shipping).
- Delivery option types: flat rate (per order / item / shipping class, formulas like `5 + 1.50 * [qty]`), free
  shipping (minimum amount and/or coupon), weight bands, price bands, local pickup.
- Checkout re-prices delivery and tax from the address as it is typed (POST `/checkout/update`); "we don't deliver
  to …" message when nothing applies.
- `commerce:install` seeds a UK zone with free delivery and UK VAT rates 20 % / 5 % / 0 % (*new-client default*).
- Importer: WooCommerce zones, methods and shipping classes (`extras.shipping`).

**Image resizing** – [docs/THEMES.md](docs/THEMES.md) "Images"
- Configurable sizes (`commerce.images`) generated on upload, WordPress-style names `file-WxH.ext` plus WebP twins;
  Imagick or GD; EXIF orientation fix, `max_dimension` cap, metadata stripped (colour profile kept), never upscales,
  animated GIFs left alone, originals never overwritten.
- `media_url($path, $size)` (unchanged without `$size`), `image_srcset()`, `Media::sizeUrl()/srcset()/variants()`,
  Blade component `<x-media-image>` (`<picture>` + WebP, `srcset`/`sizes`, width/height, lazy loading).
- `commerce:images:generate {--missing} {--size=*} {--path=} {--scan} {--no-webp} {--dry-run} {--chunk=100} {--limit=}`.
- `commerce:doctor` check "Image sizes".

**Product CSV import/export** – [docs/PRODUCT-CSV.md](docs/PRODUCT-CSV.md)
- Admin › Products › Import / Export: export all or the filtered list (variants as their own rows, UTF-8 BOM,
  formula-injection guard); import with header auto-mapping (own format and WooCommerce product exports), dry run,
  batched resumable runs driven by the page (no queue worker), per-row CSV report, image download into the media
  library with de-duplication (public hosts only).
- Options: update existing (match by SKU or ID), create missing categories/attributes, download images, keep or clear
  empty cells.
- Commands `commerce:products:export` and `commerce:products:import {file}`.
- Feature switch `product_csv` (default on).

**Scheduled jobs and abandoned-cart reminders**
- `Pine\Commerce\Scheduling\Scheduler` registers six jobs with Laravel's scheduler: `orders.cancel-unpaid` (5 min),
  `carts.abandoned-emails` (10 min), `stock.back-in-stock` (hourly), `catalog.sale-prices` (5 min),
  `maintenance.prune` (03:40), `inventory.low-stock-email` (07:00, owner setting, off).
- Cron heartbeat (`scheduler.last_run` setting) and last result per job (`scheduler.task.{key}`); web fallback for
  sites without cron (*new-client default*: on); `commerce:schedule:status [--json]`, `commerce:schedule:task {task}`,
  `commerce:doctor` cron check, Admin › Settings › Scheduled tasks.
- Abandoned-cart reminder emails (Admin › Settings › Abandoned carts, **off** until the owner switches them on):
  marketing-consent or everyone, up to three steps (1 h / 24 h / 72 h, third off) with subject, intro and optional
  single-use, email-bound discount code; signed "Return to my basket" link that restores the basket and opens the
  checkout; unsubscribe page + one-click unsubscribe headers; no tracking pixels.
- Abandoned checkouts list: Reminders column, reminders sent / recovered baskets / recovered revenue; basket detail
  page with timeline and "Stop reminders"; dashboard card.
- Routes `cart.recover` (`GET basket/restore/{cart}`), `cart.recover.unsubscribe` (`GET`/`POST basket/unsubscribe/{cart}`),
  `admin.carts.show`, `admin.carts.stop`; `Cart::restore()`; listener `MarkRecoveredCart`; mailables
  `AbandonedCartReminder`, `LowStockReport`.

**PDF invoices** – [docs/INVOICES.md](docs/INVOICES.md)
- PDF invoices and packing slips (`dompdf/dompdf` ^3.1, DejaVu Sans embedded; remote files, PHP and JavaScript off in
  the renderer). Templates `pdf.invoice` / `pdf.packing-slip`, overridable by the client or theme.
- Sequential invoice numbers separate from order numbers (`orders.invoice_number` unique, `orders.invoice_date`,
  `sequences` table): prefix/suffix/padding with `{Y}`/`{y}`/`{m}`, forward-only next number, issued on paid or
  completed by the `AssignInvoiceNumber` listener (runs before the order emails); race-safe, never reused.
- Admin › Settings › Invoices; per-order Invoice PDF / Packing slip PDF / Regenerate; bulk merged PDF or ZIP for up to
  200 orders (`admin.pdf`); Download PDF on the HTML print pages.
- Invoice PDF attached to the "order received" (processing) and "order completed" emails (each switchable).
- Customer downloads: `account.order.invoice` (signed-in owner) and `checkout.invoice` (guest, order key; follows
  `OrderAccess`).
- `OrderEmail::invoiceEmailKey()`.

**Other**
- 410 Gone redirect rules (admin form and CSV import); theme view `errors.410` (falls back to `errors.404`).
- Order-received billing-email check (theme view `checkout.verify-email`, route `checkout.thankyou.verify`),
  `Services\Checkout\OrderAccess`.
- Menu `{store.*}` tokens in labels and links.
- `Support\Sql` (`col` / `table` / `qualify`) for table-prefix-safe raw SQL.
- `Import\Contracts\BreadcrumbTermProvider` (Rank Math primary term); `products.breadcrumb_category_id`.
- Theme config `seo.description_limit` (0 = no limit).
- Settings screens: read-only panels; settings groups tied to a feature return 404 while it is off.
- Docs: `TAX-AND-SHIPPING.md`, `PRODUCT-CSV.md`, `INVOICES.md`; cron, tax/shipping, invoices, abandoned carts,
  product CSV and image steps in `PLAYBOOK.md`; 1.0 → 1.1 in `UPGRADING.md`.

### Changed
- Checkout and admin orders use tax rates and zones; shop prices follow the display setting. Order lines are stored
  without tax (like WooCommerce) – unchanged for stores without VAT.
- The shipping calculator extension (`Commerce::shippingCalculator()`) also receives `postcode` and `zone`.
- Settings › Checkout: the "VAT rate" field is replaced by Settings › Tax; "Invoice footer" moved to Settings ›
  Invoices (same key `documents.invoice_footer`).
- Postcode validation outside the UK.
- Admin uploads are rotated, capped and get every configured size plus WebP twins; upload names avoid clashes with
  generated files (`-WxH` in a name becomes `-W-H`); deleting media removes its variants
  (`ImageGenerator::deleteVariants()`); `<x-admin.thumb>` uses a sized image. The protected
  `MediaUploadController::makeThumbnail()` was replaced by `storeImage()` / `fileDetails()`.
- Default theme: responsive images everywhere (product cards, gallery, quick view, home, blog); product-card hover
  CSS uses `.has-alt`; `picture { display: contents }`; the variation gallery swap removes `<source>`/`srcset`/`sizes`.
- `CheckoutService::cancelStaleOrders()` uses the shared `CancelUnpaidOrders` job and skips itself while cron runs.
- The HTML print invoice uses the invoice number and date when numbering is on.
- A registered customer's order-pay page requires that customer's login; the guest invoice PDF follows `OrderAccess`.
- Category/shop page numbers past the end 301 to the last page (search stays 404).
- `money()` puts the minus sign first (`-£5.00`).
- Meta descriptions are no longer truncated at 300 characters (cap with `seo.description_limit`).
- Imported variations are numbered 0..n per product; an admin save keeps the stored order; `Product::variations()`
  breaks ties by id.
- Products list: "Import / Export" button and menu entry (while `product_csv` is on).

### Fixed
- Signed URLs never validated on sites with trailing slashes (`SlashUrlGenerator::hasCorrectSignature()`).
- Item refunds include the item's tax.
- Review replies are shown under the review.
- Draft/private product URLs return 404 for guests (staff see a preview banner) instead of redirecting.
- Removed coupons say why.
- `previous()` / `back()` keep the trailing slash (no extra 301).
- Deleting a user deletes their password-reset token.
- Marking a bank-transfer order paid confirms its pending payment row.
- The back office works on a table-prefixed database connection.
- Pre-release onboarding rehearsal fixes: the default theme's checkout summary no longer ends "Includes £50.00 VAT 20%,"
  when the basket also holds zero-rated goods; invoice PDFs show the net amount per VAT rate (and a 0% line for
  zero-rated goods) for orders placed with v1.1 tax rates; on inclusive-price orders delivered outside the shop's
  country the invoice's unit price is the price charged (VAT taken off), not the UK catalogue price.
- Docs: `php -S` with Laravel's router must run from `public/` (PLAYBOOK); the skeleton's `.env.example` says the log
  mailer needs `LOG_LEVEL=debug`; PLAYBOOK part 2 describes rehearsing the v1.1 features.

### Config keys added (package defaults = new-client defaults)
- `tax.*`: `enabled` true, `prices_include_tax` true, `display_shop` `incl`, `display_cart` `incl`, `rounding`
  `line`, `based_on` `shipping`, `shipping_taxable` true, `shipping_tax_class` `inherit`,
  `shipping_prices_include_tax` `''`, `adjust_non_base_prices` true, `price_suffix` `''`, `label` `VAT`,
  `install_rates` `uk`. Admin › Settings › Tax overrides each.
- `shipping.install_zones` true.
- `images.*`: `generate_on_upload` true, `driver` `auto`, `sizes` (thumbnail 150×150 crop, card 400, medium 800,
  large 1600), `webp` true, `picture_webp` true, `quality` (jpg 82, webp 80, avif 60, png 8), `auto_orient` true,
  `max_dimension` 2560, `strip_metadata` true. A missing sub-key falls back to the package default.
- `product_csv.*`: `chunk_size` 25 (cap 200), `time_budget` 20, `max_rows` 10000, `max_file_kb` 20480,
  `max_image_kb` 10240, `image_timeout` 15, `url_guard` true, `keep_days` 14, `disk` `local`.
- `features.product_csv` true.
- `scheduler.*`: `enabled` true, `tasks` (every job true), `web_fallback` true, `heartbeat_minutes` 5.
- `invoices.*`: `numbering` true, `prefix` `INV-`, `suffix` `''`, `padding` 5, `start` 1, `assign_on` `paid`,
  `attach.customer_processing` true, `attach.customer_completed` true, `customer_download` true, `paper` `a4`,
  `cache` false. Settings › Invoices overrides each.
- `commerce-import.shipping.zones` true.
- Owner settings (Admin, stored in the database): `abandoned_carts.emails_enabled` (off), `.consent` (marketing),
  `.max_age_days` (7), `.min_value` (0), `.step{1-3}.*`; `scheduler.low_stock_email` (off),
  `scheduler.cart_retention_days` (90, 0 = keep all).

### Theme contract changes (all optional; themes that ignore them keep working)
- New views: `checkout/verify-email` (**required** for themes not based on `default`), `errors/410` (falls back to
  `errors/404`), `cart/unsubscribe`, `pdf/invoice`, `pdf/packing-slip` (core fallbacks).
- `Cart::totals()` keeps its keys (amounts without tax) and adds `*_display`, `tax_lines`, `display_incl`; post the
  address fields to `/checkout/update` so zones and tax follow the customer.
- Show `$review->reply`, a banner when `$product->status !== 'published'`, and `session('account_notice')` on the
  login page.
- Images: use `<x-media-image>` or `media_url($path, $size)` + `image_srcset()`; JS that swaps gallery images must
  remove `<source>` elements and `srcset`/`sizes`.
- Invoice links: the default theme links the PDF from View order and the thank-you page when customer download is on.
- `seo.description_limit` theme config key.

### Migrations (all additive; existing rows stay valid)
- `2026_09_30_001000_add_breadcrumb_category_to_products` – nullable `products.breadcrumb_category_id`.
- `2026_09_30_120000_add_abandoned_cart_recovery` – nullable `carts.recovery_stopped_at`, `recovery_stop_reason`,
  `recovered_order_id`, `recovered_at`; tables `cart_recovery_emails`, `email_unsubscribes`.
- `2026_09_30_120000_add_tax_rates_and_shipping_zones` – tables `tax_classes`, `tax_rates`, `shipping_classes`,
  `shipping_zones`, `order_tax_lines`; new columns on `shipping_methods`, `products`, `product_variations`, `carts`,
  `orders`, `order_items`. Data: an old `tax.rate` setting becomes one equivalent rate row (prices exclude tax);
  existing delivery options move into one zone with unchanged totals.
- `2026_09_30_130100_add_source_hash_to_media` – nullable, indexed `media.source_hash`.
- `2026_09_30_150500_add_invoice_numbers_to_orders` – nullable unique `orders.invoice_number`, `orders.invoice_date`;
  table `sequences`.

### Client actions required
1. `composer update pine/commerce` (pulls in `dompdf/dompdf` ^3.1; `ext-zip` optional – without it bulk ZIP falls
   back to one merged PDF), then `php artisan migrate --force`, `php artisan commerce:publish`,
   `php artisan commerce:theme:publish`, `php artisan optimize:clear && php artisan optimize`.
2. `php artisan commerce:images:generate --missing` (add `--scan` for files without a media-library row) so existing
   media gets the new sizes. A client with its own `images` block keeps its sizes.
3. Add the cron line (recommended):
   `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1` – check with
   `php artisan commerce:schedule:status`. Without cron, the web fallback (package default on) runs due jobs after
   storefront requests; a client config with its own `scheduler` block decides that itself.
4. **To keep 1.0 behaviour exactly**, add to `config/commerce.php` (a like-for-like client does all of these):
   a `tax` block with `prices_include_tax` false, `display_shop`/`display_cart` `excl` and `install_rates` `none`;
   `shipping.install_zones` false; `invoices` with
   `numbering` false, both `attach.*` false and `customer_download` false; an `images` block with only the 150×150
   thumbnail, `webp`/`picture_webp`/`auto_orient` false and `max_dimension` null; `scheduler.web_fallback` false.
   Without them the new-client defaults apply.
5. A client that overrides the top-level `shipping` key adds `install_zones`; set
   `commerce-import.shipping.zones` false to keep the pre-1.1 flat shipping import.
6. Custom themes not based on `default`: add `checkout/verify-email`; drop any hard 300-character meta description
   cut (or set `seo.description_limit`); optionally adopt the theme contract additions above
   (`php artisan commerce:theme:check`).
7. Client code with raw SQL names tables through `Pine\Commerce\Support\Sql`.
8. Owners: review Admin › Settings › Tax, Shipping, Invoices, Scheduled tasks and Abandoned carts (reminders stay off
   until switched on; check the consent choice against the privacy policy).

### Known limitations
- WooCommerce zones by continent are imported for Europe only; variant-level shipping/tax class is not in the product
  CSV; grouped/external WooCommerce products are rejected per row.
- Images: `max_dimension` replaces the original in place (no `-scaled` copy).
- Clients cannot register their own scheduled jobs yet; the sale-price job covers simple products only.
- The admin "Order details / invoice" email does not attach the PDF; packing slips have no thumbnails.

## [1.0.1] - 2026-09-30

### Fixed
- Client skeleton: `pine/commerce` is installed from **source** (git clone over SSH) instead of a GitHub API zip
  (`config.preferred-install`: `{"pine/commerce": "source", "*": "dist"}`), so a server with only an SSH
  deploy key can install the private package. Previously `composer install` failed with a 404 on
  `api.github.com/…/zipball` unless a GitHub token was configured.

### Client actions required
- Existing clients using an SSH key only: add the same `preferred-install` block to `composer.json` `config`.

## [1.0.0 – also included in the v1.0.0 tag]

### Fixed
- SQLite projects (development, CI, import rehearsals): the storefront search suggestions (`CHAR_LENGTH`) and the
  admin dashboard/reports (`DATE_FORMAT`) no longer fail. `Support\SqliteFunctions` registers the MySQL functions the
  package's SQL uses on every SQLite connection (`CONCAT`/`CONCAT_WS` too below SQLite 3.44); MySQL is unchanged.
- Default theme: JSON-LD (`seo`, `breadcrumbs`, home/FAQ) printed compiled PHP instead of `"@context"` – Blade
  compiled it as Laravel's `@context` directive. Themes that write `'@context'` inside `{!! … !!}` must escape it as
  `'@@context'` (or build the array in `@php`).
- Importer: the "redirect shadows a live URL" warning describes the resolver correctly (the redirect wins).
- Feature switches applied everywhere they are documented to apply: `legacy_content` off now also skips the
  WPBakery/Impreza shortcode clean-up, the Font Awesome → SVG replacement and the Elementor wrapper (not only the
  per-page Elementor CSS); `product_condition` / `product_brand` off now also drop the condition / brand group from
  the shop filters (`Facets::filters()`), ignore `?condition=` / `?brand=` in Admin › Products and leave the
  Condition / Brand columns out of the product CSV export. Nothing changes while the switches are on.

### Changed
- Client skeleton: `config/commerce-import.php` lists `legacy_hosts` (the importer's list of old host names); the
  `config/commerce.php` comment says what its own `legacy_hosts` does (menus at render time).
- Docs: PLAYBOOK rehearsal on SQLite without GitHub (Part 2), local bare repositories (§1.8), `legacy_hosts` before
  the first import, wp-config precedence, daily log file names, new troubleshooting rows.

**Client actions required:** none. New clients: fill both `legacy_hosts` lists before the first import.

## [1.0.0] - 2026-09-28

First release as a standalone package. Extracted from the first client project (a WooCommerce store rebuilt
like-for-like), with every client-specific value moved out to client config, theme and code.

### Storefront
- Catalogue: shop, category, brand and attribute archives with AJAX filtering (facets, price range, sort, pagination),
  search, product pages (simple + variable products, galleries, specifications, related products), quick view.
- Basket and checkout: guest or account checkout, coupons, delivery methods (flat rate, free over a threshold,
  per-method rules, multiple options), terms acceptance, order-received page, session-expiry (419) recovery that
  keeps what the customer typed.
- Payments: Stripe (webhooks), PayPal (REST, webhooks) and bank transfer (BACS); keys stored encrypted in the
  database (Admin › Settings › Payments); more gateways through `Commerce::gateway()`.
- Customer accounts: register/login (imports WordPress password hashes as-is), orders, addresses, wishlist,
  password reset; order tracking; reviews; back-in-stock alerts; newsletter sign-up; contact form.
- Content: pages with a block-based page builder, blog (posts, categories, RSS), menus, shortcodes, legacy WordPress
  content support (Elementor/WPBakery markup and CSS), redirects, `/sitemap.xml`, custom `robots.txt`,
  Google Shopping feed, structured data (Product, Offer, Review, BreadcrumbList), SEO titles/descriptions/canonicals.
- Transactional emails (WooCommerce-equivalent set: new/processing/on-hold/completed/refunded/cancelled/failed order,
  customer note, new account, password reset, back-in-stock, contact form).

### Back office (`/admin`, hand-built Blade + Alpine.js, no admin framework)
- Dashboard with widgets, orders (statuses, notes, refunds, printable invoices/packing slips, CSV export),
  abandoned checkouts, products (variations, images, specifications, inventory, CSV), categories, attributes,
  discounts, reviews, stock alerts, customers, pages (block builder), blog, menus, redirects (CSV import/export),
  inbox (form submissions, newsletter), analytics/reports, settings (store, checkout, delivery, payments, emails,
  SEO & tracking, theme, system), staff roles (administrator / manager).
- Settings › System: platform version, active theme, feature switches, `commerce:doctor` checks, last import report.

### Themes
- Theme system: `themes/{slug}/theme.json`, parent chains ending in the package `default` theme, view resolution
  through the chain, theme config files, `ThemeDefinition` hooks (body classes, view composers, boot), assets
  published as real copies (`public/themes/{slug}`), staff theme preview (`?preview_theme=`), runtime theme switch.
- Neutral, complete `default` theme implementing the full theme contract (`docs/ARCHITECTURE.md` §8).

### WordPress / WooCommerce importer
- `commerce:import-wordpress`: one-way, idempotent, read-only on the source; site detection (`--detect`), dry runs,
  sections (settings, media, users, catalog, orders, extras, content, menus, redirects), HPOS and legacy order
  storage, permalink reproduction (old URLs keep working), media copy (`--copy-uploads`), rendered-site crawling for
  page-builder content (`--site-url`, `--snapshots`), remote database options (`--db-*`) or `--wp-path` (parses
  `wp-config.php`), rehearsal on the `zz_`-prefixed `scratch` connection.
- Built-in adapters: WooCommerce, Yoast SEO, Rank Math, Redirection, Elementor, WPBakery clean-up, ACF, YITH/TI
  wishlists, product tags, product brands, back-in-stock notifier, Contact Form 7 database, cost of goods, sequential
  order numbers, permalink plugins, Google Tag Manager, rendered theme content; client adapters/steps through `Commerce::importAdapter()` /
  `Commerce::importStep()`.

### Platform
- Feature switches (`config('commerce.features.*')`, 23 switches) that gate routes (404 with names kept), admin
  pages and menu entries, theme entry points, sitemap, emails and importer steps.
- Extension API (`Pine\Commerce\Commerce`): payment gateways, shipping calculators, admin routes/menu/settings
  screens/dashboard widgets, page templates, shortcodes, menu locations, product presenter methods, facet sorter,
  order status hooks and replacement emails, importer adapters and steps.
- Tooling: `commerce:install`, `commerce:doctor`, `commerce:publish`, `commerce:theme:make|publish|check|cache|clear`,
  `commerce:verify-urls`, `commerce:new-client` (path or VCS repository), `commerce:scratch:drop`.
- Client skeleton (`stubs/client-skeleton`): a minimal Laravel 13 app wired to the package.
- Shared-hosting friendly: no Node build, `sync` queue, no cron required, no symlinks under `public/` (LiteSpeed).
- Standalone test suite (`composer test`, Orchestra Testbench on in-memory SQLite).

### Requirements
- PHP 8.3+, Laravel 13, MySQL 8 / MariaDB 10.6+ (SQLite for tests), `stripe/stripe-php` ^21.

[Unreleased]: https://github.com/SetWebUK/ecom-core/compare/v1.5.0...HEAD
[1.5.0]: https://github.com/SetWebUK/ecom-core/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/SetWebUK/ecom-core/compare/v1.3.2...v1.4.0
[1.3.2]: https://github.com/SetWebUK/ecom-core/compare/v1.3.1...v1.3.2
[1.3.1]: https://github.com/SetWebUK/ecom-core/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/SetWebUK/ecom-core/compare/v1.2.1...v1.3.0
[1.2.1]: https://github.com/SetWebUK/ecom-core/releases/tag/v1.2.1
