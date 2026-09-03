@php
    $sheetColumns = [
        ['A', 'No.'], ['B', 'Employee'], ['C', 'Designation'], ['D', 'Fund'],
        ['E', 'Monthly Salary'], ['F', 'Days'], ['G', 'Gross'], ['H', 'Late Min'],
        ['I', 'Late'], ['J', 'Undertime'], ['K', 'Absent'], ['L', 'Earned'],
        ['M', 'Tax'], ['N', 'SSS'], ['O', 'PhilHealth'], ['P', 'Pag-IBIG'],
        ['Q', 'Other'], ['R', 'Total Ded.'], ['S', 'Net Pay'], ['T', 'Remarks'],
    ];
    $firstDataRow = 2;
    $lastDataRow = max($firstDataRow, $lines->count() + 1);
@endphp

<div class="spreadsheet-viewport" data-sheet-viewport>
    <table class="spreadsheet-grid" aria-label="Automated payroll worksheet">
        <colgroup>
            <col class="sheet-col-row">
            @foreach($sheetColumns as [$letter, $label])
                <col class="sheet-col-{{ strtolower($letter) }}">
            @endforeach
        </colgroup>
        <thead>
            <tr class="sheet-groups">
                <th class="sheet-corner" rowspan="3" aria-label="Row numbers"></th>
                <th colspan="4">Employee Information</th>
                <th colspan="8">Salary &amp; Attendance</th>
                <th colspan="6">Deductions</th>
                <th colspan="2">Payroll Result</th>
            </tr>
            <tr class="sheet-letters" aria-hidden="true">
                @foreach($sheetColumns as [$letter, $label])
                    <th>{{ $letter }}</th>
                @endforeach
            </tr>
            <tr class="sheet-headings">
                @foreach($sheetColumns as [$letter, $label])
                    <th @class(['num' => in_array($letter, range('E', 'S'), true)])>{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
        @forelse($lines as $line)
            @php
                $sheetRow = $loop->iteration + 1;
                $needsAttendanceReview = isset($attendanceReviewLineIds)
                    ? $attendanceReviewLineIds->contains($line->id)
                    : ($line->missing_log_status !== 'No issue' && $line->appeal_status !== 'approved');
                $reviewReason = $needsAttendanceReview ? trim(str_replace('Needs HR Review:', '', $line->missing_log_status)) : '';
                $reviewItems = collect($line->computed_columns['attendance_review_items'] ?? [])
                    ->filter(fn ($item) => is_array($item))
                    ->values()
                    ->all();
                $workingDays = max(1, (float) ($batch->template->working_days ?? 22));
                $cell = fn (string $column) => $column.$sheetRow;
            @endphp
            <tr data-line-row
                data-sheet-row="{{ $sheetRow }}"
                data-line-search="{{ strtolower($line->line_no.' '.$line->employee_name.' '.$line->employee_no.' '.$line->designation.' '.$line->fund_source.' '.$line->remarks.' '.$reviewReason.($needsAttendanceReview ? ' attendance review' : '')) }}"
                @class(['line-needs-review' => $needsAttendanceReview])>
                <th class="sheet-row-number" scope="row">{{ $sheetRow }}</th>
                <td data-sheet-cell="{{ $cell('A') }}" data-value="{{ $line->line_no }}">{{ $line->line_no }}</td>
                <td data-sheet-cell="{{ $cell('B') }}" data-value="{{ $line->employee_name }}">
                    <div class="payroll-line-employee">
                        <div>
                            <strong>{{ $line->employee_name }}</strong>
                            @if($line->employee_no)
                                <span>{{ $line->employee_no }}</span>
                            @endif
                        </div>
                        @if($needsAttendanceReview)
                            <button class="sheet-review-btn" type="button"
                                data-review-modal-trigger
                                data-review-employee="{{ $line->employee_name }}"
                                data-review-employee-no="{{ $line->employee_no }}"
                                data-review-reason="{{ $reviewReason }}"
                                data-review-items="{{ e(json_encode($reviewItems, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES)) }}"
                                data-review-late-minutes="{{ number_format($line->late_minutes) }}"
                                data-review-url="{{ route('payroll.lines.resolve-attendance', [$batch, $line]) }}"
                                title="Open DTR review for {{ $line->employee_name }}">
                                Review DTR
                            </button>
                        @endif
                    </div>
                </td>
                <td data-sheet-cell="{{ $cell('C') }}" data-value="{{ $line->designation }}">{{ $line->designation }}</td>
                <td data-sheet-cell="{{ $cell('D') }}" data-value="{{ $line->fund_source }}">{{ $line->fund_source }}</td>
                <td class="num" data-sheet-cell="{{ $cell('E') }}" data-value="{{ $line->monthly_salary }}" data-formula="HRIS employee salary profile">{{ number_format($line->monthly_salary, 2) }}</td>
                <td class="num" data-sheet-cell="{{ $cell('F') }}" data-value="{{ $line->rendered_days }}" data-formula="HRIS DTR → rendered days">{{ number_format($line->rendered_days, 2) }}</td>
                <td class="num sheet-calculated" data-sheet-cell="{{ $cell('G') }}" data-value="{{ $line->gross_income }}" data-formula="={{ $cell('E') }}/{{ number_format($workingDays, 2, '.', '') }}*{{ $cell('F') }}">{{ number_format($line->gross_income, 2) }}</td>
                <td class="num" data-sheet-cell="{{ $cell('H') }}" data-value="{{ $line->late_minutes }}" data-formula="HRIS DTR late minutes">{{ number_format($line->late_minutes) }}</td>
                <td class="num sheet-calculated" data-sheet-cell="{{ $cell('I') }}" data-value="{{ $line->late_deduction }}" data-formula="Payroll engine → late deduction">{{ number_format($line->late_deduction, 2) }}</td>
                <td class="num sheet-calculated" data-sheet-cell="{{ $cell('J') }}" data-value="{{ $line->undertime_deduction }}" data-formula="Payroll engine → undertime deduction">{{ number_format($line->undertime_deduction, 2) }}</td>
                <td class="num sheet-calculated" data-sheet-cell="{{ $cell('K') }}" data-value="{{ $line->absent_deduction }}" data-formula="Payroll engine → absence deduction">{{ number_format($line->absent_deduction, 2) }}</td>
                <td class="num sheet-calculated" data-sheet-cell="{{ $cell('L') }}" data-value="{{ $line->earned_for_period }}" data-formula="={{ $cell('G') }}-SUM({{ $cell('I') }}:{{ $cell('K') }})">{{ number_format($line->earned_for_period, 2) }}</td>
                <td class="num sheet-calculated" data-sheet-cell="{{ $cell('M') }}" data-value="{{ $line->tax_amount }}" data-formula="Tax rules → withholding tax">{{ number_format($line->tax_amount, 2) }}</td>
                <td class="num sheet-calculated" data-sheet-cell="{{ $cell('N') }}" data-value="{{ $line->sss }}" data-formula="Contribution rules → SSS / GSIS">{{ number_format($line->sss, 2) }}</td>
                <td class="num sheet-calculated" data-sheet-cell="{{ $cell('O') }}" data-value="{{ $line->philhealth }}" data-formula="Contribution rules → PhilHealth">{{ number_format($line->philhealth, 2) }}</td>
                <td class="num sheet-calculated" data-sheet-cell="{{ $cell('P') }}" data-value="{{ $line->pagibig }}" data-formula="Contribution rules → Pag-IBIG">{{ number_format($line->pagibig, 2) }}</td>
                <td class="num" data-sheet-cell="{{ $cell('Q') }}" data-value="{{ $line->other_deductions }}">{{ number_format($line->other_deductions, 2) }}</td>
                <td class="num sheet-calculated" data-sheet-cell="{{ $cell('R') }}" data-value="{{ $line->total_deduction }}" data-formula="=SUM({{ $cell('M') }}:{{ $cell('Q') }})">{{ number_format($line->total_deduction, 2) }}</td>
                <td class="num sheet-calculated sheet-net" data-sheet-cell="{{ $cell('S') }}" data-value="{{ $line->net_amount_received }}" data-formula="={{ $cell('L') }}-{{ $cell('R') }}"><strong>{{ number_format($line->net_amount_received, 2) }}</strong></td>
                <td data-sheet-cell="{{ $cell('T') }}" data-value="{{ $line->remarks }}">{{ $line->remarks }}</td>
            </tr>
        @empty
            <tr><th class="sheet-row-number" scope="row">2</th><td colspan="20"><div class="table-empty">No payroll lines available.</div></td></tr>
        @endforelse
        </tbody>
        @if($lines->isNotEmpty())
            @php $totalRow = $lines->count() + 2; @endphp
            <tfoot>
                <tr class="sheet-total-row" data-sheet-row="{{ $totalRow }}">
                    <th class="sheet-row-number" scope="row">{{ $totalRow }}</th>
                    <td data-sheet-cell="A{{ $totalRow }}" data-value="TOTAL"><strong>TOTAL</strong></td>
                    <td data-sheet-cell="B{{ $totalRow }}" data-value="{{ $batch->total_employees }} employees" colspan="5">{{ number_format($batch->total_employees) }} employees · automated payroll totals</td>
                    <td class="num" data-sheet-cell="G{{ $totalRow }}" data-value="{{ $batch->total_gross }}" data-formula="=SUM(G{{ $firstDataRow }}:G{{ $lastDataRow }})"><strong>{{ number_format($batch->total_gross, 2) }}</strong></td>
                    <td data-sheet-cell="H{{ $totalRow }}"></td>
                    <td data-sheet-cell="I{{ $totalRow }}"></td>
                    <td data-sheet-cell="J{{ $totalRow }}"></td>
                    <td data-sheet-cell="K{{ $totalRow }}"></td>
                    <td data-sheet-cell="L{{ $totalRow }}"></td>
                    <td data-sheet-cell="M{{ $totalRow }}"></td>
                    <td data-sheet-cell="N{{ $totalRow }}"></td>
                    <td data-sheet-cell="O{{ $totalRow }}"></td>
                    <td data-sheet-cell="P{{ $totalRow }}"></td>
                    <td data-sheet-cell="Q{{ $totalRow }}"></td>
                    <td class="num" data-sheet-cell="R{{ $totalRow }}" data-value="{{ $batch->total_deductions }}" data-formula="=SUM(R{{ $firstDataRow }}:R{{ $lastDataRow }})"><strong>{{ number_format($batch->total_deductions, 2) }}</strong></td>
                    <td class="num sheet-net" data-sheet-cell="S{{ $totalRow }}" data-value="{{ $batch->total_net }}" data-formula="=SUM(S{{ $firstDataRow }}:S{{ $lastDataRow }})"><strong>{{ number_format($batch->total_net, 2) }}</strong></td>
                    <td data-sheet-cell="T{{ $totalRow }}"></td>
                </tr>
            </tfoot>
        @endif
    </table>
</div>
