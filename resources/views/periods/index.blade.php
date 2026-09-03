<x-layouts.app page-title="Payroll Periods" page-subtitle="Semi-monthly, monthly, and custom payroll windows">
    <div class="card">
        <div class="card-header"><div><h2>Periods</h2><div class="card-kicker">Open and locked payroll windows by campus or payroll type</div></div></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>Name</th><th>Dates</th><th>Type</th><th>Payroll Type</th><th>Campus</th><th>Lock</th></tr></thead><tbody>
        @forelse($periods as $period)
            <tr><td><strong>{{ $period->name }}</strong></td><td>{{ $period->date_from->format('M d, Y') }} - {{ $period->date_to->format('M d, Y') }}</td><td>{{ $period->period_type }}</td><td>{{ $period->payroll_type ?: 'All' }}</td><td>{{ $period->campus?->code ?? 'All' }}</td><td><span class="badge {{ $period->is_locked ? 'red' : 'green' }}">{{ $period->is_locked ? 'Locked' : 'Open' }}</span></td></tr>
        @empty
            <tr><td colspan="6"><div class="table-empty">No payroll periods found.</div></td></tr>
        @endforelse
        </tbody></table></div>
        <div style="margin-top:16px">{{ $periods->links() }}</div>
    </div>
</x-layouts.app>
