<?php

namespace Pine\Commerce\Services\Invoices;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;

/**
 * HTML → PDF with dompdf (pure PHP: no wkhtmltopdf/Chrome binary, no network). Locked down for templates that print
 * customer-entered text: no PHP, no JavaScript, no remote files (images are passed as data: URIs), local reads only
 * under public/. Fonts: the DejaVu family bundled with dompdf, embedded (subset) in every PDF, so £, € and accented
 * characters render everywhere. A template may set another font-family; custom fonts go in the font cache directory
 * (storage/app/private/dompdf) via dompdf's load_font tooling or @font-face with a local file.
 */
class PdfRenderer
{
    public function render(string $html, string $paper = 'a4', string $orientation = 'portrait'): string
    {
        $dompdf = new Dompdf($this->options());
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper(in_array(strtolower($paper), ['a4', 'letter', 'legal', 'a5'], true) ? strtolower($paper) : 'a4', $orientation);
        $dompdf->render();

        return (string) $dompdf->output();
    }

    public function options(): Options
    {
        $fonts = storage_path('app/private/dompdf');
        if (! is_dir($fonts)) {
            File::ensureDirectoryExists($fonts, 0755);
        }

        return new Options([
            'defaultFont' => 'DejaVu Sans',
            'defaultPaperSize' => 'a4',
            'defaultMediaType' => 'print',
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'isHtml5ParserEnabled' => true,
            'isFontSubsettingEnabled' => true,
            'fontDir' => $fonts,
            'fontCache' => $fonts,
            'tempDir' => sys_get_temp_dir(),
            'chroot' => [public_path()],
            'dpi' => 96,
        ]);
    }
}
