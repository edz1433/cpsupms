<x-layouts.app page-title="Fund Clusters" page-subtitle="Configurable payroll templates and fund sources from the workbook">
    <div class="card">
        <div class="card-header"><div><h2>Fund Clusters</h2><div class="card-kicker">Workbook-modeled fund sources and payroll template mappings</div></div></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>Code</th><th>Name</th><th>Template</th><th>Fund Source</th><th>Campus</th><th>Status</th></tr></thead><tbody>
        @forelse($fundClusters as $fund)
            <tr><td><strong>{{ $fund->code }}</strong></td><td>{{ $fund->name }}</td><td>{{ $fund->payroll_template_type }}</td><td>{{ $fund->fund_source_name }}</td><td>{{ $fund->campus?->code ?? 'University-wide' }}</td><td><span class="badge {{ $fund->is_active ? 'green' : 'gray' }}">{{ $fund->is_active ? 'Active' : 'Inactive' }}</span></td></tr>
        @empty
            <tr><td colspan="6"><div class="table-empty">No fund clusters found.</div></td></tr>
        @endforelse
        </tbody></table></div>
        <div style="margin-top:16px">{{ $fundClusters->links() }}</div>
    </div>
</x-layouts.app>
