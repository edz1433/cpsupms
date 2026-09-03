<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollBatch;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $user = auth()->user();
        $allBatches = PayrollBatch::query()
            ->with(['campus', 'fundCluster', 'period'])
            ->visibleTo($user)
            ->get();
        $totalBatches = max(1, $allBatches->count());
        $totalGross = (float) $allBatches->sum('total_gross');
        $totalDeductions = (float) $allBatches->sum('total_deductions');
        $totalNet = (float) $allBatches->sum('total_net');
        $statusLabels = [
            PayrollBatch::DRAFT => 'Draft',
            PayrollBatch::SUBMITTED => 'Submitted',
            PayrollBatch::RETURNED => 'Returned',
            PayrollBatch::APPROVED => 'Approved',
            PayrollBatch::PRINTED => 'Printed',
        ];
        $statusAnalytics = collect($statusLabels)->map(function ($label, $status) use ($allBatches, $totalBatches) {
            $count = $allBatches->where('status', $status)->count();

            return [
                'status' => $status,
                'label' => $label,
                'count' => $count,
                'percent' => round(($count / $totalBatches) * 100, 1),
            ];
        })->values();
        $campusAnalytics = $allBatches
            ->groupBy(fn ($batch) => $batch->campus?->code ?? 'N/A')
            ->map(fn ($items, $label) => [
                'label' => $label,
                'net' => (float) $items->sum('total_net'),
                'count' => $items->count(),
            ])
            ->sortByDesc('net')
            ->take(6)
            ->values();
        $campusMax = max(1, (float) $campusAnalytics->max('net'));
        $fundAnalytics = $allBatches
            ->groupBy(fn ($batch) => $batch->fundCluster?->fund_source_name ?? 'Unassigned')
            ->map(fn ($items, $label) => [
                'label' => str($label)->limit(34)->toString(),
                'net' => (float) $items->sum('total_net'),
                'count' => $items->count(),
            ])
            ->sortByDesc('net')
            ->take(6)
            ->values();
        $fundMax = max(1, (float) $fundAnalytics->max('net'));
        $periodTrend = $allBatches
            ->groupBy(fn ($batch) => $batch->period?->name ?? 'No period')
            ->map(fn ($items, $label) => [
                'label' => str($label)->limit(18)->toString(),
                'gross' => (float) $items->sum('total_gross'),
                'deductions' => (float) $items->sum('total_deductions'),
                'net' => (float) $items->sum('total_net'),
            ])
            ->values()
            ->take(8);
        $trendMax = max(1, (float) $periodTrend->max('gross'), (float) $periodTrend->max('net'));

        return view('dashboard', [
            'stats' => [
                'employees' => Employee::query()->visibleTo($user)->count(),
                'drafts' => $allBatches->where('status', PayrollBatch::DRAFT)->count(),
                'submitted' => $allBatches->whereIn('status', [PayrollBatch::SUBMITTED, PayrollBatch::RESUBMITTED, PayrollBatch::UNDER_REVIEW])->count(),
                'approved' => $allBatches->where('status', PayrollBatch::APPROVED)->count(),
                'net' => $totalNet,
            ],
            'analytics' => [
                'total_batches' => $allBatches->count(),
                'total_gross' => $totalGross,
                'total_deductions' => $totalDeductions,
                'total_net' => $totalNet,
                'deduction_rate' => $totalGross > 0 ? round(($totalDeductions / $totalGross) * 100, 1) : 0,
                'avg_net' => $allBatches->count() > 0 ? round($totalNet / $allBatches->count(), 2) : 0,
                'missing_logs' => $allBatches->sum('employees_with_missing_logs'),
                'unresolved_appeals' => $allBatches->sum('employees_with_unresolved_appeals'),
                'negative_net' => $allBatches->sum('employees_with_negative_net'),
            ],
            'statusAnalytics' => $statusAnalytics,
            'campusAnalytics' => $campusAnalytics,
            'campusMax' => $campusMax,
            'fundAnalytics' => $fundAnalytics,
            'fundMax' => $fundMax,
            'periodTrend' => $periodTrend,
            'trendMax' => $trendMax,
            'recentBatches' => PayrollBatch::query()
                ->with(['campus', 'period', 'fundCluster'])
                ->visibleTo($user)
                ->latest()
                ->limit(8)
                ->get(),
            'auditLogs' => AuditLog::query()->latest()->limit(8)->get(),
        ]);
    }
}
