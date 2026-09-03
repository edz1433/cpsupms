<?php

namespace App\Services;

use App\Models\PayrollBatch;
use App\Models\PayrollLine;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

class PayrollWorkbookService
{
    private const TEMPLATE_PATH = 'Payroll sample.xlsx';

    private const LAYOUTS = [
        'INC' => ['last' => 'V', 'start' => 11, 'footer' => 121, 'employee' => 12, 'group' => 11, 'subtotal' => 37, 'blank' => 38, 'grand' => 119, 'period' => 'A3', 'mode' => 'fund'],
        'MDS' => ['last' => 'U', 'start' => 10, 'footer' => 46, 'employee' => 10, 'total' => 45, 'period' => 'A3'],
        'PROJ' => ['last' => 'U', 'start' => 10, 'footer' => 19, 'employee' => 10, 'total' => 18, 'period' => 'A3'],
        'BUSTYPE' => ['last' => 'V', 'start' => 10, 'footer' => 18, 'employee' => 10, 'total' => 17, 'period' => 'A3'],
        'YEARBOOK' => ['last' => 'T', 'start' => 10, 'footer' => 12, 'employee' => 10, 'total' => 11, 'period' => 'A3'],
        'SUPPORT SERVICES' => ['last' => 'S', 'start' => 12, 'footer' => 18, 'employee' => 12, 'subtotal' => 14, 'blank' => 15, 'grand' => 16, 'period' => 'A4', 'mode' => 'office'],
    ];

    public function __construct(private PayrollSignatoryService $signatories) {}

    public function build(PayrollBatch $batch): Spreadsheet
    {
        $batch->loadMissing(['campus', 'period', 'template', 'fundCluster', 'lines.employee']);
        $code = strtoupper(trim((string) $batch->template?->code));
        $path = public_path(self::TEMPLATE_PATH);

        if (! is_file($path)) {
            throw new RuntimeException('The payroll workbook template is missing: public/'.self::TEMPLATE_PATH);
        }

        $reader = IOFactory::createReader('Xlsx');
        $reader->setLoadSheetsOnly([$code]);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheetByName($code);

        if (! $sheet) {
            throw new RuntimeException("The payroll workbook template does not contain the {$code} sheet.");
        }

        $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($sheet));
        $spreadsheet->getCalculationEngine()->clearCalculationCache();

        if ($code === 'PT') {
            $this->buildPartTime($sheet, $batch);
        } elseif (isset(self::LAYOUTS[$code])) {
            $this->buildStandard($sheet, $batch, $code, self::LAYOUTS[$code]);
        } else {
            throw new RuntimeException("No payroll export layout is configured for {$code}.");
        }

        $sheet->setTitle($code);
        $sheet->setSelectedCell('A1');
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function buildStandard(Worksheet $sheet, PayrollBatch $batch, string $code, array $layout): void
    {
        $prototype = clone $sheet;
        $dynamicCount = $layout['footer'] - $layout['start'];
        $sheet->removeRow($layout['start'], $dynamicCount);

        $descriptors = $this->standardRows($batch->lines, $layout);
        $sheet->insertNewRowBefore($layout['start'], count($descriptors));

        $row = $layout['start'];
        $subtotals = [];
        foreach ($descriptors as $descriptor) {
            $this->copyRow($prototype, $descriptor['prototype'], $sheet, $row);

            if ($descriptor['type'] === 'employee') {
                $this->writeEmployee($sheet, $row, $code, $descriptor['line'], $descriptor['number'], $layout['last']);
            } elseif ($descriptor['type'] === 'group') {
                $this->clearRow($sheet, $row, $layout['last']);
                $sheet->setCellValue("A{$row}", $descriptor['label']);
                $sheet->mergeCells("A{$row}:B{$row}");
            } elseif ($descriptor['type'] === 'subtotal') {
                $subtotals[] = $row;
                $this->writeTotal($sheet, $row, $code, $descriptor['first'], $descriptor['last'], $descriptor['label']);
            } elseif ($descriptor['type'] === 'grand') {
                $this->writeGrandTotal($sheet, $row, $code, $subtotals);
            } elseif ($descriptor['type'] === 'total') {
                $this->writeTotal($sheet, $row, $code, $descriptor['first'], $descriptor['last']);
            }

            $row++;
        }

        $footerRow = $layout['start'] + count($descriptors);
        $sheet->setCellValue($layout['period'], $this->periodText($batch, true));
        $this->writeFooter($sheet, $batch, $code, $footerRow);
        $this->formatPresentationText($sheet, $code, $footerRow, $layout['last']);
        $sheet->getPageSetup()->setPrintArea("A1:{$layout['last']}".$sheet->getHighestDataRow());
        $this->configurePage($sheet);
    }

    private function standardRows(Collection $lines, array $layout): array
    {
        if (($layout['mode'] ?? null) === 'fund') {
            return $this->groupedRows($lines, $layout, fn (PayrollLine $line) => $line->fund_source ?: 'UNASSIGNED FUND SOURCE', true);
        }

        if (($layout['mode'] ?? null) === 'office') {
            return $this->groupedRows($lines, $layout, fn (PayrollLine $line) => $line->employee?->office_name ?: ($line->fund_source ?: 'UNASSIGNED OFFICE'), false);
        }

        $rows = [];
        $first = $layout['start'];
        foreach ($lines->values() as $index => $line) {
            $rows[] = ['type' => 'employee', 'prototype' => $layout['employee'], 'line' => $line, 'number' => $index + 1];
        }
        $last = $first + max(0, $lines->count() - 1);
        $rows[] = ['type' => 'total', 'prototype' => $layout['total'], 'first' => $first, 'last' => $lines->isEmpty() ? null : $last];

        return $rows;
    }

    private function groupedRows(Collection $lines, array $layout, callable $key, bool $showGroupHeader): array
    {
        $groups = $lines->groupBy($key, preserveKeys: false);
        if ($groups->isEmpty()) {
            $groups = collect(['NO EMPLOYEES' => collect()]);
        }

        $rows = [];
        $cursor = $layout['start'];
        $number = 1;

        foreach ($groups as $label => $members) {
            if ($showGroupHeader) {
                $rows[] = ['type' => 'group', 'prototype' => $layout['group'], 'label' => strtoupper((string) $label)];
                $cursor++;
            }

            $first = $cursor;
            foreach ($members as $line) {
                $rows[] = ['type' => 'employee', 'prototype' => $layout['employee'], 'line' => $line, 'number' => $number++];
                $cursor++;
            }

            $rows[] = [
                'type' => 'subtotal',
                'prototype' => $layout['subtotal'],
                'first' => $first,
                'last' => $members->isEmpty() ? null : $cursor - 1,
                'label' => $showGroupHeader ? 'Sub Total ('.$label.')' : ($groups->count() === 1 ? 'Total' : 'Total ('.$label.')'),
            ];
            $cursor++;
            $rows[] = ['type' => 'blank', 'prototype' => $layout['blank']];
            $cursor++;
        }

        $rows[] = ['type' => 'grand', 'prototype' => $layout['grand']];
        $rows[] = ['type' => 'blank', 'prototype' => $layout['blank']];

        return $rows;
    }

    private function buildPartTime(Worksheet $sheet, PayrollBatch $batch): void
    {
        $prototype = clone $sheet;
        foreach (array_values($sheet->getMergeCells()) as $range) {
            $sheet->unmergeCells($range);
        }
        $sheet->removeRow(1, $sheet->getHighestDataRow());

        $groups = $batch->lines->groupBy(
            fn (PayrollLine $line) => $line->employee?->office_name ?: ($line->fund_source ?: $batch->fundCluster?->fund_source_name ?: 'PART-TIME PERSONNEL'),
            preserveKeys: false
        );
        if ($groups->isEmpty()) {
            $groups = collect(['PART-TIME PERSONNEL' => collect()]);
        }

        $cursor = 1;
        $page = 1;
        foreach ($groups as $label => $lines) {
            $blockStart = $cursor;
            for ($sourceRow = 1; $sourceRow <= 6; $sourceRow++) {
                $this->copyRow($prototype, $sourceRow, $sheet, $cursor++);
            }
            $this->copyMerges($prototype, $sheet, 1, 6, $blockStart - 1);

            $first = $cursor;
            foreach ($lines->values() as $index => $line) {
                $this->copyRow($prototype, 7, $sheet, $cursor);
                $this->writeEmployee($sheet, $cursor, 'PT', $line, $index + 1, 'V');
                $cursor++;
            }

            $this->copyRow($prototype, 19, $sheet, $cursor);
            $this->writeTotal($sheet, $cursor, 'PT', $first, $lines->isEmpty() ? null : $cursor - 1);
            $cursor++;

            $footerRow = $cursor;
            for ($sourceRow = 20; $sourceRow <= 38; $sourceRow++) {
                $this->copyRow($prototype, $sourceRow, $sheet, $cursor++);
            }
            $this->copyMerges($prototype, $sheet, 20, 38, $footerRow - 20);

            $sheet->setCellValue('A'.($blockStart + 2), $label);
            $sheet->setCellValue('A'.($blockStart + 3), $this->periodText($batch, false));
            $sheet->setCellValue('U'.($blockStart + 4), 'PAGE '.$page.' OF '.$groups->count());
            $this->writeFooter($sheet, $batch, 'PT', $footerRow);
            $this->formatPresentationText($sheet, 'PT', $footerRow, 'V', $blockStart);

            $cursor++;
            if ($page < $groups->count()) {
                $sheet->setBreak("A{$cursor}", Worksheet::BREAK_ROW);
            }
            $page++;
        }

        $sheet->getPageSetup()->setPrintArea('A1:V'.($cursor - 1));
        $this->configurePage($sheet);
    }

    private function writeEmployee(Worksheet $sheet, int $row, string $code, PayrollLine $line, int $number, string $lastColumn): void
    {
        $this->clearRow($sheet, $row, $lastColumn);
        $tax = $this->taxSplit($line);
        $late = (float) $line->late_deduction + (float) $line->undertime_deduction;
        $other = (float) $line->other_deductions + (float) $line->graduate_school_deduction;
        $common = ['A' => $number, 'B' => $line->employee_name, 'C' => $line->designation];

        $values = match ($code) {
            'PT' => $common + [
                'D' => $line->monthly_salary, 'E' => $line->rendered_days, 'F' => $line->rate_per_day,
                'G' => $late, 'H' => $line->gross_income, 'I' => $line->absent_deduction, 'J' => $line->salary_differential,
                'K' => $line->earned_for_period, 'L' => $tax[3], 'M' => $tax[2], 'N' => $line->sss,
                'O' => $line->pagibig, 'P' => $line->philhealth, 'Q' => $line->project_deduction,
                'R' => $line->graduate_school_deduction, 'S' => $line->nsca_mpc, 'T' => $line->total_deduction,
                'U' => $line->net_amount_received, 'V' => $line->remarks,
            ],
            'INC' => $common + [
                'D' => $line->fund_source, 'E' => $line->rate_per_day, 'F' => $line->gross_income, 'G' => $late,
                'H' => $line->absent_deduction, 'I' => $line->salary_differential, 'J' => $line->earned_for_period,
                'K' => $tax[10], 'L' => $tax[5], 'M' => $tax[3], 'N' => $tax[2], 'O' => $line->sss,
                'P' => $line->philhealth, 'Q' => $line->pagibig, 'R' => $line->nsca_mpc,
                'S' => (float) $line->project_deduction + $other, 'T' => $line->total_deduction,
                'U' => $line->net_amount_received, 'V' => $line->remarks,
            ],
            'MDS' => $common + [
                'D' => $line->fund_source, 'E' => $line->rate_per_day, 'F' => $line->gross_income, 'G' => $late,
                'H' => $line->absent_deduction, 'I' => $line->salary_differential, 'J' => $line->earned_for_period,
                'K' => $tax[5], 'L' => $tax[3], 'M' => $tax[2], 'N' => $line->sss, 'O' => $line->philhealth,
                'P' => $line->pagibig, 'Q' => $line->nsca_mpc, 'R' => (float) $line->project_deduction + $other,
                'S' => $line->total_deduction, 'T' => $line->net_amount_received, 'U' => $line->remarks,
            ],
            'PROJ' => $common + [
                'D' => $line->fund_source, 'E' => $line->rate_per_day, 'F' => $line->gross_income, 'G' => $late,
                'H' => $line->absent_deduction, 'I' => $line->salary_differential, 'J' => $line->earned_for_period,
                'K' => $tax[5], 'L' => $tax[3], 'M' => $tax[2], 'N' => $line->sss, 'O' => $line->project_deduction,
                'P' => $line->philhealth, 'Q' => $line->pagibig, 'R' => (float) $line->nsca_mpc + $other,
                'S' => $line->total_deduction, 'T' => $line->net_amount_received, 'U' => $line->remarks,
            ],
            'BUSTYPE' => $common + [
                'D' => $line->fund_source, 'E' => $line->rate_per_day, 'F' => $line->gross_income, 'G' => $late,
                'H' => $line->absent_deduction, 'I' => $line->salary_differential, 'J' => $line->earned_for_period,
                'K' => $tax[3], 'L' => $tax[2], 'O' => $line->sss, 'P' => $line->philhealth,
                'Q' => $line->pagibig, 'R' => $line->nsca_mpc, 'S' => (float) $line->project_deduction + $other,
                'T' => $line->total_deduction, 'U' => $line->net_amount_received, 'V' => $line->remarks,
            ],
            'YEARBOOK' => $common + [
                'D' => $line->fund_source, 'E' => $line->rate_per_day, 'F' => $line->gross_income, 'G' => $late,
                'H' => $line->absent_deduction, 'I' => $line->salary_differential, 'J' => $line->earned_for_period,
                'K' => $tax[3], 'L' => $tax[2], 'M' => $line->sss, 'N' => $line->philhealth,
                'O' => $line->pagibig, 'P' => $line->nsca_mpc, 'Q' => (float) $line->project_deduction + $other,
                'R' => $line->total_deduction, 'S' => $line->net_amount_received, 'T' => $line->remarks,
            ],
            'SUPPORT SERVICES' => $common + [
                'D' => $line->employee?->office_name ?: $line->fund_source, 'E' => $line->rate_per_day, 'F' => $line->gross_income,
                'G' => $late, 'H' => $line->absent_deduction, 'I' => $line->salary_differential, 'J' => $line->earned_for_period,
                'K' => $tax[3], 'L' => $tax[2], 'N' => $line->sss, 'O' => $line->nsca_mpc,
                'P' => (float) $line->project_deduction + $other, 'Q' => $line->total_deduction,
                'R' => $line->net_amount_received, 'S' => $line->remarks,
            ],
        };

        foreach ($values as $column => $value) {
            $sheet->setCellValue($column.$row, $value);
        }
    }

    private function writeTotal(Worksheet $sheet, int $row, string $code, int $first, ?int $last, ?string $label = null): void
    {
        $this->clearRow($sheet, $row, $this->lastColumn($code));
        if ($label !== null) {
            $sheet->setCellValue("A{$row}", $label);
        }
        foreach ($this->totalColumns($code) as $column) {
            $sheet->setCellValue($column.$row, $last === null ? 0 : "=SUM({$column}{$first}:{$column}{$last})");
        }
    }

    private function writeGrandTotal(Worksheet $sheet, int $row, string $code, array $subtotals): void
    {
        $this->clearRow($sheet, $row, $this->lastColumn($code));
        $sheet->setCellValue("A{$row}", 'GRAND TOTAL');
        $this->mergeCells($sheet, "A{$row}:B{$row}");
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        foreach ($this->totalColumns($code) as $column) {
            $references = array_map(fn (int $subtotal) => $column.$subtotal, $subtotals);
            $sheet->setCellValue($column.$row, $references === [] ? 0 : '=SUM('.implode(',', $references).')');
        }
    }

    private function writeFooter(Worksheet $sheet, PayrollBatch $batch, string $code, int $footer): void
    {
        $signatories = $this->signatories->forBatch($batch);
        $positions = [
            'PT' => ['prepared_by' => ['A', 3, 4], 'approved_by' => ['N', 3, 4], 'certified_correct_by' => ['A', 11, 12], 'certified_payment_by' => ['R', 15, 16]],
            'INC' => ['prepared_by' => ['A', 2, 3], 'approved_by' => ['O', 1, 2], 'certified_correct_by' => ['A', 9, 10], 'certified_payment_by' => ['R', 15, 16]],
            'MDS' => ['prepared_by' => ['A', 4, 5], 'approved_by' => ['N', 3, 4], 'certified_correct_by' => ['A', 11, 12], 'certified_payment_by' => ['Q', 17, 18]],
            'PROJ' => ['prepared_by' => ['A', 3, 4], 'approved_by' => ['N', 2, 3], 'certified_correct_by' => ['A', 11, 12], 'certified_payment_by' => ['P', 17, 18]],
            'BUSTYPE' => ['prepared_by' => ['A', 3, 4], 'approved_by' => ['S', 2, 3], 'certified_correct_by' => ['A', 10, 11], 'certified_payment_by' => ['Q', 16, 17]],
            'YEARBOOK' => ['prepared_by' => ['A', 3, 4], 'approved_by' => ['M', 2, 3], 'certified_correct_by' => ['A', 10, 11], 'certified_payment_by' => ['O', 16, 17]],
            'SUPPORT SERVICES' => ['prepared_by' => ['A', 2, 3], 'approved_by' => ['N', 1, 2], 'certified_correct_by' => ['A', 7, 8], 'certified_payment_by' => ['O', 13, 14]],
        ];

        foreach ($positions[$code] as $role => [$column, $nameOffset, $designationOffset]) {
            $sheet->setCellValue($column.($footer + $nameOffset), $signatories[$role]['name'] ?? '');
            $sheet->setCellValue($column.($footer + $designationOffset), $signatories[$role]['designation'] ?? '');
        }

        $totals = $this->batchTotals($batch->lines);
        foreach ($this->recapCells($code) as $cell => $metric) {
            [$column, $offset] = explode(':', $cell);
            $sheet->setCellValue($column.($footer + (int) $offset), $totals[$metric]);
        }
    }

    private function recapCells(string $code): array
    {
        return match ($code) {
            'PT' => ['U:0' => 'net', 'K:1' => 'earned', 'L:2' => 'tax', 'L:5' => 'sss', 'L:6' => 'pagibig', 'L:7' => 'philhealth', 'L:8' => 'net', 'D:14' => 'earned', 'K:18' => 'earned', 'L:18' => 'earned'],
            'INC' => ['M:0' => 'earned', 'N:1' => 'tax', 'N:2' => 'sss', 'N:3' => 'nsca', 'N:4' => 'pagibig', 'N:5' => 'other', 'N:6' => 'philhealth', 'N:7' => 'net', 'F:12' => 'earned', 'M:17' => 'earned', 'N:17' => 'earned'],
            'MDS' => ['T:0' => 'net', 'L:1' => 'earned', 'M:2' => 'pagibig', 'M:3' => 'tax', 'M:4' => 'sss', 'M:7' => 'philhealth', 'M:8' => 'other', 'M:9' => 'net', 'F:14' => 'earned'],
            'PROJ' => ['T:0' => 'net', 'L:1' => 'earned', 'M:2' => 'tax', 'M:3' => 'sss', 'M:4' => 'project', 'M:5' => 'pagibig', 'M:6' => 'philhealth', 'M:7' => 'nsca', 'M:8' => 'net', 'F:14' => 'earned'],
            'BUSTYPE' => ['U:0' => 'net', 'K:1' => 'earned', 'L:2' => 'tax', 'L:3' => 'sss', 'L:5' => 'pagibig', 'L:6' => 'philhealth', 'L:7' => 'other', 'L:8' => 'net', 'F:13' => 'earned', 'K:18' => 'earned', 'L:18' => 'earned'],
            'YEARBOOK' => ['S:0' => 'net', 'K:1' => 'earned', 'L:2' => 'tax', 'L:3' => 'sss', 'L:5' => 'pagibig', 'L:6' => 'philhealth', 'L:7' => 'other', 'L:8' => 'net', 'F:13' => 'earned', 'K:18' => 'earned', 'L:18' => 'earned'],
            'SUPPORT SERVICES' => ['K:0' => 'earned', 'L:1' => 'tax', 'L:2' => 'sss', 'L:3' => 'nsca', 'L:4' => 'other', 'L:5' => 'net', 'F:10' => 'earned'],
        };
    }

    private function formatPresentationText(Worksheet $sheet, string $code, int $footer, string $lastColumn, int $blockStart = 1): void
    {
        $acknowledgementRow = $code === 'PT' ? $blockStart + 4 : ($code === 'SUPPORT SERVICES' ? 5 : 4);
        $acknowledgementLastColumn = $code === 'PT' ? 'T' : $lastColumn;
        $this->mergeCells($sheet, "A{$acknowledgementRow}:{$acknowledgementLastColumn}{$acknowledgementRow}");
        $sheet->getStyle("A{$acknowledgementRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $certifiedOffsets = ['PT' => 8, 'INC' => 6, 'MDS' => 9, 'PROJ' => 8, 'BUSTYPE' => 8, 'YEARBOOK' => 8, 'SUPPORT SERVICES' => 5];
        $fundOffsets = ['PT' => 14, 'INC' => 12, 'MDS' => 14, 'PROJ' => 14, 'BUSTYPE' => 13, 'YEARBOOK' => 13, 'SUPPORT SERVICES' => 10];
        $leftLast = $code === 'PT' ? 'E' : 'G';
        $fundLast = $code === 'PT' ? 'C' : 'E';
        $certifiedRow = $footer + $certifiedOffsets[$code];
        $fundRow = $footer + $fundOffsets[$code];
        $preparedLast = $code === 'PT' ? 'E' : 'G';
        $this->mergeCells($sheet, "A{$footer}:{$preparedLast}{$footer}");
        $this->mergeCells($sheet, "A{$certifiedRow}:{$leftLast}{$certifiedRow}");
        $this->mergeCells($sheet, "A{$fundRow}:{$fundLast}{$fundRow}");
        foreach (["A{$footer}", "A{$certifiedRow}", "A{$fundRow}"] as $cell) {
            $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        }

        $accountColumn = $code === 'PT' ? 'F' : 'H';
        for ($row = $footer; $row <= $sheet->getHighestDataRow(); $row++) {
            $cell = $sheet->getCell($accountColumn.$row);
            $value = $cell->getValue();
            if (is_string($value) && preg_match('/^\d{8}\s+\d{2}$/', trim($value))) {
                $cell->setValue(preg_replace('/\s+/', "\u{00A0}", trim($value)));
            }
        }
    }

    private function batchTotals(Collection $lines): array
    {
        return [
            'earned' => (float) $lines->sum('earned_for_period'),
            'tax' => (float) $lines->sum('tax_amount'),
            'sss' => (float) $lines->sum('sss'),
            'philhealth' => (float) $lines->sum('philhealth'),
            'pagibig' => (float) $lines->sum('pagibig'),
            'nsca' => (float) $lines->sum('nsca_mpc'),
            'project' => (float) $lines->sum('project_deduction'),
            'other' => (float) $lines->sum(fn (PayrollLine $line) => (float) $line->project_deduction + (float) $line->graduate_school_deduction + (float) $line->other_deductions),
            'net' => (float) $lines->sum('net_amount_received'),
        ];
    }

    private function taxSplit(PayrollLine $line): array
    {
        $split = [2 => 0.0, 3 => 0.0, 5 => 0.0, 10 => 0.0];
        $percentage = (int) round(((float) data_get($line->computed_columns, 'tax_rate', 0)) * 100);
        if (isset($split[$percentage])) {
            $split[$percentage] = (float) $line->tax_amount;
        } elseif ((float) $line->tax_amount !== 0.0) {
            $split[3] = (float) $line->tax_amount;
        }

        return $split;
    }

    private function totalColumns(string $code): array
    {
        return match ($code) {
            'PT' => range('K', 'U'),
            'INC' => range('J', 'U'),
            'MDS', 'PROJ' => range('J', 'T'),
            'BUSTYPE' => range('J', 'U'),
            'YEARBOOK' => range('J', 'S'),
            'SUPPORT SERVICES' => range('J', 'R'),
        };
    }

    private function lastColumn(string $code): string
    {
        return $code === 'PT' ? 'V' : self::LAYOUTS[$code]['last'];
    }

    private function periodText(PayrollBatch $batch, bool $prefix): string
    {
        $from = $batch->period->date_from;
        $to = $batch->period->date_to;
        $period = $from->format('F j').'-'.($from->month === $to->month ? $to->format('j, Y') : $to->format('F j, Y'));

        return ($prefix ? 'For the period ' : '').$period;
    }

    private function configurePage(Worksheet $sheet): void
    {
        $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0)->setPaperSize(PageSetup::PAPERSIZE_LETTER);
        $sheet->setShowGridlines(false);
    }

    private function clearRow(Worksheet $sheet, int $row, string $lastColumn): void
    {
        $last = Coordinate::columnIndexFromString($lastColumn);
        for ($column = 1; $column <= $last; $column++) {
            $sheet->getCell([$column, $row])->setValue(null);
        }
    }

    private function copyRow(Worksheet $source, int $sourceRow, Worksheet $target, int $targetRow): void
    {
        $last = Coordinate::columnIndexFromString($source->getHighestDataColumn());
        for ($column = 1; $column <= $last; $column++) {
            $from = $source->getCell([$column, $sourceRow]);
            $to = $target->getCell([$column, $targetRow]);
            $to->setValue($from->getValue());
            $to->setXfIndex($from->getXfIndex());
        }

        $fromDimension = $source->getRowDimension($sourceRow);
        $toDimension = $target->getRowDimension($targetRow);
        $toDimension->setRowHeight($fromDimension->getRowHeight());
        $toDimension->setVisible($fromDimension->getVisible());
        $toDimension->setOutlineLevel($fromDimension->getOutlineLevel());
        $toDimension->setCollapsed($fromDimension->getCollapsed());
    }

    private function copyMerges(Worksheet $source, Worksheet $target, int $firstRow, int $lastRow, int $offset): void
    {
        foreach (array_values($source->getMergeCells()) as $range) {
            [$start, $end] = Coordinate::rangeBoundaries($range);
            if ($start[1] < $firstRow || $end[1] > $lastRow) {
                continue;
            }

            $target->mergeCells(
                Coordinate::stringFromColumnIndex($start[0]).($start[1] + $offset).':'.
                Coordinate::stringFromColumnIndex($end[0]).($end[1] + $offset)
            );
        }
    }

    private function mergeCells(Worksheet $sheet, string $range): void
    {
        [$start, $end] = Coordinate::rangeBoundaries($range);

        foreach (array_values($sheet->getMergeCells()) as $existing) {
            [$existingStart, $existingEnd] = Coordinate::rangeBoundaries($existing);
            $overlaps = $start[0] <= $existingEnd[0]
                && $end[0] >= $existingStart[0]
                && $start[1] <= $existingEnd[1]
                && $end[1] >= $existingStart[1];

            if ($overlaps) {
                $sheet->unmergeCells($existing);
            }
        }

        $sheet->mergeCells($range);
    }
}
