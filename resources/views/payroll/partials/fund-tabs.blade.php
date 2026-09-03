{{-- Workbook-style fund tabs: one run fills these the way the manual Excel file fills its sheets. --}}
<nav class="fund-tabs" aria-label="Payroll funds">
    <span class="fund-tabs-head">Funds</span>
    <div class="fund-tabs-list">
        @foreach($fundTabs as $tab)
            @if($tab['batch'])
                <a
                    class="fund-tab{{ $tab['is_current'] ? ' active' : '' }}"
                    href="{{ route('payroll.show', $tab['batch']) }}"
                    @if($tab['is_current']) aria-current="page" @endif
                    title="{{ $tab['type'] }} - {{ $tab['batch']->status }} - {{ number_format($tab['batch']->total_net, 2) }} net"
                >
                    <span class="fund-dot {{ $tab['batch']->status === 'Approved for Printing' || $tab['batch']->status === 'Printed' ? 'green' : ($tab['batch']->status === 'Returned for Correction' ? 'red' : 'gold') }}"></span>
                    {{ $tab['type'] }}
                    <strong>{{ number_format($tab['batch']->total_employees) }}</strong>
                </a>
            @else
                <span class="fund-tab empty" title="No {{ $tab['type'] }} employees in this run, so no draft was generated.">
                    {{ $tab['type'] }}
                </span>
            @endif
        @endforeach
    </div>
</nav>
