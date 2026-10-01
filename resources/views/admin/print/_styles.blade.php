{{-- Shared A4 print styles for invoices and packing slips (screen preview with toolbar; clean black-on-white when printed). --}}
<style>
    *, *::before, *::after { box-sizing: border-box; }
    html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { margin: 0; font-family: Inter, "Helvetica Neue", Arial, sans-serif; font-size: 12.5px; line-height: 1.5; color: #1a1a1a; background: #e9ecef; }
    .toolbar { position: sticky; top: 0; z-index: 5; display: flex; gap: 12px; align-items: center; justify-content: space-between; padding: 10px 20px; background: #1a1a1a; color: #fff; font-size: 13px; }
    .toolbar__actions { display: flex; gap: 8px; }
    .toolbar button, .toolbar a { font: inherit; font-weight: 600; border: 0; border-radius: 8px; padding: 8px 16px; cursor: pointer; text-decoration: none; }
    .toolbar button { background: #1976d2; color: #fff; }
    .toolbar button:hover { background: #1565c0; }
    .toolbar a { background: transparent; color: #d0d0d0; box-shadow: inset 0 0 0 1px #555; }
    .toolbar a:hover { color: #fff; }
    .doc { position: relative; width: 210mm; min-height: 297mm; margin: 20px auto; padding: 14mm 15mm 16mm; background: #fff; box-shadow: 0 2px 10px rgba(0,0,0,.12); display: flex; flex-direction: column; page-break-after: always; break-after: page; }
    .doc:last-child { page-break-after: auto; break-after: auto; }
    .doc__body { flex: 1; }
    .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; padding-bottom: 18px; border-bottom: 2px solid #1a1a1a; margin-bottom: 22px; }
    .head img { max-height: 56px; max-width: 230px; display: block; }
    .title { text-align: right; }
    .title h1 { font-size: 28px; line-height: 1; letter-spacing: .08em; margin: 0 0 8px; text-transform: uppercase; font-weight: 800; }
    .facts { margin: 0; display: grid; grid-template-columns: auto auto; gap: 1px 14px; justify-content: end; font-size: 12px; }
    .facts dt { color: #6b7280; text-align: right; }
    .facts dd { margin: 0; font-weight: 600; text-align: right; }
    .parties { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 24px; }
    .parties h3 { font-size: 10.5px; text-transform: uppercase; letter-spacing: .08em; color: #6b7280; margin: 0 0 6px; font-weight: 700; }
    .parties p { margin: 0; white-space: pre-line; }
    .parties .strong { font-weight: 600; }
    .deliver { padding: 12px 14px; border: 1.5px solid #1a1a1a; border-radius: 6px; font-size: 14px; line-height: 1.45; }
    table.items { width: 100%; border-collapse: collapse; }
    table.items th { text-align: left; font-size: 10.5px; text-transform: uppercase; letter-spacing: .06em; color: #6b7280; font-weight: 700; border-bottom: 1.5px solid #1a1a1a; padding: 8px 6px; }
    table.items td { border-bottom: 1px solid #e5e7eb; padding: 10px 6px; vertical-align: top; }
    table.items tr { page-break-inside: avoid; break-inside: avoid; }
    .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .opt { color: #6b7280; font-size: 11.5px; }
    .refunded { color: #b3240b; font-size: 11.5px; font-weight: 600; }
    .summary { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; margin-top: 16px; page-break-inside: avoid; break-inside: avoid; }
    .summary__notes { flex: 1; font-size: 12px; }
    .totals { width: 46%; border-collapse: collapse; }
    .totals td { padding: 4px 6px; }
    .totals tr.grand td { border-top: 1.5px solid #1a1a1a; font-size: 15px; font-weight: 800; padding-top: 8px; }
    .totals tr.muted td { color: #6b7280; }
    .stamp { display: inline-block; margin-top: 10px; padding: 4px 12px; border: 2px solid currentColor; border-radius: 6px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; font-size: 12px; }
    .stamp--paid { color: #0f7b45; }
    .stamp--due { color: #b25e00; }
    .stamp--refunded { color: #b3240b; }
    .note { margin-top: 18px; padding: 10px 12px; border-left: 3px solid #1a1a1a; background: #f5f5f5; white-space: pre-line; page-break-inside: avoid; break-inside: avoid; }
    .foot { margin-top: 26px; padding-top: 10px; border-top: 1px solid #e5e7eb; font-size: 10.5px; color: #6b7280; text-align: center; line-height: 1.6; }
    .foot strong { color: #1a1a1a; }
    .check { width: 20px; height: 20px; border: 1.5px solid #1a1a1a; border-radius: 4px; display: inline-block; }
    .qty { font-size: 16px; font-weight: 800; text-align: center; }
    .thumb { width: 44px; height: 44px; object-fit: contain; border: 1px solid #e5e7eb; border-radius: 4px; display: block; }
    .big-number { font-size: 22px; font-weight: 800; letter-spacing: .02em; }
    .signoff { display: grid; grid-template-columns: repeat(2, 1fr); gap: 24px; margin-top: 24px; font-size: 11.5px; color: #6b7280; }
    .signoff span { display: block; border-bottom: 1px solid #9ca3af; height: 26px; margin-top: 4px; }
    @media screen and (max-width: 820px) {
        .doc { width: auto; min-height: 0; margin: 12px; padding: 20px; }
        .parties { grid-template-columns: 1fr; }
        .totals { width: 100%; }
        .summary { flex-direction: column-reverse; }
    }
    @media print {
        body { background: #fff; }
        .toolbar { display: none; }
        .doc { margin: 0; box-shadow: none; width: auto; min-height: calc(297mm - 24mm); padding: 0; }
        @page { size: A4; margin: 12mm 12mm; }
    }
</style>
