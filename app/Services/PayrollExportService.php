<?php

namespace App\Services;

use App\Models\PayrollBatch;
use App\Support\PayrollDompdfWriter;
use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class PayrollExportService
{
    public function __construct(private PayrollWorkbookService $workbooks) {}

    public function excel(PayrollBatch $batch): Response
    {
        $spreadsheet = $this->workbooks->build($batch);
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(true);
        $content = $this->render($writer, $spreadsheet);

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($batch, 'xlsx').'"',
        ]);
    }

    public function pdf(PayrollBatch $batch): Response
    {
        $spreadsheet = $this->workbooks->build($batch);
        $writer = new PayrollDompdfWriter($spreadsheet);
        $writer->setSheetIndex(0);
        $writer->setPreCalculateFormulas(true);
        $content = $this->render($writer, $spreadsheet);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->filename($batch, 'pdf').'"',
        ]);
    }

    private function render(object $writer, Spreadsheet $spreadsheet): string
    {
        $level = ob_get_level();
        ob_start();
        try {
            $writer->save('php://output');

            return (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function filename(PayrollBatch $batch, string $extension): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $batch->batch_no) ?: 'payroll';

        return $name.'-payroll.'.$extension;
    }
}
