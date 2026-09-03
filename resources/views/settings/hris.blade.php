<x-layouts.app page-title="HRIS Settings" page-subtitle="Direct, read-only HRIS database integration">
    <section class="grid two">
        <div class="card">
            <div class="card-header"><div><h2>Connection</h2><div class="card-kicker">Configured through environment variables</div></div></div>
            @if(session('hris_status'))<div class="alert {{ session('hris_status') === 'connected' ? 'success' : 'danger' }}"><x-icon :name="session('hris_status') === 'connected' ? 'check' : 'alert'" /> <span>HRIS status: {{ session('hris_status') }}</span></div>@endif
            <div class="stack">
                <div class="panel-note"><strong>Host:</strong> {{ $host }}:{{ $port }}</div>
                <div class="panel-note"><strong>Database:</strong> {{ $database }}</div>
                <div class="panel-note"><strong>Credentials:</strong> <span class="badge {{ $hasCredentials ? 'green' : 'red' }}">{{ $hasCredentials ? 'Configured' : 'Missing' }}</span></div>
            </div>
            <div class="actions" style="margin-top:16px">
                <form method="POST" action="{{ route('settings.hris.check') }}">@csrf<button class="primary-btn" type="submit"><x-icon name="shield" /> Validate HRIS Connection</button></form>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><div><h2>Access Rules</h2><div class="card-kicker">Dedicated HRIS database connection</div></div></div>
            <p class="muted">Employee synchronization reads only payroll-relevant employee fields from HRIS. Database credentials stay in the server environment and are never exposed in Blade, JavaScript, URLs, logs, or exports.</p>
        </div>
    </section>
    <section class="card" style="margin-top:16px">
        <div class="card-header"><div><h2>Recent HRIS Sync Logs</h2><div class="card-kicker">Connection checks and import attempts</div></div></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>Type</th><th>Status</th><th>Duration</th><th>Error</th><th>When</th></tr></thead><tbody>
        @forelse($logs as $log)
            <tr><td>{{ $log->request_type }}</td><td><span class="badge {{ $log->status === 'connected' ? 'green' : 'red' }}">{{ $log->status }}</span></td><td>{{ $log->duration_ms }} ms</td><td>{{ $log->error_message }}</td><td>{{ $log->created_at->format('M d, Y h:i A') }}</td></tr>
        @empty
            <tr><td colspan="5"><div class="table-empty">No HRIS sync logs yet.</div></td></tr>
        @endforelse
        </tbody></table></div>
    </section>
</x-layouts.app>
