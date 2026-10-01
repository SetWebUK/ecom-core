# Invoices and PDF documents (since 1.1)

Invoices and packing slips as PDF files, sequential invoice numbers, the invoice attached to the order emails and a
customer download. PDFs are made on the fly with [dompdf](https://github.com/dompdf/dompdf) (pure PHP: no
wkhtmltopdf/Chrome binary, no network access). The printable HTML pages (`admin.print`) still work as before.

## What the shop owner gets

| Where | What |
|---|---|
| Admin › Orders › order › **Print** | Packing slip / Invoice (HTML, print from the browser), **Invoice PDF**, **Packing slip PDF**, **Regenerate invoice** |
| Admin › Orders list, select orders › **Print** | Invoices – one PDF (one page per order), Invoices – ZIP of PDFs (one file per order), Packing slips – one PDF (up to 200 orders) |
| Admin › Orders › order › Payment details | The invoice number and date (when numbering is on) |
| Admin › Settings › **Invoices** | Numbering, sending, downloads, notes/footers, paper size (below) |
| My account › View order | "Download invoice (PDF)" – signed-in owner only |
| Order-received page | "Download invoice (PDF)" – anyone with the order's private key link (guests) |
| Customer emails | The invoice PDF attached to "order received (processing)" and/or "order completed", and (since 1.2) to the "Order details / invoice" email sent from the admin order page |

## Settings › Invoices

Every setting's default comes from `config('commerce.invoices')`, so a client can ship different defaults; once the
shop owner saves the screen, the saved values win.

| Setting (key) | Config default: package / a client keeping 1.0 behaviour | Meaning |
|---|---|---|
| Give invoices their own sequential numbers (`invoices.numbering`) | **on** / off | Off = the invoice number is the order number (the behaviour before 1.1). |
| Issue the number when the order is (`invoices.assign_on`) | paid / paid | `paid` = processing or completed; `completed` = only completed. |
| Prefix / Suffix (`invoices.prefix`, `invoices.suffix`) | `INV-` / `INV-`, empty | `{Y}`, `{y}`, `{m}` = year/month of the invoice date, e.g. `{Y}/` → `2026/00042`. May be saved empty. |
| Minimum digits (`invoices.padding`) | 5 / 5 | Zero padding: 5 → `00042`. |
| Next invoice number (`invoices.next_number`) | `start` = 1 | Can only be moved **forward**; the form refuses a lower number. |
| Attach to "order received" email (`invoices.attach_customer_processing`) | **on** / off | |
| Attach to "order completed" email (`invoices.attach_customer_completed`) | **on** / off | |
| Attach to "Order details / invoice" email (`invoices.attach_customer_invoice`, since 1.2) | **on** / off | The email sent with Send email on an order page. Unpaid orders get no PDF (see Emails). A client `attach` block without this key = off. |
| Customers can download their invoice (`invoices.customer_download`) | **on** / off | My account + order-received page links. |
| Invoice notes (`invoices.notes`) | – | Printed under the totals (payment terms, bank details…). |
| Invoice footer / Packing slip footer (`documents.invoice_footer`, `documents.packing_slip_footer`) | – | Bottom line of each document (the invoice footer used to be under Settings › Checkout). |
| Paper size (`invoices.paper`) | A4 | A4 or US Letter. |
| (config only) `invoices.cache` | off | Keep generated single-order PDFs in `storage/app/private/invoices` (never public). |

The store details printed on documents come from Settings › Store details: name, company name, logo (or
`commerce.documents.logo`), address, phone, email, **VAT number**, **company number**, registered office.

## Invoice numbers

- Stored on the order: `orders.invoice_number` (unique) and `orders.invoice_date` (migration
  `2026_09_30_150500_add_invoice_numbers_to_orders`, additive; existing orders keep `NULL` until they get a number).
- The counter is the `invoice` row of the `sequences` table (last number issued).
- Issued by the `AssignInvoiceNumber` listener when an order reaches the chosen status (it runs before the order
  emails). Issuing locks the order row, then the counter row, in one transaction – two payments at the same moment
  never share a number and an order never gets two. A number is never reused: the counter only goes up, changing the
  prefix does not reset it, and a formatted number that already exists (e.g. imported) is skipped.
- Orders paid **before** numbering was switched on get their number the first time their invoice is made (download,
  email, print or Regenerate) – so older orders may be numbered later than newer ones. This is expected.
- An order that has not reached the status yet prints as a **Pro forma invoice** (admin only) without an invoice
  number. Customers see the download link only once the invoice exists.
- SQLite installs: numbering is safe too (the writer takes SQLite's database lock first); set a `busy_timeout` on the
  connection if many processes write at once.

## Access rules

| Who | Route | Rule |
|---|---|---|
| Customer (signed in) | `GET my-account/view-order/{number}/invoice` (`account.order.invoice`) | Order belongs to the user |
| Guest / anyone with the link | `GET checkout/order-received/{number}/invoice?key=…` (`checkout.invoice`) | `hash_equals` on the order key |
| Staff | `GET admin/orders/{id}/pdf/{invoice\|packing-slip}` (`admin.orders.pdf`, `?inline=1` to open in the browser), `GET admin/pdf/{document}?orders=1,2,3[&format=zip]` (`admin.pdf`), `POST admin/orders/{id}/invoice/regenerate` | `admin` middleware (staff) |

Customer routes answer **404** (never 403) for someone else's order, a wrong key, a switched-off download or an order
without an invoice yet, and are throttled (30/min). Responses are `no-store` and `noindex`.

## Templates (theme-overridable)

The PDF views are `pdf.invoice` and `pdf.packing-slip`, looked up in this order:

1. `resources/views/pdf/invoice.blade.php` (client one-off override),
2. the active theme and its parents: `themes/{slug}/views/pdf/invoice.blade.php`,
3. the core template `commerce::pdf.invoice` (package `resources/views/pdf/`, overridable at
   `resources/views/vendor/commerce/pdf/…`).

Data passed: `$orders` (one page per order – end each page with `page-break-after: always` except the last),
`$store` (`DocumentData::store()`), `$logo` (the logo as a `data:` URI or `null`), `$document`. Helpers:
`Invoices::number($order, false)`, `Invoices::date($order)`, `DocumentData::address($order, 'billing')`,
`DocumentData::stamp($order)`, `DocumentData::taxLines($order)`.

Write dompdf-friendly HTML: tables for layout (no flexbox/grid), inline `<style>`, font `DejaVu Sans` (embedded, covers
£, € and accented characters). Remote files, PHP and JavaScript are disabled – pass images as `data:` URIs.

**Tax breakdown.** `DocumentData::taxLines()` returns one row per rate from, in order: a `taxLines()` relation or
`tax_lines` attribute on the order, `meta.tax_lines` (`[{label, rate, net, tax}]`), otherwise one line from
`orders.tax_total` with the store's tax label and rate. The core invoice shows a per-rate table when rates are known,
and "Includes VAT" instead of a VAT line when the order total already includes the tax.

## Emails

`Pine\Commerce\Mail\CustomerProcessingOrder` and `CustomerCompletedOrder` return `customer_processing` /
`customer_completed` from `invoiceEmailKey()`; `OrderEmail::attachments()` adds the invoice PDF when that email is
switched on in Settings › Invoices and the invoice exists. A client replacement Mailable (`Commerce::orderEmail()`)
that extends the core class inherits this; one that extends `OrderEmail` directly can return a key too. A PDF error is
logged and never stops the email.

**"Order details / invoice" (back office, since 1.2).** `Mail\Admin\CustomerInvoice` (Admin › Orders › order › Send
email) returns `customer_invoice` and attaches the PDF while `invoices.attach_customer_invoice` is on and the order has
an invoice under the same rules as a download: with numbering on, an issued number – or a paid order that qualifies,
whose number is issued when the email is sent; with numbering off, a paid order (the order number). An **unpaid** order
(the "Pay for this order" email) gets **no PDF** and never a number; with `assign_on` = completed a paid but not yet
completed order gets none either. The send modal shows what will happen before sending (`CustomerInvoice::attachmentNote()`,
`Invoices::emailAttachment($order, $key)`), and the success message and the order note say whether the invoice was attached.

## For developers

```php
use Pine\Commerce\Services\Invoices\{Invoices, InvoiceNumbers, InvoicePdf};

Invoices::number($order);                     // issued number (issues it if due), order number when numbering is off, null if not yet
app(InvoicePdf::class)->render($order);       // PDF bytes (invoice); render($orders, 'packing-slip') for many orders in one file
app(InvoicePdf::class)->zip($orders);         // temp path of a ZIP of PDFs (needs ext-zip)
app(InvoiceNumbers::class)->assign($order);   // issue now (idempotent)
Invoices::customerUrl($order, auth()->user()); // download link for the storefront, or null
```
