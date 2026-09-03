<nav class="campus-menu" aria-label="Payroll campus submenu">
    <div class="campus-menu-head">Campus</div>
    <div class="campus-menu-list" role="tablist">
        @if($isUniversityWide)
            <a
                class="campus-tab all {{ $selectedCampus ? '' : 'active' }}"
                href="{{ route('payroll.index') }}"
                role="tab"
                aria-selected="{{ $selectedCampus ? 'false' : 'true' }}"
            >
                <span class="campus-tab-name">All campuses</span>
                <span class="campus-tab-count">{{ number_format($allCampusTotal) }}</span>
            </a>
        @endif

        @foreach($campuses as $campus)
            @php
                $stats = $campusStats[$campus->id] ?? ['total' => 0, 'needs_action' => 0];
                $isActive = $selectedCampus?->id === $campus->id;
            @endphp
            <a
                class="campus-tab {{ $isActive ? 'active' : '' }}"
                href="{{ route('payroll.index', ['campus' => $campus->id]) }}"
                role="tab"
                aria-selected="{{ $isActive ? 'true' : 'false' }}"
                title="{{ $campus->name }}"
            >
                <span class="campus-tab-name">{{ $campus->code }}</span>
                @if($stats['needs_action'] > 0)
                    <span class="campus-tab-flag" title="{{ $stats['needs_action'] }} awaiting campus action"></span>
                @endif
                <span class="campus-tab-count">{{ number_format($stats['total']) }}</span>
            </a>
        @endforeach
    </div>
</nav>
