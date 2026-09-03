<x-layouts.app page-title="Dashboard" page-subtitle="Payroll overview and review queue">
    <section class="ops-brief">
        <div class="ops-brief-main">
            <div>
                <div class="section-label">Payroll Control</div>
                <h2>{{ number_format($analytics['total_net'], 2) }}</h2>
                <p>Current net payable across visible payroll batches</p>
            </div>
        </div>
        <div class="ops-stat">
            <span>Total Gross</span>
            <strong>{{ number_format($analytics['total_gross'], 2) }}</strong>
        </div>
        <div class="ops-stat">
            <span>Deductions</span>
            <strong>{{ number_format($analytics['total_deductions'], 2) }}</strong>
        </div>
        <div class="ops-stat">
            <span>Deduction Rate</span>
            <strong>{{ number_format($analytics['deduction_rate'], 1) }}%</strong>
        </div>
        <div class="ops-stat">
            <span>Avg Net / Batch</span>
            <strong>{{ number_format($analytics['avg_net'], 2) }}</strong>
        </div>
    </section>

    <section class="grid kpis">
        <div class="card kpi-card" style="--accent:#0B6E2E;--accent-bg:#E8F5EC"><div class="kpi-row"><div><div class="kpi-label">Employees</div><div class="kpi-value">{{ number_format($stats['employees']) }}</div><div class="kpi-note">Active payroll records</div></div><div class="kpi-icon"><x-icon name="employees" /></div></div></div>
        <div class="card kpi-card" style="--accent:#8A6A00;--accent-bg:#FFF8C7"><div class="kpi-row"><div><div class="kpi-label">Drafts</div><div class="kpi-value">{{ number_format($stats['drafts']) }}</div><div class="kpi-note">In campus preparation</div></div><div class="kpi-icon"><x-icon name="payroll" /></div></div></div>
        <div class="card kpi-card" style="--accent:#475467;--accent-bg:#F2F4F7"><div class="kpi-row"><div><div class="kpi-label">Submitted</div><div class="kpi-value">{{ number_format($stats['submitted']) }}</div><div class="kpi-note">Awaiting review</div></div><div class="kpi-icon"><x-icon name="open" /></div></div></div>
        <div class="card kpi-card" style="--accent:#16A34A;--accent-bg:#EAFBF0"><div class="kpi-row"><div><div class="kpi-label">Approved</div><div class="kpi-value">{{ number_format($stats['approved']) }}</div><div class="kpi-note">Ready for printing</div></div><div class="kpi-icon"><x-icon name="check" /></div></div></div>
        <div class="card kpi-card" style="--accent:#0B6E2E;--accent-bg:#E8F5EC"><div class="kpi-row"><div><div class="kpi-label">Total Net</div><div class="kpi-value">{{ number_format($stats['net'], 2) }}</div><div class="kpi-note">Current payable total</div></div><div class="kpi-icon"><x-icon name="funds" /></div></div></div>
    </section>

    <section class="analytics-grid">
        <div class="card chart-card">
            <div class="card-header">
                <div><h2>Payroll Trend</h2><div class="card-kicker">Gross and net payroll by period</div></div>
                <span class="badge green">{{ number_format($analytics['total_batches']) }} batches</span>
            </div>
            @if($periodTrend->isNotEmpty())
                <div class="trend-chart" aria-label="Payroll trend chart">
                    @foreach($periodTrend as $period)
                        <div class="trend-group" title="{{ $period['label'] }} gross {{ number_format($period['gross'], 2) }} net {{ number_format($period['net'], 2) }}">
                            <div class="trend-bar gross" style="height: {{ max(3, ($period['gross'] / $trendMax) * 170) }}px"></div>
                            <div class="trend-bar net" style="height: {{ max(3, ($period['net'] / $trendMax) * 170) }}px"></div>
                            <div class="trend-label">{{ $period['label'] }}</div>
                        </div>
                    @endforeach
                </div>
                <div class="actions" style="margin-top:14px">
                    <span class="badge green">Gross</span>
                    <span class="badge gold">Net</span>
                    <span class="badge gray">Deduction rate {{ number_format($analytics['deduction_rate'], 1) }}%</span>
                </div>
            @else
                <div class="table-empty">No payroll trend data yet.</div>
            @endif
        </div>

        <div class="card chart-card">
            <div class="card-header"><div><h2>Status Distribution</h2><div class="card-kicker">Batch progress across the approval workflow</div></div></div>
            <div class="status-stack" aria-label="Payroll status distribution">
                @foreach($statusAnalytics as $status)
                    <span class="status-segment {{ str($status['label'])->lower()->toString() }}" style="width: {{ $status['percent'] }}%" title="{{ $status['label'] }} {{ $status['percent'] }}%"></span>
                @endforeach
            </div>
            <div class="legend-grid">
                @foreach($statusAnalytics as $status)
                    @php
                        $dot = match($status['label']) {
                            'Draft' => '#8A6A00',
                            'Submitted' => '#667085',
                            'Returned' => '#DC2626',
                            'Approved' => '#16A34A',
                            'Printed' => '#0B6E2E',
                            default => '#0B6E2E',
                        };
                    @endphp
                    <div class="legend-row">
                        <div class="legend-name"><span class="legend-dot" style="--dot:{{ $dot }}"></span>{{ $status['label'] }}</div>
                        <div class="legend-value">{{ number_format($status['count']) }} <span class="muted small">({{ number_format($status['percent'], 1) }}%)</span></div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="analytics-grid">
        <div class="card">
            <div class="card-header"><div><h2>Campus Net Payroll</h2><div class="card-kicker">Highest net payroll totals by campus</div></div></div>
            <div class="bar-list">
                @forelse($campusAnalytics as $campus)
                    <div class="bar-row">
                        <div class="bar-meta"><strong>{{ $campus['label'] }}</strong><span>{{ number_format($campus['net'], 2) }} / {{ $campus['count'] }} batches</span></div>
                        <div class="bar-track"><div class="bar-fill" style="width: {{ max(2, ($campus['net'] / $campusMax) * 100) }}%"></div></div>
                    </div>
                @empty
                    <div class="table-empty">No campus payroll data yet.</div>
                @endforelse
            </div>
        </div>

        <div class="card">
            <div class="card-header"><div><h2>Fund Source Allocation</h2><div class="card-kicker">Net payroll distribution by fund source</div></div></div>
            <div class="bar-list">
                @forelse($fundAnalytics as $fund)
                    <div class="bar-row">
                        <div class="bar-meta"><strong>{{ $fund['label'] }}</strong><span>{{ number_format($fund['net'], 2) }}</span></div>
                        <div class="bar-track"><div class="bar-fill gold" style="width: {{ max(2, ($fund['net'] / $fundMax) * 100) }}%"></div></div>
                    </div>
                @empty
                    <div class="table-empty">No fund-source payroll data yet.</div>
                @endforelse
            </div>
        </div>
    </section>

    <section class="card" style="margin-top:18px">
        <div class="card-header"><div><h2>Payroll Risk Indicators</h2><div class="card-kicker">Issues that need attention before final approval</div></div></div>
        <div class="risk-grid">
            <div class="risk-box warning"><div class="kpi-label">Missing Logs</div><strong>{{ number_format($analytics['missing_logs']) }}</strong><div class="muted small">Employees flagged across batches</div></div>
            <div class="risk-box danger"><div class="kpi-label">Unresolved Appeals</div><strong>{{ number_format($analytics['unresolved_appeals']) }}</strong><div class="muted small">Must be cleared before approval</div></div>
            <div class="risk-box success"><div class="kpi-label">Average Net / Batch</div><strong>{{ number_format($analytics['avg_net'], 2) }}</strong><div class="muted small">Based on visible payroll batches</div></div>
        </div>
    </section>

    <section class="grid two" style="margin-top:18px">
        <div class="card">
            <div class="card-header">
                <div><h2>Recent Payroll Batches</h2><div class="card-kicker">Campus submissions and review status</div></div>
            </div>
            @include('payroll.partials.batch-table', ['batches' => $recentBatches])
        </div>
        <div class="card">
            <div class="card-header"><div><h2>Recent Audit Log</h2><div class="card-kicker">Latest recorded payroll events</div></div></div>
            <div class="timeline">
                @forelse($auditLogs as $log)
                    <div class="timeline-item">
                        <strong>{{ $log->event }}</strong>
                        <div class="muted small">{{ $log->remarks ?: 'No remarks' }}</div>
                        <div class="muted small">{{ $log->created_at?->format('M d, Y h:i A') }}</div>
                    </div>
                @empty
                    <div class="table-empty">No audit activity yet.</div>
                @endforelse
            </div>
        </div>
    </section>
</x-layouts.app>
