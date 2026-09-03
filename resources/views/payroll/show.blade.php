@php
    $tardinessSync = $batch->snapshot['tardiness_sync'] ?? null;
    $tardinessSyncFailed = is_array($tardinessSync) && ($tardinessSync['status'] ?? null) !== 'connected';
    $legacyApiSnapshot = $tardinessSyncFailed && (str_contains((string) ($tardinessSync['message'] ?? ''), 'HTTP')
        || in_array($tardinessSync['status'] ?? null, ['api error', 'invalid token', 'timeout'], true));
    $tardinessSyncMessage = $legacyApiSnapshot
        ? 'This draft contains an old API sync failure. Refresh attendance to read its DTR data directly from the HRIS database.'
        : ($tardinessSync['message'] ?? 'Payroll needs a successful direct HRIS attendance database read before submission.');
    // Old batches can still contain failure text created by the retired API integration.
    $isLegacySyncFailureReview = fn ($line) => str_contains($line->missing_log_status, 'HRIS returned HTTP')
        || str_contains($line->missing_log_status, 'HRIS tardiness sync failed')
        || str_contains($line->missing_log_status, 'HRIS API');
    $attendanceReviewLines = $batch->lines->filter(fn ($line) => $line->missing_log_status !== 'No issue'
        && $line->appeal_status !== 'approved'
        && ! ($tardinessSyncFailed && $isLegacySyncFailureReview($line)));
    $realMissingLogCount = $attendanceReviewLines->count();
    $negativeNetCount = $batch->lines->filter(fn ($line) => (float) $line->net_amount_received < 0)->count();
    $lateMinutesTotal = $batch->lines->sum('late_minutes');
    $submitBlocked = $attendanceReviewLines->isNotEmpty() || $tardinessSyncFailed;
    $employeeTypeLabel = $batch->snapshot['payroll_employee_type_label'] ?? 'Regular';
    $attendanceReviewLineIds = $attendanceReviewLines->pluck('id');
@endphp
<x-layouts.app page-title="Payroll {{ $batch->batch_no }}" page-subtitle="{{ $batch->campus->name }} - {{ $batch->period->name }}" content-class="content-wide">

    {{-- Funds on the left, section jumps on the right, sharing one row. --}}
    <div class="payroll-toolbar">
        @include('payroll.partials.fund-tabs')

        <nav class="payroll-jump">
            <button type="button" data-signatories-open>Signatories</button>
            <a href="#payroll-lines">Payroll Lines <strong>{{ $batch->total_employees }}</strong></a>
            <a href="#history">History</a>
        </nav>
    </div>

    {{-- One slim line rather than a row of cards; the totals are reference figures, not the page's subject. --}}
    <section class="payroll-metrics">
        <span class="metric"><small>Employees</small><strong>{{ number_format($batch->total_employees) }}</strong></span>
        <span class="metric"><small>Group</small><strong>{{ $employeeTypeLabel }}</strong></span>
        <span class="metric"><small>Gross</small><strong>{{ number_format($batch->total_gross, 2) }}</strong></span>
        <span class="metric"><small>Deductions</small><strong>{{ number_format($batch->total_deductions, 2) }}</strong></span>
        <span class="metric highlight"><small>Net</small><strong>{{ number_format($batch->total_net, 2) }}</strong></span>
        <span class="metric {{ $realMissingLogCount > 0 ? 'warn' : '' }}"><small>Open Reviews</small><strong>{{ $realMissingLogCount }}</strong></span>
        <span class="metric"><small>Late Minutes</small><strong>{{ number_format($lateMinutesTotal) }}</strong></span>
        @if($negativeNetCount > 0)
            <span class="metric danger"><small>Negative Net</small><strong>{{ $negativeNetCount }}</strong></span>
        @endif
    </section>

    <section id="payroll-lines" class="payroll-section spreadsheet-workbook" data-payroll-workbook>
        <div class="workbook-titlebar">
            <div class="workbook-file">
                <span class="workbook-app-mark">X</span>
                <div>
                    <strong>{{ $batch->batch_no }}.xlsx</strong>
                    <span>Saved automatically in CPSU Payroll</span>
                </div>
            </div>
            <div class="workbook-state"><span></span> Automated &amp; up to date</div>
        </div>

        <div class="workbook-tabs" role="tablist" aria-label="Payroll workbook tools">
            <button class="active" type="button" role="tab" aria-selected="true">Home</button>
            <button type="button" role="tab" aria-selected="false" data-sheet-focus-search>Find</button>
            <button type="button" role="tab" aria-selected="false" data-signatories-open>Signatories</button>
            <a href="#history" role="tab">Review History</a>
        </div>

        <div class="workbook-ribbon">
            <div class="ribbon-group ribbon-clipboard">
                <button type="button" data-sheet-copy title="Copy selected cell"><span class="ribbon-icon">⎘</span><strong>Copy</strong></button>
                <span class="ribbon-group-label">Clipboard</span>
            </div>
            <div class="ribbon-group ribbon-data">
                <div class="ribbon-control">
                    <span>Payroll period</span>
                    <strong>{{ $batch->period->date_from->format('M d') }} – {{ $batch->period->date_to->format('M d, Y') }}</strong>
                </div>
                <div class="ribbon-control">
                    <span>Employee group</span>
                    <strong>{{ $employeeTypeLabel }}</strong>
                </div>
                <span class="ribbon-group-label">Workbook Data</span>
            </div>
            <div class="ribbon-group ribbon-automation">
                <div class="automation-chip"><span class="automation-check">✓</span><div><strong>HRIS-linked</strong><small>Attendance &amp; employee data</small></div></div>
                <div class="automation-chip"><span class="automation-check">ƒx</span><div><strong>Auto-calculated</strong><small>Salary, deductions &amp; net</small></div></div>
                <span class="ribbon-group-label">Automation</span>
            </div>
            <div class="ribbon-group ribbon-find">
                <label class="workbook-search">
                    <x-icon name="search" />
                    <input type="search" placeholder="Find employee or value" data-line-search aria-label="Find employee or payroll value">
                </label>
                <span class="ribbon-group-label">Find</span>
            </div>
        </div>

        <div class="formula-bar">
            <output class="name-box" data-sheet-name aria-label="Selected cell">A2</output>
            <span class="formula-fx" aria-hidden="true">ƒx</span>
            <output class="formula-value" data-sheet-formula aria-live="polite">Select a cell to inspect its automated value or formula</output>
            <span class="formula-lock" title="Calculated payroll cells are protected">🔒 Protected</span>
        </div>

        @include('payroll.partials.lines-table', ['lines' => $batch->lines, 'attendanceReviewLineIds' => $attendanceReviewLineIds])
        <div class="table-empty workbook-empty" data-line-empty hidden>No payroll lines match the current search.</div>

        <div class="workbook-footer">
            <div class="sheet-tab-nav" aria-label="Workbook sheets">
                <button type="button" title="Add worksheet" disabled>+</button>
                <a class="active" href="#payroll-lines"><span class="sheet-tab-dot"></span>Payroll Register</a>
                <a href="#payroll-lines" data-sheet-focus-reviews>Attendance Review <strong>{{ $realMissingLogCount }}</strong></a>
                <a href="#history">Approval History</a>
            </div>
            <div class="workbook-statusbar">
                <span data-sheet-status>Ready</span>
                <span>{{ number_format($batch->total_employees) }} records</span>
                <span data-sheet-summary></span>
                <label>Zoom <input type="range" min="85" max="115" value="100" data-sheet-zoom><output data-sheet-zoom-value>100%</output></label>
            </div>
        </div>
    </section>

    <dialog id="line-review-modal" class="modal">
        <div class="modal-panel review-modal-panel">
            <div class="modal-header">
                <div>
                    <h2>Attendance Review</h2>
                    <div class="card-kicker" data-modal-review-employee></div>
                </div>
                <button class="ghost-btn icon-btn" type="button" data-modal-close aria-label="Close review details"><x-icon name="x" /></button>
            </div>
            <div class="modal-body">
                <div class="review-modal-summary">
                    <div><span>Employee No.</span><strong data-modal-review-employee-no></strong></div>
                    <div><span>Late Minutes</span><strong data-modal-review-late-minutes></strong></div>
                </div>
                <div class="review-modal-list">
                    <div class="subsection-title">Items To Review</div>
                    <div class="dtr-review-wrap">
                        <table class="dtr-review-table">
                            <thead>
                                <tr>
                                    <th rowspan="2">Day</th>
                                    <th colspan="2">AM</th>
                                    <th colspan="2">PM</th>
                                    <th colspan="2">Overtime</th>
                                    <th rowspan="2">Review</th>
                                </tr>
                                <tr>
                                    <th>In</th>
                                    <th>Out</th>
                                    <th>In</th>
                                    <th>Out</th>
                                    <th>In</th>
                                    <th>Out</th>
                                </tr>
                            </thead>
                            <tbody data-modal-review-items></tbody>
                        </table>
                    </div>
                    <div class="dtr-review-note" data-modal-review-note hidden>DTR time values were not captured for this payroll line. Regenerate this payroll after the HRIS database is available to display AM/PM time punches.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="ghost-btn" type="button" data-modal-close>Close</button>
                <form id="line-review-form" method="POST" data-modal-review-form data-confirm-review>
                    @csrf
                    <button class="primary-btn" type="submit"><x-icon name="check" /> Resolve Reviewed Dates</button>
                </form>
            </div>
        </div>
    </dialog>

    <dialog id="signatories-modal" class="modal">
        <div class="modal-panel signatories-modal-panel">
            <div class="modal-header">
                <div>
                    <h2>Report Signatories</h2>
                    <div class="card-kicker">Names and designations printed in the payroll PDF</div>
                </div>
                <button class="ghost-btn icon-btn" type="button" data-modal-close aria-label="Close signatories"><x-icon name="x" /></button>
            </div>
            <form method="POST" action="{{ route('payroll.signatories.update', $batch) }}">
                @csrf
                @method('PUT')
                <div class="modal-body">
                    <div class="form-grid">
                        @foreach($signatoryRoles as $role => $label)
                            <label class="field">
                                <span>{{ $label }}</span>
                                <select class="select" name="signatories[{{ $role }}]" data-employee-search required>
                                    <option value="">Select employee</option>
                                    @foreach($signatoryEmployees as $employee)
                                        <option value="{{ $employee->id }}" @selected((string) ($signatories[$role]['employee_id'] ?? '') === (string) $employee->id)>{{ $employee->full_name }}{{ $employee->designation ? ' - ' . $employee->designation : '' }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endforeach
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="ghost-btn" type="button" data-modal-close>Close</button>
                    <button class="primary-btn" type="submit"><x-icon name="check" /> Save Signatories</button>
                </div>
            </form>
        </div>
    </dialog>

    {{-- Batch identity, readiness, and the workflow buttons on one slim row. --}}
    <section class="payroll-command">
        <div class="command-main">
            <span class="badge {{ $batch->status === 'Approved for Printing' ? 'green' : ($batch->status === 'Returned for Correction' ? 'red' : 'gold') }}">{{ $batch->status }}</span>
            <strong class="command-fund">{{ $batch->fundCluster->fund_source_name }}</strong>
            <span class="command-meta">{{ $employeeTypeLabel }} &middot; {{ $batch->template->code }} template &middot; {{ $batch->period->date_from->format('M d') }}-{{ $batch->period->date_to->format('M d, Y') }}</span>
            <span class="command-note {{ $submitBlocked ? 'blocked' : 'ready' }}">
                <strong>{{ $submitBlocked ? 'Action needed' : 'Ready to submit' }}</strong>
                <span>
                    @if($tardinessSyncFailed)
                        {{ $tardinessSyncMessage }}
                    @elseif($attendanceReviewLines->isNotEmpty())
                        {{ $attendanceReviewLines->count() }} attendance review {{ $attendanceReviewLines->count() === 1 ? 'item' : 'items' }} open.
                    @else
                        Attendance review is clear.
                    @endif
                </span>
            </span>
        </div>
        <div class="command-actions">
            @if(in_array($batch->status, ['Draft','Returned for Correction'], true))
                <form method="POST" action="{{ route('payroll.submit', $batch) }}">
                    @csrf
                    <input type="hidden" name="remarks" value="Reviewed by campus payroll administrator and submitted for checking.">
                    <button class="primary-btn" type="submit" @disabled($submitBlocked) title="{{ $tardinessSyncFailed ? 'Regenerate after the HRIS attendance database is available' : ($attendanceReviewLines->isNotEmpty() ? 'Resolve HR attendance review issues before submission' : 'Submit payroll') }}"><x-icon name="open" /> Submit</button>
                </form>
            @endif
            @can('review-payroll')
                @if(in_array($batch->status, ['Submitted to University Payroll','Corrected and Resubmitted','Under University Payroll Review'], true))
                    <form method="POST" action="{{ route('payroll.approve', $batch) }}">@csrf<input type="hidden" name="remarks" value="Computation checked and approved for printing."><button class="primary-btn" type="submit"><x-icon name="check" /> Approve</button></form>
                    <form method="POST" action="{{ route('payroll.return', $batch) }}">@csrf<input type="hidden" name="remarks" value="Returned for correction. See review notes."><button class="danger-btn" type="submit"><x-icon name="return" /> Return</button></form>
                @endif
            @endcan
            @if($batch->status === 'Approved for Printing')
                <form method="POST" action="{{ route('payroll.print', $batch) }}">@csrf<input type="hidden" name="remarks" value="Final payroll printed."><button class="gold-btn" type="submit"><x-icon name="printer" /> Print Final</button></form>
            @endif
            @if(in_array($batch->status, ['Approved for Printing','Printed'], true))
                <a class="ghost-btn" href="{{ route('payroll.printable', $batch) }}"><x-icon name="printer" /> Printable PDF</a>
                <a class="ghost-btn" href="{{ route('payroll.export', $batch) }}"><x-icon name="download" /> Export Excel</a>
            @endif
            @if($tardinessSyncFailed)
                @if(in_array($batch->status, ['Draft', 'Returned for Correction'], true) && auth()->user()->canManagePayroll())
                    <form method="POST" action="{{ route('payroll.refresh-attendance', $batch) }}">
                        @csrf
                        <button class="primary-btn" type="submit"><x-icon name="refresh" /> Refresh Attendance</button>
                    </form>
                @endif
                @if(auth()->user()->canManageHris())
                    <a class="ghost-btn" href="{{ route('settings.hris') }}"><x-icon name="settings" /> HRIS Settings</a>
                @endif
            @endif
        </div>
    </section>

    <section id="history" class="card payroll-section">
        <div class="card-header"><div><h2>Approval History</h2><div class="card-kicker">Submission, review, return, approval, and print events</div></div></div>
        <div class="timeline">
        @forelse($batch->reviews as $review)
            <div class="timeline-item"><strong>{{ ucfirst($review->action) }}</strong> by {{ $review->reviewer?->name }} <span class="muted small">{{ $review->created_at->format('M d, Y h:i A') }}</span><br><span class="muted">{{ $review->remarks }}</span></div>
        @empty
            <div class="table-empty">No review activity yet.</div>
        @endforelse
        </div>
    </section>

    <script>
        (function () {
            const reviewModal = document.querySelector('#line-review-modal');
            const reviewForm = document.querySelector('[data-modal-review-form]');
            const reviewEmployee = document.querySelector('[data-modal-review-employee]');
            const reviewEmployeeNo = document.querySelector('[data-modal-review-employee-no]');
            const reviewLateMinutes = document.querySelector('[data-modal-review-late-minutes]');
            const reviewItems = document.querySelector('[data-modal-review-items]');
            const reviewNote = document.querySelector('[data-modal-review-note]');
            const signatoriesModal = document.querySelector('#signatories-modal');

            function openModal(modal) {
                if (modal?.showModal) {
                    modal.showModal();
                } else if (modal) {
                    modal.setAttribute('open', 'open');
                }
            }

            function fallbackItems(reason) {
                const grouped = {};
                let hasDatedIssue = false;

                (reason || 'For HR review')
                    .split(';')
                    .map(function (issue) {
                        return issue.trim();
                    })
                    .filter(Boolean)
                    .forEach(function (issue) {
                        const parentheticalDate = issue.match(/\(([^)]+)\)\s*$/);
                        const leadingDate = issue.match(/^([A-Z][a-z]{2}\s+\d{1,2})\s*:\s*(.+)$/);
                        const dateLabel = leadingDate ? leadingDate[1] : (parentheticalDate ? parentheticalDate[1] : 'Summary');
                        const cleanIssue = leadingDate
                            ? leadingDate[2].trim()
                            : issue.replace(/\s*\([^)]*\)\s*$/, '').trim();

                        if (dateLabel !== 'Summary') {
                            hasDatedIssue = true;
                        }

                        if (!grouped[dateLabel]) {
                            grouped[dateLabel] = {
                                date_label: dateLabel,
                                weekday: '',
                                summary: '',
                                issues: [],
                                times: {},
                            };
                        }

                        grouped[dateLabel].issues.push(cleanIssue || issue);
                    });

                return Object.values(grouped).filter(function (item) {
                    return !hasDatedIssue || item.date_label !== 'Summary';
                }).map(function (item) {
                    item.issues = Array.from(new Set(item.issues));
                    item.summary = item.issues.join(', ');
                    return item;
                });
            }

            function dtrCell(value, isMissing) {
                const cell = document.createElement('td');
                cell.textContent = value || '';
                if (isMissing && !value) {
                    cell.className = 'dtr-missing';
                }
                return cell;
            }

            function normalizedKey(key) {
                return String(key || '').toLowerCase().replace(/[^a-z0-9]/g, '');
            }

            function firstTimeValue(source, aliases) {
                if (!source || typeof source !== 'object') {
                    return null;
                }

                const targets = aliases.map(normalizedKey);

                for (const key in source) {
                    if (Object.prototype.hasOwnProperty.call(source, key) && targets.includes(normalizedKey(key))) {
                        return source[key];
                    }
                }

                return null;
            }

            function displayTime(value) {
                if (value === null || value === undefined || value === '') {
                    return '';
                }

                const raw = String(value).trim();
                const simple = raw.match(/^(\d{1,2}):(\d{1,2})(?::\d{1,2})?$/);

                if (simple) {
                    return simple[1].padStart(2, '0') + ':' + simple[2].padStart(2, '0');
                }

                return raw;
            }

            function reviewTimes(item) {
                const times = item.times && typeof item.times === 'object' ? item.times : {};

                return {
                    am_in: displayTime(firstTimeValue(times, ['am_in', 'am in', 'AM IN']) ?? firstTimeValue(item, ['am_in', 'am in', 'AM IN'])),
                    am_out: displayTime(firstTimeValue(times, ['am_out', 'am out', 'AM OUT']) ?? firstTimeValue(item, ['am_out', 'am out', 'AM OUT'])),
                    pm_in: displayTime(firstTimeValue(times, ['pm_in', 'pm in', 'PM IN']) ?? firstTimeValue(item, ['pm_in', 'pm in', 'PM IN'])),
                    pm_out: displayTime(firstTimeValue(times, ['pm_out', 'pm out', 'PM OUT']) ?? firstTimeValue(item, ['pm_out', 'pm out', 'PM OUT'])),
                    ot_in: displayTime(firstTimeValue(times, ['ot_in', 'ot in', 'OT IN']) ?? firstTimeValue(item, ['ot_in', 'ot in', 'OT IN'])),
                    ot_out: displayTime(firstTimeValue(times, ['ot_out', 'ot out', 'OT OUT']) ?? firstTimeValue(item, ['ot_out', 'ot out', 'OT OUT'])),
                };
            }

            /**
             * Every punch HRIS recorded for the date, including ones too far outside the
             * schedule to be used. Without this a blank cell is ambiguous: it could mean
             * no punch at all, or a punch that could not be matched to a half-day.
             */
            function punchEvidence(item) {
                const wrap = document.createElement('div');
                wrap.className = 'review-day-punches';

                const timeline = Array.isArray(item.timeline) ? item.timeline : [];

                if (timeline.length === 0) {
                    wrap.classList.add('is-empty');
                    wrap.textContent = hasTime(item)
                        ? 'Punch list unavailable for this date.'
                        : 'No DTR punch recorded for this date.';

                    return wrap;
                }

                const label = document.createElement('span');
                label.className = 'review-day-punches-label';
                label.textContent = 'HRIS punches';
                wrap.appendChild(label);

                timeline.forEach(function (entry) {
                    const chip = document.createElement('span');
                    chip.className = 'review-punch review-punch-' + String(entry.type || '').toLowerCase();
                    chip.textContent = displayTime(entry.time) + ' ' + (entry.type || '');
                    wrap.appendChild(chip);
                });

                return wrap;
            }

            function hasIssue(item, needle) {
                return (item.issues || []).some(function (issue) {
                    return String(issue).toLowerCase().includes(needle);
                });
            }

            function hasTime(item) {
                return Object.values(reviewTimes(item)).some(function (value) {
                    return value !== null && value !== undefined && value !== '';
                });
            }

            function reviewPayload(trigger) {
                try {
                    const items = JSON.parse(trigger.dataset.reviewItems || '[]');
                    return Array.isArray(items) && items.length > 0 ? items : fallbackItems(trigger.dataset.reviewReason);
                } catch (error) {
                    return fallbackItems(trigger.dataset.reviewReason);
                }
            }

            function hiddenInput(name, value) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value || '';
                input.setAttribute('form', 'line-review-form');
                return input;
            }

            function resolutionSelect(index) {
                const select = document.createElement('select');
                select.name = 'review_items[' + index + '][resolution]';
                select.required = true;
                select.setAttribute('form', 'line-review-form');

                [
                    ['', 'Select resolution'],
                    ['corrected_dtr', 'Corrected DTR'],
                    ['cto', 'CTO'],
                    ['leave', 'On Leave'],
                    ['vacation_leave', 'Vacation Leave'],
                    ['sick_leave', 'Sick Leave'],
                    ['emergency_leave', 'Emergency Leave'],
                    ['official_business', 'Official Business'],
                    ['absent', 'Absent'],
                    ['half_day', 'Half Day'],
                    ['undertime', 'Undertime'],
                    ['late', 'Late'],
                    ['holiday', 'Holiday / Suspension'],
                    ['suspension', 'Work Suspension'],
                    ['no_pay', 'No Pay'],
                    ['other', 'Other'],
                ].forEach(function (option) {
                    const optionNode = document.createElement('option');
                    optionNode.value = option[0];
                    optionNode.textContent = option[1];
                    select.appendChild(optionNode);
                });

                return select;
            }

            function remarksInput(index) {
                const textarea = document.createElement('textarea');
                textarea.name = 'review_items[' + index + '][remarks]';
                textarea.required = true;
                textarea.rows = 2;
                textarea.placeholder = 'Justification or remarks';
                textarea.setAttribute('form', 'line-review-form');

                return textarea;
            }

            function renderReviewItem(item, index) {
                const row = document.createElement('tr');
                const date = document.createElement('td');
                const review = document.createElement('td');
                const issues = document.createElement('div');
                const times = reviewTimes(item);
                const hasIssues = Array.isArray(item.issues) && item.issues.length > 0;

                date.className = 'review-day-date';
                review.className = 'dtr-review-issues';
                issues.className = 'review-day-issues';
                date.textContent = item.date_label && item.weekday
                    ? item.weekday + ', ' + item.date_label
                    : item.date_label || 'Summary';

                (hasIssues ? item.issues : []).forEach(function (issue) {
                    const chip = document.createElement('span');
                    chip.textContent = issue;
                    issues.appendChild(chip);
                });

                review.appendChild(issues);
                review.appendChild(punchEvidence(item));

                if (hasIssues) {
                    const fields = document.createElement('div');
                    fields.className = 'review-resolution-fields';
                    fields.append(
                        hiddenInput('review_items[' + index + '][date]', item.date || item.date_label || ''),
                        hiddenInput('review_items[' + index + '][date_label]', item.date_label || ''),
                        hiddenInput('review_items[' + index + '][summary]', item.summary || item.issues.join(', ')),
                        resolutionSelect(index),
                        remarksInput(index)
                    );
                    review.appendChild(fields);
                }

                // Flag only the punch that is actually absent. A captured time is shown
                // as-is, even when the other half of the same day is missing.
                const inMissing = hasIssue(item, 'missing time-in');
                const outMissing = hasIssue(item, 'missing time-out');

                row.append(
                    date,
                    dtrCell(times.am_in, inMissing && !times.am_in),
                    dtrCell(times.am_out, outMissing && !times.am_out),
                    dtrCell(times.pm_in, inMissing && !times.pm_in),
                    dtrCell(times.pm_out, outMissing && !times.pm_out),
                    dtrCell(times.ot_in, false),
                    dtrCell(times.ot_out, false),
                    review
                );

                return row;
            }

            document.querySelectorAll('[data-signatories-open]').forEach(function (trigger) {
                trigger.addEventListener('click', function () {
                    window.initializeSearchableSelects?.(signatoriesModal);
                    openModal(signatoriesModal);
                });
            });

            function openReviewModal(trigger) {
                const employee = trigger.dataset.reviewEmployee || 'Employee';
                const employeeNo = trigger.dataset.reviewEmployeeNo || 'Not set';
                const lateMinutes = trigger.dataset.reviewLateMinutes || '0';
                const items = reviewPayload(trigger);

                if (reviewEmployee) {
                    reviewEmployee.textContent = employee;
                }

                if (reviewEmployeeNo) {
                    reviewEmployeeNo.textContent = employeeNo;
                }

                if (reviewLateMinutes) {
                    reviewLateMinutes.textContent = lateMinutes;
                }

                if (reviewItems) {
                    reviewItems.replaceChildren();
                    items.forEach(function (item, index) {
                        reviewItems.appendChild(renderReviewItem(item, index));
                    });
                    window.initializeSearchableSelects?.(reviewItems);
                }

                if (reviewNote) {
                    reviewNote.hidden = items.some(function (item) {
                        return hasTime(item) || (Array.isArray(item.timeline) && item.timeline.length > 0);
                    });
                }

                if (reviewForm) {
                    reviewForm.action = trigger.dataset.reviewUrl || '';
                }

                openModal(reviewModal);
            }

            document.querySelectorAll('[data-review-modal-trigger]').forEach(function (trigger) {
                trigger.addEventListener('click', function () {
                    openReviewModal(trigger);
                });
            });

            document.querySelectorAll('[data-modal-close]').forEach(function (button) {
                button.addEventListener('click', function () {
                    button.closest('dialog')?.close();
                });
            });

            document.querySelectorAll('dialog.modal').forEach(function (modal) {
                modal.addEventListener('click', function (event) {
                    if (event.target === modal) {
                        modal.close();
                    }
                });
            });

            const lineSearch = document.querySelector('[data-line-search]');
            const lineRows = Array.from(document.querySelectorAll('[data-line-row]'));
            const lineEmpty = document.querySelector('[data-line-empty]');

            lineSearch?.addEventListener('input', function () {
                const query = lineSearch.value.trim().toLowerCase();
                let visibleCount = 0;

                lineRows.forEach(function (row) {
                    const visible = query === '' || (row.dataset.lineSearch || '').includes(query);
                    row.style.display = visible ? '' : 'none';
                    visibleCount += visible ? 1 : 0;
                });

                if (lineEmpty) {
                    lineEmpty.hidden = visibleCount > 0;
                }
            });

            const workbook = document.querySelector('[data-payroll-workbook]');
            const sheetViewport = workbook?.querySelector('[data-sheet-viewport]');
            const sheetCells = Array.from(workbook?.querySelectorAll('[data-sheet-cell]') || []);
            const sheetName = workbook?.querySelector('[data-sheet-name]');
            const sheetFormula = workbook?.querySelector('[data-sheet-formula]');
            const sheetStatus = workbook?.querySelector('[data-sheet-status]');
            const sheetSummary = workbook?.querySelector('[data-sheet-summary]');
            let selectedCell = null;
            let sheetPanPointer = null;
            let sheetPanStartX = 0;
            let sheetPanStartScroll = 0;
            let sheetPanMoved = false;
            let sheetPanCell = null;
            let ignoreSheetClick = false;

            function cellCoordinates(cell) {
                const match = String(cell?.dataset.sheetCell || '').match(/^([A-Z]+)(\d+)$/);
                return match ? { column: match[1], row: Number(match[2]) } : null;
            }

            function selectSheetCell(cell, options) {
                if (!cell || cell.closest('tr')?.style.display === 'none') {
                    return;
                }

                selectedCell?.classList.remove('is-selected');
                selectedCell?.setAttribute('tabindex', '-1');
                selectedCell = cell;
                selectedCell.classList.add('is-selected');
                selectedCell.setAttribute('tabindex', '0');

                const address = selectedCell.dataset.sheetCell || '';
                const formula = selectedCell.dataset.formula;
                const value = selectedCell.dataset.value ?? selectedCell.textContent.trim();
                const numericValue = Number(value);

                if (sheetName) {
                    sheetName.value = address;
                    sheetName.textContent = address;
                }
                if (sheetFormula) {
                    sheetFormula.value = formula || value || '—';
                    sheetFormula.textContent = formula || value || '—';
                }
                if (sheetStatus) {
                    sheetStatus.textContent = formula ? 'Calculated cell' : 'Ready';
                }
                if (sheetSummary) {
                    sheetSummary.textContent = Number.isFinite(numericValue) && value !== ''
                        ? 'Value: ' + numericValue.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                        : '';
                }

                if (options?.focus) {
                    selectedCell.focus({ preventScroll: true });
                }
                if (options?.scroll) {
                    selectedCell.scrollIntoView({ block: 'nearest', inline: 'nearest' });
                }
            }

            function adjacentCell(direction) {
                const current = cellCoordinates(selectedCell);
                if (!current) {
                    return null;
                }

                const row = selectedCell.closest('tr');
                const visibleRows = Array.from(workbook.querySelectorAll('[data-sheet-row]')).filter(function (candidate) {
                    return candidate.style.display !== 'none';
                });
                const rowIndex = visibleRows.indexOf(row);
                const rowCells = Array.from(row.querySelectorAll('[data-sheet-cell]'));
                const cellIndex = rowCells.indexOf(selectedCell);

                if (direction === 'left' || direction === 'right') {
                    return rowCells[cellIndex + (direction === 'right' ? 1 : -1)] || null;
                }

                const nextRow = visibleRows[rowIndex + (direction === 'down' ? 1 : -1)];
                return nextRow?.querySelector('[data-sheet-cell="' + current.column + nextRow.dataset.sheetRow + '"]') || null;
            }

            sheetCells.forEach(function (cell) {
                cell.setAttribute('tabindex', '-1');
                cell.addEventListener('click', function (event) {
                    if (ignoreSheetClick) {
                        event.preventDefault();
                        return;
                    }
                    if (event.target.closest('button, a, input, select, textarea')) {
                        return;
                    }
                    selectSheetCell(cell);
                });
            });

            sheetViewport?.addEventListener('pointerdown', function (event) {
                if (event.button !== 0 || event.target.closest('button, a, input, select, textarea')) {
                    return;
                }

                sheetPanPointer = event.pointerId;
                sheetPanStartX = event.clientX;
                sheetPanStartScroll = sheetViewport.scrollLeft;
                sheetPanMoved = false;
                sheetPanCell = event.target.closest('[data-sheet-cell]');
                sheetViewport.setPointerCapture?.(event.pointerId);
                sheetViewport.classList.add('is-panning');
                event.preventDefault();
            });

            sheetViewport?.addEventListener('pointermove', function (event) {
                if (sheetPanPointer !== event.pointerId) {
                    return;
                }

                const distance = event.clientX - sheetPanStartX;
                if (Math.abs(distance) > 4) {
                    sheetPanMoved = true;
                }

                if (sheetPanMoved) {
                    sheetViewport.scrollLeft = sheetPanStartScroll - distance;
                    event.preventDefault();
                }
            });

            function finishSheetPan(event) {
                if (sheetPanPointer !== event.pointerId) {
                    return;
                }

                if (sheetPanMoved) {
                    ignoreSheetClick = true;
                    window.setTimeout(function () {
                        ignoreSheetClick = false;
                    }, 0);
                    if (sheetStatus) {
                        sheetStatus.textContent = 'Ready · drag to view left and right';
                    }
                } else if (sheetPanCell) {
                    selectSheetCell(sheetPanCell);
                }

                sheetViewport.releasePointerCapture?.(event.pointerId);
                sheetViewport.classList.remove('is-panning');
                sheetPanPointer = null;
                sheetPanCell = null;
            }

            sheetViewport?.addEventListener('pointerup', finishSheetPan);
            sheetViewport?.addEventListener('pointercancel', finishSheetPan);

            workbook?.addEventListener('keydown', function (event) {
                if (!selectedCell || !['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Enter', 'Tab'].includes(event.key)) {
                    return;
                }
                if (event.target.matches('input, select, textarea, button, a')) {
                    return;
                }

                const direction = {
                    ArrowUp: 'up', ArrowDown: 'down', ArrowLeft: 'left', ArrowRight: 'right',
                    Enter: event.shiftKey ? 'up' : 'down', Tab: event.shiftKey ? 'left' : 'right',
                }[event.key];
                const nextCell = adjacentCell(direction);

                if (nextCell) {
                    event.preventDefault();
                    selectSheetCell(nextCell, { focus: true, scroll: true });
                }
            });

            workbook?.querySelector('[data-sheet-copy]')?.addEventListener('click', async function () {
                if (!selectedCell) {
                    return;
                }

                const copyValue = selectedCell.dataset.value ?? selectedCell.textContent.trim();
                try {
                    await navigator.clipboard.writeText(copyValue);
                    if (sheetStatus) {
                        sheetStatus.textContent = 'Copied ' + selectedCell.dataset.sheetCell;
                    }
                } catch (error) {
                    if (sheetStatus) {
                        sheetStatus.textContent = 'Select the value and press Ctrl+C';
                    }
                }
            });

            workbook?.querySelector('[data-sheet-focus-search]')?.addEventListener('click', function () {
                lineSearch?.focus();
            });

            workbook?.querySelector('[data-sheet-focus-reviews]')?.addEventListener('click', function (event) {
                event.preventDefault();
                if (lineSearch) {
                    lineSearch.value = 'review';
                    lineSearch.dispatchEvent(new Event('input'));
                    lineSearch.focus();
                }
            });

            const zoomControl = workbook?.querySelector('[data-sheet-zoom]');
            const zoomValue = workbook?.querySelector('[data-sheet-zoom-value]');
            zoomControl?.addEventListener('input', function () {
                const zoom = Number(zoomControl.value) / 100;
                workbook.style.setProperty('--sheet-zoom', zoom);
                if (zoomValue) {
                    zoomValue.value = zoomControl.value + '%';
                    zoomValue.textContent = zoomControl.value + '%';
                }
            });

            if (sheetCells.length > 0) {
                selectSheetCell(sheetCells[0]);
            }

            document.addEventListener('submit', function (event) {
                const form = event.target;

                if (!form.matches('[data-confirm-review]') || form.dataset.confirmed === '1') {
                    return;
                }

                event.preventDefault();

                if (!window.Swal) {
                    if (window.confirm('Resolve attendance review?')) {
                        form.dataset.confirmed = '1';
                        form.submit();
                    }

                    return;
                }

                window.Swal.fire({
                    title: 'Resolve attendance review?',
                    text: 'Confirm only after HR has reviewed the selected attendance issue.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Resolve',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#0B6E2E',
                    cancelButtonColor: '#667085',
                    reverseButtons: true,
                }).then(function (result) {
                    if (result.isConfirmed) {
                        form.dataset.confirmed = '1';
                        form.submit();
                    }
                });
            });
        })();
    </script>
</x-layouts.app>
