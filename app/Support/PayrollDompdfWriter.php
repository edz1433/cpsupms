<?php

namespace App\Support;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Writer\Pdf\Dompdf as BaseDompdfWriter;

class PayrollDompdfWriter extends BaseDompdfWriter
{
    protected function createExternalWriterInstance(): Dompdf
    {
        $fontDirectory = storage_path('framework/dompdf-fonts');
        File::ensureDirectoryExists($fontDirectory);

        $options = new Options;
        $options->set('fontDir', $fontDirectory);
        $options->set('fontCache', $fontDirectory);
        $options->set('tempDir', storage_path('framework/cache'));
        $options->set('isRemoteEnabled', false);
        $options->set('chroot', array_values(array_filter([
            public_path(),
            storage_path(),
            is_dir('C:\\Windows\\Fonts') ? 'C:\\Windows\\Fonts' : null,
        ])));

        $dompdf = new Dompdf($options);
        $this->registerSystemFonts($dompdf);

        return $dompdf;
    }

    private function registerSystemFonts(Dompdf $dompdf): void
    {
        $fonts = [
            ['Broadway', 'normal', 'normal', 'C:\\Windows\\Fonts\\BROADW.TTF'],
            ['Broadway BT', 'normal', 'normal', 'C:\\Windows\\Fonts\\BROADW.TTF'],
            ['BroadwayP', 'normal', 'normal', 'C:\\Windows\\Fonts\\BROADW.TTF'],
            ['Bauhaus 93', 'normal', 'normal', 'C:\\Windows\\Fonts\\BAUHS93.TTF'],
            ['Arial', 'normal', 'normal', 'C:\\Windows\\Fonts\\arial.ttf'],
            ['Arial', 'normal', 'bold', 'C:\\Windows\\Fonts\\arialbd.ttf'],
            ['Arial', 'italic', 'normal', 'C:\\Windows\\Fonts\\ariali.ttf'],
            ['Arial', 'italic', 'bold', 'C:\\Windows\\Fonts\\arialbi.ttf'],
        ];

        foreach ($fonts as [$family, $style, $weight, $path]) {
            if (is_file($path)) {
                $dompdf->getFontMetrics()->registerFont(
                    compact('family', 'style', 'weight'),
                    $path
                );
            }
        }
    }
}
