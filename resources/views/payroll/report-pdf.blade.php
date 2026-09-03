@php
    $money = fn ($value) => $value === null || (float) $value == 0.0 ? '' : number_format((float) $value, 2);
    $number = fn ($value) => $value === null || (float) $value == 0.0 ? '' : rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
    $periodLabel = $batch->period->date_from->format('F d') . ' - ' . $batch->period->date_to->format('F d, Y');
    $fund = $batch->fundCluster->fund_source_name;
    $sign = fn ($role) => $signatories[$role] ?? ['name' => null, 'designation' => null, 'label' => ''];
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 8mm 7mm; size: legal landscape; }
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #000; font-size: 8px; margin: 0; }
        .report { width: 100%; }
        .header { position: relative; min-height: 62px; text-align: center; }
        .header img { position: absolute; left: 20px; top: 0; width: 54px; height: 54px; object-fit: contain; }
        .header h1 { margin: 0; font-size: 13px; font-weight: 700; letter-spacing: .02em; }
        .header h2 { margin: 2px 0 0; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .header p { margin: 2px 0; font-size: 8px; }
        .page-no { position: absolute; right: 5px; top: 2px; font-size: 8px; }
        .page-no:after { content: counter(page) " OF " counter(pages); }
        .meta { width: 100%; border-collapse: collapse; margin: 2px 0 4px; }
        .meta td { border: 1px solid #000; padding: 2px 4px; vertical-align: middle; }
        .meta .label { width: 11%; font-weight: 700; background: #f2f2f2; }
        table.payroll { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.payroll th, table.payroll td { border: 1px solid #000; padding: 2px 3px; line-height: 1.15; vertical-align: middle; }
        table.payroll th { font-size: 7px; text-transform: uppercase; text-align: center; font-weight: 700; background: #f5f5f5; }
        table.payroll td { font-size: 7px; }
        thead { display: table-header-group; }
        tfoot { display: table-row-group; }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: 700; }
        .name { font-weight: 700; }
        .w-no { width: 3%; }
        .w-name { width: 13%; }
        .w-desig { width: 10%; }
        .w-money { width: 5.6%; }
        .w-small { width: 4.3%; }
        .w-remarks { width: 7%; }
        .summary { width: 100%; border-collapse: collapse; margin-top: 5px; page-break-inside: avoid; }
        .summary td { border: 1px solid #000; padding: 3px 5px; font-size: 8px; }
        .summary .amount { width: 13%; text-align: right; font-weight: 700; }
        .approval { margin-top: 8px; page-break-inside: avoid; }
        .approval table { width: 100%; border-collapse: collapse; }
        .approval td { width: 25%; padding: 14px 8px 0; text-align: center; vertical-align: bottom; }
        .signature-name { display: block; min-height: 15px; border-bottom: 1px solid #000; font-weight: 700; text-transform: uppercase; }
        .signature-title { display: block; min-height: 12px; padding-top: 2px; font-size: 7px; }
        .certification { margin-top: 12px; font-size: 8px; text-align: center; page-break-inside: avoid; }
        .certification .line { margin-top: 14px; display: inline-block; min-width: 310px; border-bottom: 1px solid #000; font-weight: 700; text-transform: uppercase; }
    </style>
</head>
<body>
<div class="report">
    <div class="header">
        @if(file_exists($logoPath))
            <img src="{{ $logoPath }}" alt="CPSU Seal">
        @endif
        <div class="page-no">PAGE </div>
        <h1>CENTRAL PHILIPPINES STATE UNIVERSITY</h1>
        <p>Kabankalan City, Negros Occidental</p>
        <h2>{{ $batch->template->name }}</h2>
        <p>{{ $batch->campus->name }} | {{ $periodLabel }} | {{ $fund }}</p>
    </div>

    <table class="meta">
        <tr>
            <td class="label">Payroll No.</td>
            <td>{{ $batch->batch_no }}</td>
            <td class="label">Template</td>
            <td>{{ $batch->template->code }}</td>
            <td class="label">Fund</td>
            <td>{{ $fund }}</td>
        </tr>
    </table>

    <table class="payroll">
        <thead>
            <tr>
                <th class="w-no">No.</th>
                <th class="w-name">Name</th>
                <th class="w-desig">Designation</th>
                <th class="w-money">Monthly Salary</th>
                <th class="w-small">Days Rendered</th>
                <th class="w-money">Gross Income</th>
                <th class="w-small">Late Min</th>
                <th class="w-money">Late</th>
                <th class="w-money">Undertime</th>
                <th class="w-money">Absent</th>
                <th class="w-money">Earned</th>
                <th class="w-money">Tax</th>
                <th class="w-money">SSS</th>
                <th class="w-money">PhilHealth</th>
                <th class="w-money">Pag-IBIG</th>
                <th class="w-money">Project</th>
                <th class="w-money">Grad School</th>
                <th class="w-money">NSCA MPC</th>
                <th class="w-money">Other</th>
                <th class="w-money">Total Deduction</th>
                <th class="w-money">Net Amount Received</th>
                <th class="w-remarks">Remarks</th>
            </tr>
        </thead>
        <tbody>
            @foreach($batch->lines as $line)
                <tr>
                    <td class="center">{{ $line->line_no }}</td>
                    <td class="name">{{ $line->employee_name }}</td>
                    <td>{{ $line->designation }}</td>
                    <td class="right">{{ $money($line->monthly_salary) }}</td>
                    <td class="center">{{ $number($line->rendered_days) }}</td>
                    <td class="right">{{ $money($line->gross_income) }}</td>
                    <td class="center">{{ $line->late_minutes ?: '' }}</td>
                    <td class="right">{{ $money($line->late_deduction) }}</td>
                    <td class="right">{{ $money($line->undertime_deduction) }}</td>
                    <td class="right">{{ $money($line->absent_deduction) }}</td>
                    <td class="right">{{ $money($line->earned_for_period) }}</td>
                    <td class="right">{{ $money($line->tax_amount) }}</td>
                    <td class="right">{{ $money($line->sss) }}</td>
                    <td class="right">{{ $money($line->philhealth) }}</td>
                    <td class="right">{{ $money($line->pagibig) }}</td>
                    <td class="right">{{ $money($line->project_deduction) }}</td>
                    <td class="right">{{ $money($line->graduate_school_deduction) }}</td>
                    <td class="right">{{ $money($line->nsca_mpc) }}</td>
                    <td class="right">{{ $money($line->other_deductions) }}</td>
                    <td class="right">{{ $money($line->total_deduction) }}</td>
                    <td class="right bold">{{ $money($line->net_amount_received) }}</td>
                    <td>{{ $line->remarks }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5" class="right bold">TOTAL</td>
                <td class="right bold">{{ $money($batch->lines->sum('gross_income')) }}</td>
                <td class="center bold">{{ $batch->lines->sum('late_minutes') ?: '' }}</td>
                <td class="right bold">{{ $money($batch->lines->sum('late_deduction')) }}</td>
                <td class="right bold">{{ $money($batch->lines->sum('undertime_deduction')) }}</td>
                <td class="right bold">{{ $money($batch->lines->sum('absent_deduction')) }}</td>
                <td class="right bold">{{ $money($batch->lines->sum('earned_for_period')) }}</td>
                <td class="right bold">{{ $money($batch->lines->sum('tax_amount')) }}</td>
                <td class="right bold">{{ $money($batch->lines->sum('sss')) }}</td>
                <td class="right bold">{{ $money($batch->lines->sum('philhealth')) }}</td>
                <td class="right bold">{{ $money($batch->lines->sum('pagibig')) }}</td>
                <td class="right bold">{{ $money($batch->lines->sum('project_deduction')) }}</td>
                <td class="right bold">{{ $money($batch->lines->sum('graduate_school_deduction')) }}</td>
                <td class="right bold">{{ $money($batch->lines->sum('nsca_mpc')) }}</td>
                <td class="right bold">{{ $money($batch->lines->sum('other_deductions')) }}</td>
                <td class="right bold">{{ $money($batch->lines->sum('total_deduction')) }}</td>
                <td class="right bold">{{ $money($batch->lines->sum('net_amount_received')) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <table class="summary">
        <tr>
            <td>Due to Pag-IBIG</td>
            <td class="amount">{{ $money($batch->lines->sum('pagibig')) }}</td>
            <td>Other Payables (SSS)</td>
            <td class="amount">{{ $money($batch->lines->sum('sss')) }}</td>
            <td>Due to PhilHealth</td>
            <td class="amount">{{ $money($batch->lines->sum('philhealth')) }}</td>
            <td>Net Amount for Payment</td>
            <td class="amount">{{ $money($batch->lines->sum('net_amount_received')) }}</td>
        </tr>
    </table>

    <div class="approval">
        <table>
            <tr>
                @foreach(['prepared_by', 'certified_correct_by', 'approved_by', 'certified_payment_by'] as $role)
                    @php($person = $sign($role))
                    <td>
                        <div>{{ $person['label'] }}</div>
                        <span class="signature-name">{!! $person['name'] ? e($person['name']) : '&nbsp;' !!}</span>
                        <span class="signature-title">{!! $person['designation'] ? e($person['designation']) : '&nbsp;' !!}</span>
                    </td>
                @endforeach
            </tr>
        </table>
    </div>

    <div class="certification">
        CERTIFIED: That each employee whose name appears above has been paid the amount indicated through direct credit to their respective accounts.
        <br>
        @php($payment = $sign('certified_payment_by'))
        <span class="line">{!! $payment['name'] ? e($payment['name']) : '&nbsp;' !!}</span>
        <div>{!! $payment['designation'] ? e($payment['designation']) : '&nbsp;' !!}</div>
    </div>
</div>
</body>
</html>
