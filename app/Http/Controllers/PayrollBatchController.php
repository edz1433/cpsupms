<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePayrollBatchRequest;
use App\Models\Campus;
use App\Models\PayrollBatch;
use App\Models\PayrollLine;
use App\Services\AuditLogger;
use App\Services\HrisTardinessSyncService;
use App\Services\PayrollBatchService;
use App\Services\PayrollEmployeeTypeService;
use App\Services\PayrollExportService;
use App\Services\PayrollFundTypeService;
use App\Services\PayrollPeriodWindowService;
use App\Services\PayrollReviewService;
use App\Services\PayrollSignatoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class PayrollBatchController extends Controller
{
    public function index(Request $request, PayrollPeriodWindowService $periodWindows, PayrollFundTypeService $fundTypes, PayrollEmployeeTypeService $employeeTypes, PayrollSignatoryService $signatories)
    {
        $user = auth()->user();

        $campuses = Campus::query()
            ->when(! $user->isUniversityWide(), fn ($query) => $query->whereKey($user->campus_id))
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $selectedCampus = $user->isUniversityWide()
            ? $campuses->firstWhere('id', (int) $request->query('campus'))
            : $campuses->firstWhere('id', $user->campus_id);

        $statusRows = PayrollBatch::query()
            ->visibleTo($user)
            ->selectRaw('campus_id, status, count(*) as total')
            ->groupBy('campus_id', 'status')
            ->get();

        $needsAction = [PayrollBatch::DRAFT, PayrollBatch::RETURNED];

        $campusStats = $statusRows
            ->groupBy('campus_id')
            ->map(fn ($rows) => [
                'total' => (int) $rows->sum('total'),
                'needs_action' => (int) $rows->whereIn('status', $needsAction)->sum('total'),
            ]);

        $scopedRows = $selectedCampus
            ? $statusRows->where('campus_id', $selectedCampus->id)
            : $statusRows;

        $countByStatus = fn (array $statuses) => (int) $scopedRows->whereIn('status', $statuses)->sum('total');

        $batches = PayrollBatch::query()
            ->with(['campus', 'period', 'template', 'fundCluster'])
            ->visibleTo($user)
            ->when($selectedCampus, fn ($query) => $query->where('campus_id', $selectedCampus->id))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('payroll.index', [
            'batches' => $batches,
            'campuses' => $campuses,
            'campusStats' => $campusStats,
            'selectedCampus' => $selectedCampus,
            'isUniversityWide' => $user->isUniversityWide(),
            'allCampusTotal' => (int) $statusRows->sum('total'),
            'canManagePayroll' => $user->canManagePayroll(),
            // Powers the "Generate Payroll" modal, which replaces a trip to the create page.
            'selectedCampusId' => $selectedCampus?->id,
            'periods' => $periodWindows->query()->get(),
            'fundClusters' => $fundTypes->mainFundClusters(),
            'employeeTypes' => $employeeTypes->options(),
            'signatoryRoles' => PayrollSignatoryService::ROLES,
            'signatoryEmployees' => $signatories->employeeOptions($user),
            'defaultSignatories' => $signatories->defaultsForCreate($user),
            'summary' => [
                'total' => (int) $scopedRows->sum('total'),
                'draft' => $countByStatus([PayrollBatch::DRAFT]),
                'for_review' => $countByStatus([PayrollBatch::SUBMITTED, PayrollBatch::UNDER_REVIEW, PayrollBatch::RESUBMITTED]),
                'returned' => $countByStatus([PayrollBatch::RETURNED]),
                'cleared' => $countByStatus([PayrollBatch::APPROVED, PayrollBatch::PRINTED]),
                'net' => (float) PayrollBatch::query()
                    ->visibleTo($user)
                    ->when($selectedCampus, fn ($query) => $query->where('campus_id', $selectedCampus->id))
                    ->sum('total_net'),
            ],
        ]);
    }

    public function create(Request $request, PayrollPeriodWindowService $periodWindows, PayrollFundTypeService $fundTypes, PayrollEmployeeTypeService $employeeTypes, PayrollSignatoryService $signatories)
    {
        $user = auth()->user();

        $campuses = Campus::query()
            ->when(! $user->isUniversityWide(), fn ($query) => $query->whereKey($user->campus_id))
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('payroll.create', [
            'campuses' => $campuses,
            'selectedCampusId' => $campuses->firstWhere('id', (int) $request->query('campus'))?->id,
            'periods' => $periodWindows->query()->get(),
            'fundClusters' => $fundTypes->mainFundClusters(),
            'employeeTypes' => $employeeTypes->options(),
            'signatoryRoles' => PayrollSignatoryService::ROLES,
            'signatoryEmployees' => $signatories->employeeOptions($user),
            'defaultSignatories' => $signatories->defaultsForCreate($user),
        ]);
    }

    public function store(StorePayrollBatchRequest $request, PayrollBatchService $service, PayrollEmployeeTypeService $employeeTypes)
    {
        $data = $request->validated();
        $result = $service->generateAll($data, $request->user());
        $batches = $result['batches'];

        if ($batches->isEmpty()) {
            return back()
                ->withInput()
                ->withErrors(['generate' => 'No active '.$employeeTypes->label($data['payroll_employee_type']).' employees were found for this campus, so no payroll draft was generated.']);
        }

        $status = $this->generationStatus($result);

        return $batches->count() === 1
            ? redirect()->route('payroll.show', $batches->first())->with('status', $status)
            : redirect()->route('payroll.index', ['campus' => $data['campus_id']])->with('status', $status);
    }

    public function show(PayrollBatch $payroll, PayrollSignatoryService $signatories, HrisTardinessSyncService $tardiness)
    {
        $this->authorizeCampus($payroll);
        $user = auth()->user();
        $batch = $payroll->load(['campus', 'period', 'template.columns', 'fundCluster', 'lines', 'reviews.reviewer']);
        $tardiness->fillMissingReviewTimes($batch->period, $batch->campus_id, $batch->lines, $user);

        return view('payroll.show', [
            'batch' => $batch,
            'fundTabs' => $this->fundTabs($batch),
            'signatoryRoles' => PayrollSignatoryService::ROLES,
            'signatoryEmployees' => $signatories->employeeOptions($user),
            'signatories' => $signatories->forBatch($payroll),
        ]);
    }

    public function updateSignatories(Request $request, PayrollBatch $payroll, PayrollSignatoryService $signatories)
    {
        $this->authorizeCampus($payroll);
        abort_unless($request->user()->canManagePayroll(), 403);

        $data = $request->validate([
            'signatories' => ['required', 'array'],
            'signatories.prepared_by' => ['required', Rule::exists('employees', 'id')->where('is_active', true)],
            'signatories.certified_correct_by' => ['required', Rule::exists('employees', 'id')->where('is_active', true)],
            'signatories.approved_by' => ['required', Rule::exists('employees', 'id')->where('is_active', true)],
            'signatories.certified_payment_by' => ['required', Rule::exists('employees', 'id')->where('is_active', true)],
        ]);

        $snapshot = $payroll->snapshot ?? [];
        $snapshot['signatories'] = $signatories->snapshot($data['signatories'] ?? []);
        $payroll->update(['snapshot' => $snapshot]);

        return back()->with('status', 'Payroll report signatories updated.');
    }

    public function submit(Request $request, PayrollBatch $payroll, PayrollReviewService $service)
    {
        $this->authorizeCampus($payroll);
        $data = $request->validate(['remarks' => ['required', 'string', 'max:1000']]);
        abort_unless(in_array($payroll->status, [PayrollBatch::DRAFT, PayrollBatch::RETURNED], true), 422, 'Only draft or returned payroll can be submitted.');
        abort_if($this->hasBlockingTardinessSyncFailure($payroll), 422, 'The HRIS attendance database must be available before submitting this payroll draft.');
        abort_if($this->hasUnresolvedAttendanceReviews($payroll), 422, 'Resolve HR attendance review issues before submitting this payroll draft.');

        $service->submit($payroll, $request->user(), $data['remarks']);

        return back()->with('status', 'Payroll submitted to University Payroll.');
    }

    public function refreshAttendance(Request $request, PayrollBatch $payroll, PayrollBatchService $service)
    {
        $this->authorizeCampus($payroll);
        abort_unless($request->user()->canManagePayroll(), 403);
        abort_unless(in_array($payroll->status, [PayrollBatch::DRAFT, PayrollBatch::RETURNED], true), 422, 'Only draft or returned payroll can refresh HRIS attendance.');

        $result = $service->refreshAttendance($payroll, $request->user());

        if (($result['status'] ?? null) !== 'connected') {
            return back()->withErrors(['hris' => $result['message'] ?? 'Unable to read attendance from the HRIS database.']);
        }

        return back()->with('status', 'Payroll attendance refreshed directly from the HRIS database.');
    }

    public function resolveAttendanceReview(Request $request, PayrollBatch $payroll, PayrollLine $line, PayrollBatchService $service, AuditLogger $audit)
    {
        $this->authorizeCampus($payroll);
        abort_unless($request->user()->canManagePayroll(), 403);
        abort_unless((int) $line->payroll_batch_id === (int) $payroll->id, 404);

        $data = $request->validate([
            'remarks' => ['nullable', 'string', 'max:500'],
            'review_items' => ['nullable', 'array'],
            'review_items.*.date' => ['required_with:review_items', 'string', 'max:40'],
            'review_items.*.date_label' => ['nullable', 'string', 'max:40'],
            'review_items.*.summary' => ['nullable', 'string', 'max:500'],
            'review_items.*.resolution' => ['required_with:review_items', 'string', Rule::in(array_keys($this->attendanceResolutionOptions()))],
            'review_items.*.remarks' => ['required_with:review_items', 'string', 'max:500'],
        ]);
        $reviewResolutions = $this->reviewResolutions($line, $data['review_items'] ?? []);
        $remarks = $reviewResolutions !== []
            ? collect($reviewResolutions)->map(fn ($item) => $item['date_label'].': '.$item['resolution_label'].' - '.$item['remarks'])->implode('; ')
            : trim((string) ($data['remarks'] ?? 'Attendance issue reviewed and resolved.'));
        $existingRemarks = trim((string) $line->remarks);
        $computedColumns = $line->computed_columns ?? [];

        if ($reviewResolutions !== []) {
            $computedColumns['attendance_review_resolutions'] = $reviewResolutions;
            $computedColumns['attendance_review_items'] = collect($computedColumns['attendance_review_items'] ?? [])
                ->map(function ($item) use ($reviewResolutions) {
                    if (! is_array($item)) {
                        return $item;
                    }

                    $resolution = collect($reviewResolutions)->firstWhere('date', (string) ($item['date'] ?? $item['date_label'] ?? ''));

                    return $resolution ? $item + ['resolution' => $resolution] : $item;
                })
                ->all();
        }

        $line->update([
            'missing_log_status' => 'No issue',
            'appeal_status' => 'approved',
            'remarks' => trim($existingRemarks.($existingRemarks !== '' ? '; ' : '').'HR review resolved: '.$remarks),
            'computed_columns' => $computedColumns,
        ]);

        $service->refreshTotals($payroll);
        $audit->record('payroll.attendance_review_resolved', $request->user(), $payroll, $remarks, [
            'campus_id' => $payroll->campus_id,
            'employee_no' => $line->employee_no,
            'line_id' => $line->id,
        ]);

        return back()->with('status', 'Attendance review issue resolved for '.$line->employee_name.'.');
    }

    public function bulkResolveAttendanceReviews(Request $request, PayrollBatch $payroll, PayrollBatchService $service, AuditLogger $audit)
    {
        $this->authorizeCampus($payroll);
        abort_unless($request->user()->canManagePayroll(), 403);

        $data = $request->validate([
            'line_ids' => ['required', 'array', 'min:1'],
            'line_ids.*' => ['integer'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);
        $remarks = trim((string) ($data['remarks'] ?? 'Attendance issues reviewed and resolved.'));
        $lines = $payroll->lines()
            ->whereIn('id', $data['line_ids'])
            ->where('missing_log_status', '!=', 'No issue')
            ->where(function ($query) {
                $query->whereNull('appeal_status')
                    ->orWhere('appeal_status', '!=', 'approved');
            })
            ->get();

        abort_if($lines->isEmpty(), 422, 'Select at least one unresolved attendance review item.');

        foreach ($lines as $line) {
            $existingRemarks = trim((string) $line->remarks);
            $line->update([
                'missing_log_status' => 'No issue',
                'appeal_status' => 'approved',
                'remarks' => trim($existingRemarks.($existingRemarks !== '' ? '; ' : '').'HR review resolved: '.$remarks),
            ]);
        }

        $service->refreshTotals($payroll);
        $audit->record('payroll.attendance_reviews_bulk_resolved', $request->user(), $payroll, $remarks, [
            'campus_id' => $payroll->campus_id,
            'batch_no' => $payroll->batch_no,
            'line_count' => $lines->count(),
        ]);

        return back()->with('status', $lines->count().' attendance review item'.($lines->count() === 1 ? '' : 's').' resolved.');
    }

    public function return(Request $request, PayrollBatch $payroll, PayrollReviewService $service)
    {
        $this->authorizeUniversityReviewer();
        $data = $request->validate(['remarks' => ['required', 'string', 'max:1000']]);
        abort_unless(in_array($payroll->status, [PayrollBatch::SUBMITTED, PayrollBatch::RESUBMITTED, PayrollBatch::UNDER_REVIEW], true), 422, 'Only submitted payroll can be returned.');

        $service->returnForCorrection($payroll, $request->user(), $data['remarks']);

        return back()->with('status', 'Payroll returned for correction.');
    }

    public function approve(Request $request, PayrollBatch $payroll, PayrollReviewService $service)
    {
        $this->authorizeUniversityReviewer();
        $data = $request->validate(['remarks' => ['required', 'string', 'max:1000']]);
        abort_if($payroll->employees_with_unresolved_appeals > 0, 422, 'Resolve appeals before approval.');
        abort_unless(in_array($payroll->status, [PayrollBatch::SUBMITTED, PayrollBatch::RESUBMITTED, PayrollBatch::UNDER_REVIEW], true), 422, 'Only submitted payroll can be approved.');

        $service->approve($payroll, $request->user(), $data['remarks']);

        return back()->with('status', 'Payroll approved for printing.');
    }

    public function print(Request $request, PayrollBatch $payroll, PayrollReviewService $service)
    {
        $this->authorizeCampus($payroll);
        $data = $request->validate(['remarks' => ['nullable', 'string', 'max:1000']]);
        abort_unless($payroll->status === PayrollBatch::APPROVED || $payroll->status === PayrollBatch::PRINTED, 422, 'Only approved payroll can be printed.');

        $service->markPrinted($payroll, $request->user(), $data['remarks'] ?? 'Final payroll printed');

        return redirect()->route('payroll.printable', $payroll)->with('status', 'Payroll marked as printed.');
    }

    public function printable(PayrollBatch $payroll, PayrollExportService $export)
    {
        $this->authorizeCampus($payroll);

        abort_unless(in_array($payroll->status, [PayrollBatch::APPROVED, PayrollBatch::PRINTED], true), 403);

        return $export->pdf($payroll);
    }

    public function export(PayrollBatch $payroll, PayrollExportService $export)
    {
        $this->authorizeCampus($payroll);

        abort_unless(in_array($payroll->status, [PayrollBatch::APPROVED, PayrollBatch::PRINTED], true), 403);

        return $export->excel($payroll);
    }

    public function destroy(Request $request, PayrollBatch $payroll, AuditLogger $audit)
    {
        $this->authorizeCampus($payroll);

        abort_unless($payroll->status === PayrollBatch::DRAFT, 422, 'Only draft payroll batches can be deleted.');

        $batchNo = $payroll->batch_no;
        $campusId = $payroll->campus_id;

        $audit->record('payroll.deleted', $request->user(), $payroll, 'Draft payroll batch deleted.', [
            'campus_id' => $campusId,
            'batch_no' => $batchNo,
        ]);

        $payroll->delete();

        return redirect()->route('payroll.index')->with('status', 'Draft payroll batch deleted.');
    }

    /**
     * One run fans out into a draft per payroll fund, so report what it produced.
     */
    private function generationStatus(array $result): string
    {
        $batches = $result['batches'];
        $funds = $batches->map(fn (PayrollBatch $batch) => $batch->fundCluster?->payroll_template_type)->filter()->implode(', ');
        $status = $batches->count() === 1
            ? 'Draft payroll generated for '.$funds.' and ready for review.'
            : $batches->count().' draft payrolls generated - one per fund ('.$funds.') - and ready for review.';

        if (($result['unassigned'] ?? 0) > 0) {
            $status .= ' '.$result['unassigned'].' employee'.($result['unassigned'] === 1 ? '' : 's')
                .' had no fund cluster and '.($result['unassigned'] === 1 ? 'was' : 'were').' placed on the '
                .PayrollFundTypeService::FALLBACK_TYPE.' draft flagged as missing fund source.';
        }

        if ($result['skipped_funds'] ?? []) {
            $status .= ' No active payroll template for '.implode(', ', $result['skipped_funds']).', so '
                .(count($result['skipped_funds']) === 1 ? 'that fund was' : 'those funds were').' skipped.';
        }

        return $status;
    }

    /**
     * The sibling drafts of the same generation run, rendered as workbook-style fund
     * tabs so a payroll administrator can shift between funds the way the manual
     * Excel file switches sheets. Funds with no employees have no draft to open.
     */
    private function fundTabs(PayrollBatch $batch): Collection
    {
        $runRef = $batch->snapshot['generation_run']['ref'] ?? null;
        $employeeType = $batch->snapshot['payroll_employee_type'] ?? null;

        $siblings = PayrollBatch::query()
            ->with('fundCluster')
            ->visibleTo(auth()->user())
            ->where('campus_id', $batch->campus_id)
            ->where('payroll_period_id', $batch->payroll_period_id)
            ->when(
                $runRef,
                fn ($query) => $query->where('snapshot->generation_run->ref', $runRef),
                // Drafts generated before run references fall back to the same campus, period, and group.
                fn ($query) => $query->when($employeeType, fn ($q) => $q->where('snapshot->payroll_employee_type', $employeeType)),
            )
            ->orderBy('id')
            ->get();

        // Ties keep the newest draft per fund, but the one being viewed always wins.
        $byType = $siblings
            ->filter(fn (PayrollBatch $sibling) => $sibling->fundCluster?->payroll_template_type)
            ->keyBy(fn (PayrollBatch $sibling) => $sibling->fundCluster->payroll_template_type)
            ->put($batch->fundCluster?->payroll_template_type, $batch);

        return collect(PayrollFundTypeService::TYPES)->map(fn (string $type) => [
            'type' => $type,
            'batch' => $byType->get($type),
            'is_current' => $byType->get($type)?->is($batch) ?? false,
        ]);
    }

    private function authorizeCampus(PayrollBatch $batch): void
    {
        $user = auth()->user();
        abort_if(! $user->isUniversityWide() && $batch->campus_id !== $user->campus_id, 403);
    }

    private function hasUnresolvedAttendanceReviews(PayrollBatch $batch): bool
    {
        return $batch->lines()
            ->where('missing_log_status', '!=', 'No issue')
            ->where(function ($query) {
                $query->whereNull('appeal_status')
                    ->orWhere('appeal_status', '!=', 'approved');
            })
            ->exists();
    }

    private function attendanceResolutionOptions(): array
    {
        return [
            'corrected_dtr' => 'Corrected DTR',
            'cto' => 'CTO',
            'leave' => 'On Leave',
            'vacation_leave' => 'Vacation Leave',
            'sick_leave' => 'Sick Leave',
            'emergency_leave' => 'Emergency Leave',
            'official_business' => 'Official Business',
            'absent' => 'Absent',
            'half_day' => 'Half Day',
            'undertime' => 'Undertime',
            'late' => 'Late',
            'holiday' => 'Holiday / Suspension',
            'suspension' => 'Work Suspension',
            'no_pay' => 'No Pay',
            'other' => 'Other',
        ];
    }

    private function reviewResolutions(PayrollLine $line, array $submittedItems): array
    {
        if ($submittedItems === []) {
            return [];
        }

        $options = $this->attendanceResolutionOptions();
        $issueDates = collect($line->computed_columns['attendance_review_items'] ?? [])
            ->filter(fn ($item) => is_array($item) && ! empty($item['issues']))
            ->map(fn ($item) => trim((string) ($item['date'] ?? $item['date_label'] ?? '')))
            ->filter()
            ->unique()
            ->values();
        $submitted = collect($submittedItems)
            ->map(function ($item) use ($options) {
                $date = trim((string) ($item['date'] ?? $item['date_label'] ?? ''));
                $resolution = trim((string) ($item['resolution'] ?? ''));
                $remarks = trim((string) ($item['remarks'] ?? ''));

                if ($date === '' || $resolution === '' || $remarks === '') {
                    return null;
                }

                return [
                    'date' => $date,
                    'date_label' => trim((string) ($item['date_label'] ?? $date)),
                    'summary' => trim((string) ($item['summary'] ?? '')),
                    'resolution' => $resolution,
                    'resolution_label' => $options[$resolution] ?? ucfirst(str_replace('_', ' ', $resolution)),
                    'remarks' => $remarks,
                ];
            })
            ->filter()
            ->values();

        if ($issueDates->isNotEmpty()) {
            $submittedDates = $submitted->pluck('date')->unique()->values();
            abort_if($issueDates->diff($submittedDates)->isNotEmpty(), 422, 'Resolve each reviewed attendance date before clearing this employee.');
        }

        return $submitted->all();
    }

    private function hasBlockingTardinessSyncFailure(PayrollBatch $batch): bool
    {
        $sync = $batch->snapshot['tardiness_sync'] ?? null;

        return is_array($sync) && ($sync['status'] ?? null) !== 'connected';
    }

    private function authorizeUniversityReviewer(): void
    {
        $user = auth()->user();
        abort_unless(in_array($user->role?->slug, ['super-administrator', 'university-payroll-administrator'], true), 403);
    }
}
