<?php

namespace App\Services;

use App\Models\PayrollBatch;
use App\Models\PayrollReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PayrollReviewService
{
    public function __construct(private AuditLogger $audit) {}

    public function submit(PayrollBatch $batch, User $user, string $remarks): PayrollBatch
    {
        return $this->transition($batch, $user, PayrollBatch::SUBMITTED, 'submitted', $remarks, [
            'submitted_at' => now(),
        ]);
    }

    public function returnForCorrection(PayrollBatch $batch, User $user, string $remarks): PayrollBatch
    {
        return $this->transition($batch, $user, PayrollBatch::RETURNED, 'returned', $remarks);
    }

    public function approve(PayrollBatch $batch, User $user, string $remarks): PayrollBatch
    {
        return $this->transition($batch, $user, PayrollBatch::APPROVED, 'approved', $remarks, [
            'approved_at' => now(),
        ]);
    }

    public function markPrinted(PayrollBatch $batch, User $user, string $remarks): PayrollBatch
    {
        return $this->transition($batch, $user, PayrollBatch::PRINTED, 'printed', $remarks, [
            'printed_at' => now(),
            'printed_by' => $user->id,
        ]);
    }

    private function transition(PayrollBatch $batch, User $user, string $status, string $action, string $remarks, array $extra = []): PayrollBatch
    {
        return DB::transaction(function () use ($batch, $user, $status, $action, $remarks, $extra) {
            $batch->update(['status' => $status] + $extra);

            PayrollReview::create([
                'payroll_batch_id' => $batch->id,
                'reviewed_by' => $user->id,
                'action' => $action,
                'remarks' => $remarks,
            ]);

            $this->audit->record('payroll.'.$action, $user, $batch, $remarks, [
                'campus_id' => $batch->campus_id,
                'status' => $status,
            ]);

            return $batch->refresh();
        });
    }
}
