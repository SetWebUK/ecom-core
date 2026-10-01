{{-- Shared styles of the PDF documents (dompdf: CSS 2.1 + tables – no flex/grid). DejaVu Sans is embedded, so £/€/accents render. --}}
<style>
    @page { margin: 14mm 14mm 18mm; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: "DejaVu Sans", sans-serif; font-size: 9pt; line-height: 1.4; color: #1a1a1a; }
    .doc { page-break-after: always; }
    .doc--last { page-break-after: auto; }
    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; }
    .head td { padding: 0 0 12px; }
    .head { border-bottom: 1.5px solid #1a1a1a; margin-bottom: 16px; }
    .logo { max-height: 52px; max-width: 220px; }
    .store-name { font-size: 16pt; font-weight: bold; }
    h1 { font-size: 18pt; letter-spacing: 1px; text-transform: uppercase; margin: 0 0 6px; text-align: right; }
    .facts { width: auto; margin-left: auto; }
    .facts td { padding: 1px 0 1px 12px; text-align: right; font-size: 8.5pt; }
    .facts .k { color: #6b7280; }
    .facts .v { font-weight: bold; }
    .big-number { font-size: 15pt; font-weight: bold; text-align: right; }
    .parties { margin: 0 0 18px; }
    .parties td { width: 33.33%; padding-right: 14px; }
    .label { font-size: 7.5pt; text-transform: uppercase; letter-spacing: .5px; color: #6b7280; font-weight: bold; margin: 0 0 4px; }
    .pre { white-space: pre-line; }
    .strong { font-weight: bold; }
    .deliver { border: 1.2px solid #1a1a1a; padding: 8px 10px; font-size: 10pt; }
    .items th { text-align: left; font-size: 7.5pt; text-transform: uppercase; letter-spacing: .4px; color: #6b7280; border-bottom: 1.2px solid #1a1a1a; padding: 6px 5px; }
    .items td { border-bottom: 0.6px solid #d9dde3; padding: 7px 5px; }
    .items tr { page-break-inside: avoid; }
    .num { text-align: right; white-space: nowrap; }
    .center { text-align: center; }
    .opt { color: #6b7280; font-size: 8pt; }
    .refunded { color: #b3240b; font-size: 8pt; font-weight: bold; }
    .summary { margin-top: 12px; page-break-inside: avoid; }
    .summary > tbody > tr > td { padding: 0; }
    .totals td { padding: 3px 5px; }
    .totals .grand td { border-top: 1.2px solid #1a1a1a; font-size: 11pt; font-weight: bold; padding-top: 6px; }
    .totals .muted td { color: #6b7280; }
    .taxes { margin-top: 12px; width: 100%; }
    .taxes th { text-align: left; font-size: 7.5pt; color: #6b7280; text-transform: uppercase; border-bottom: 0.6px solid #d9dde3; padding: 3px 5px; }
    .taxes td { padding: 3px 5px; font-size: 8.5pt; }
    .stamp { display: inline-block; padding: 3px 10px; border: 1.5px solid #0f7b45; color: #0f7b45; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; font-size: 9pt; }
    .stamp--due { border-color: #b25e00; color: #b25e00; }
    .stamp--refunded { border-color: #b3240b; color: #b3240b; }
    .note { margin-top: 14px; padding: 7px 9px; border-left: 2.5px solid #1a1a1a; background: #f3f4f6; white-space: pre-line; page-break-inside: avoid; }
    .foot { margin-top: 22px; padding-top: 8px; border-top: 0.6px solid #d9dde3; font-size: 7.5pt; color: #6b7280; text-align: center; line-height: 1.5; page-break-inside: avoid; }
    .foot strong { color: #1a1a1a; }
    .check { display: inline-block; width: 13px; height: 13px; border: 1.2px solid #1a1a1a; }
    .qty { font-size: 11pt; font-weight: bold; text-align: center; }
    .signoff td { width: 50%; padding: 18px 14px 0 0; font-size: 8pt; color: #6b7280; }
    .signoff .line { border-bottom: 0.8px solid #9ca3af; height: 20px; }
</style>
