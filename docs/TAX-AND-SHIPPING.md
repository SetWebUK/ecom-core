# Tax and shipping (v1.1)

How Pine Commerce works out VAT/sales tax and delivery prices, where each part is configured, and what an order
stores. Admin screens: **Settings › Tax** and **Settings › Shipping**.

## 1. Tax

### 1.1 Settings (`tax.*`, Settings › Tax)

Each setting is the saved value, else `config('commerce.tax.*')`, else the pre-v1.1 behaviour.

| Setting | Values | Package default (new stores) | Pre-v1.1 behaviour |
|---|---|---|---|
| `tax.enabled` | bool | `true` | – |
| `tax.prices_include_tax` | bool – prices are entered with tax in them | `true` (UK style) | `false` |
| `tax.display_shop` | `incl` \| `excl` (`''` = as entered) – shop pages, search, feed, JSON-LD | `incl` | as entered |
| `tax.display_cart` | `incl` \| `excl` (`''` = as entered) – basket, checkout, receipts, emails | `incl` | as entered |
| `tax.rounding` | `line` (each rate per line, WooCommerce default) \| `order` | `line` | `line` |
| `tax.based_on` | `shipping` \| `billing` \| `base` (the shop's address) | `shipping` | – |
| `tax.shipping_taxable` | bool (methods also have their own tax status) | `true` | `true` |
| `tax.shipping_tax_class` | `inherit` \| a class slug | `inherit` | – |
| `tax.shipping_prices_include_tax` | `''` (like product prices) \| `yes` \| `no` (WooCommerce) | `''` | – |
| `tax.adjust_non_base_prices` | bool – inclusive prices: other rates pay net + their own rate | `true` | – |
| `tax.price_suffix` | text after shop prices; `{price_incl}` / `{price_excl}` | `''` | – |
| `tax.label` | name for rates without one and for orders without per-rate lines | `VAT` | `VAT` |
| `tax.base_state`, `tax.base_postcode` | the shop's county/postcode (country = `store.country`) | – | – |
| `commerce.tax.install_rates` (config only) | rates `commerce:install` seeds: `uk` \| `eu` \| `uk+eu` \| `none` | `uk` | – |

A client config can keep prices entered and shown **without** tax with **no rate rows** (`install_rates` `none`): then
nothing changes for it – no tax is charged, exactly as before 1.1.

### 1.2 Classes and rates

- **Tax classes** (`tax_classes`): `standard`, `reduced-rate`, `zero-rate` (created by the migration) plus custom ones.
  Products use `products.tax_class` (null/'' = standard, like WooCommerce), variations `product_variations.tax_class`
  (null = the product's class). `products.tax_status`: `taxable`, `shipping` (only the shipping is taxed) or `none`.
- **Rates** (`tax_rates`): class, country (`''` = every country), state/county, postcode patterns, cities, rate %,
  name, priority, compound, shipping. Matching (`Pine\Commerce\Services\Tax\TaxRates`) follows WooCommerce: for each
  priority the most specific matching rate wins; rates with different priorities add up; compound rates are charged
  on the price plus the taxes before them.
- **Postcode patterns** (`Pine\Commerce\Support\PostcodeMatcher`, also used by shipping zones): exact (`SW1A 1AA`),
  a UK outcode (`BT1`), wildcards (`BT*`, `JE? 3AB`), UK district ranges (`HS1-HS9`), numeric ranges
  (`10000...19999`), exclusions (`!IV1`).
- CSV import/export on Settings › Tax uses WooCommerce's columns: `Country code, State code, Postcode / ZIP, City,
  Rate %, Tax name, Priority, Compound, Shipping, Tax class` (postcodes/cities separated by `;`).

### 1.3 The maths (`Pine\Commerce\Services\Tax\TaxEngine`)

1. Lines are priced **as entered**; coupons come off the entered amounts (a £10 coupon takes £10 off what the
   customer sees in both modes).
2. Per line, tax is **added** (exclusive prices) or **extracted** (inclusive prices) for the rates of the customer's
   address (`tax.based_on`; the shop's country until an address is typed; the shop's address for local pickup).
3. Inclusive prices and a customer whose rates differ from the shop's: with `tax.adjust_non_base_prices` the price
   without the shop's tax is charged plus the customer's own rates (no UK VAT for a US customer).
4. Shipping is taxed with the shipping tax class (`inherit` = standard when any item is standard-rated, else the
   highest-rated class in the basket) when the method and `tax.shipping_taxable` allow it.
5. Rounding: `line` rounds each rate on each line; `order` sums unrounded and rounds each rate once. Inclusive totals
   never change with rounding – the customer pays the prices shown.

`Cart::totals()` keeps its keys **without tax** (`subtotal`, `discount`, `shipping`) plus `tax`, `total`,
`shipping_tax` and adds `tax_lines` (per rate), `subtotal_display`, `discount_display`, `shipping_display`,
`coupons_display`, `display_incl`, `prices_include_tax`, `shipping_zone`, `shipping_location`, `tax_location`.
`CartLine` gains `netSubtotal`, `netTotal`, `subtotalTax`, `taxes`, `displaySubtotal()`, `displayTotal()`.
Shop prices go through `Pine\Commerce\Services\Tax\PriceDisplay` (used by `ProductPresenter::price/priceHtml`), which
returns prices untouched when entry and display agree.

### 1.4 What an order stores

Like WooCommerce, amounts are stored **without tax**: `orders.subtotal`, `discount_total`, `shipping_total`,
`tax_total` (items + shipping), `shipping_tax` (new), `total`, `prices_include_tax` (new);
`order_items.subtotal`/`total` (net), `tax`, `subtotal_tax` (new), `tax_class` (new), `taxes` (new, `{rate id: amount}`);
`order_tax_lines` (new) holds one row per rate (label, rate, items tax, shipping tax). `Order::taxBreakdown()` returns
the per-rate lines (or one line with `tax_total` for older/imported orders), `Order::displayAmounts()` the receipt
amounts including or excluding tax. Refunds of items refund the line's tax too.

## 2. Shipping

### 2.1 Zones (`shipping_zones`)

A zone has countries or `country:state` regions (none = everywhere) and optional postcode patterns. Checkout uses the
**first** zone in the admin's order that matches the delivery address and offers that zone's active methods, then any
**unzoned** methods (no zone – how every method worked before v1.1, still limited by their own country list). No
matching zone and no unzoned method = "we don't deliver to …" at checkout. The countries customers can choose at
checkout are the setting `checkout.countries` (Settings › Shipping), else the keys of `config('commerce.store.countries')`.

### 2.2 Method types (`shipping_methods.type`, `settings` JSON)

| Type | Price |
|---|---|
| `flat_rate` | `cost` or a formula in `settings.cost` (`[qty]`, `[cost]`, `[fee percent="10" min_fee="" max_fee=""]`, + − * / brackets – never eval'd); `settings.calculation` `order` (once), `item` (× quantity) or `class` (+ `class_costs` {class id: formula}, `no_class_cost`, `class_mode` `sum`\|`max`) |
| `free_shipping` | free; `settings.requires` `''`, `coupon`, `min_amount`, `either`, `both` (minimum = `min_order_amount`), `settings.ignore_discounts` |
| `weight_table` | bands `settings.rates` [{min, max, cost}] on the basket weight (kg; variation weight, else product weight) |
| `price_table` | bands on the basket value after discounts |
| `local_pickup` | `cost`; tax at the shop's address |

Common: `min_order_amount` hides other types below that basket value (a free-shipping coupon still unlocks methods
whose code starts with `free`, as before), `countries` limits further, `tax_status` `taxable`\|`none`. Registered
calculators (`Commerce::shippingCalculator()`) run after the type's price; their `$context` now also has `postcode` and
`zone`. Shipping classes (`shipping_classes`) are set on products and variations (`shipping_class_id`).

### 2.3 Checkout

Both themes send the address (country, postcode, county, city – and the billing address when it differs) to
`POST /checkout/update` whenever it changes; the basket remembers it (`carts.destination`), so zones and tax follow the
customer. Postcodes are validated as UK postcodes for GB/IM/JE/GG and loosely elsewhere.

## 3. Upgrading an existing store (migration `2026_09_30_120000`)

Additive only. The old single `tax.rate` setting (Settings › Checkout › VAT, now removed) becomes one equivalent rate
row (standard class, every country, also on shipping) and `tax.prices_include_tax = false`; a store without it gets no
rates. Existing methods move into one zone for the countries they were limited to ("United Kingdom (UK)" for `GB`,
"Everywhere" if any had no limit), keep cost, minimum and countries; a free method (`free…`, cost 0) becomes the free
shipping type with the same coupon/minimum rule. Totals stay identical (tests: `tests/Tax/UpgradeMigrationTest.php`).

## 4. Importer

- `commerce-import.settings.woocommerce` null (all) or listing the keys imports `tax.enabled`,
  `tax.prices_include_tax`, `tax.display_shop`, `tax.display_cart`, `tax.based_on`, `tax.rounding`
  (`woocommerce_tax_round_at_subtotal`), `tax.shipping_tax_class`, `tax.shipping_prices_include_tax` (`no` when prices
  include tax – WooCommerce shipping costs are ex. tax), `tax.price_suffix`, `checkout.countries` (specific countries).
- Step `extras.tax` (`TaxStep`, alias `tax`) imports tax classes (`wc_tax_rate_classes` or `woocommerce_tax_classes`)
  and rates with postcode/city locations when `tax.rates` is allowed by that list.
- `extras.shipping` with `commerce-import.shipping.zones` (default `true`) imports zones (countries, states, every
  WooCommerce continent – AF, AN, AS, EU, NA, OC, SA – as WooCommerce's countries for it, postcodes, "rest of the world"), `flat_rate` (costs, formulas, class costs, per class /
  per order), `free_shipping` (requires, min amount, ignore discounts), `local_pickup`, other plugin methods switched
  off, and `product_shipping_class` terms onto products/variations. `false` keeps the pre-v1.1 flat list.
