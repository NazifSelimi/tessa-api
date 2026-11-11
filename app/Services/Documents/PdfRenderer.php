<?php

namespace App\Services\Documents;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Turns a Blade view into PDF bytes with dompdf. DejaVu Sans (bundled with
 * dompdf) has Cyrillic and Albanian letters. Remote loading stays off: every
 * picture is handed over as a local file or a data URI.
 */
class PdfRenderer
{
    public function html(string $view, array $data): string
    {
        return view($view, $data)->render();
    }

    public function render(string $view, array $data, string $paper = 'a4'): string
    {
        return Pdf::loadHTML($this->html($view, $data))
            ->setPaper($paper)
            ->setOption([
                'defaultFont' => 'DejaVu Sans',
                'isRemoteEnabled' => false,
                'isPhpEnabled' => false,
                'isHtml5ParserEnabled' => true,
                'isFontSubsettingEnabled' => true,
                'dpi' => 96,
                'chroot' => [storage_path(), public_path(), base_path('vendor/dompdf')],
            ])
            ->output();
    }
}
