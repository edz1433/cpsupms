<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0B6E2E">
    <link rel="apple-touch-icon" href="{{ asset('images/cpsu-logo.png') }}">
    <title>{{ $title ?? 'CPSU Payroll Management System' }}</title>
    <script>
        try {
            // Collapsed unless this browser has explicitly expanded it before.
            if (localStorage.getItem('cpsu-payroll-sidebar-collapsed') !== '0') {
                document.documentElement.classList.add('sidebar-collapsed');
            }
        } catch (error) {}
    </script>
    @include('partials.styles')
    @vite(['resources/js/app.js'])
</head>
<body>
@php
    $user = auth()->user();
    $nav = [
        ['Dashboard', 'dashboard', 'dashboard', 'dashboard'],
        ['Payroll', 'payroll.index', 'payroll', 'payroll'],
        ['Employees', 'employees.index', 'employees', 'employees'],
        ['Fund Clusters', 'fund-clusters.index', 'fund-clusters', 'funds'],
        ['Periods', 'periods.index', 'periods', 'calendar'],
    ];

    if ($user->canManageHris()) {
        $nav[] = ['HRIS Settings', 'settings.hris', 'settings', 'settings'];
    }
@endphp
<input id="nav-toggle" class="nav-toggle" type="checkbox">
<label class="mobile-backdrop" for="nav-toggle" aria-label="Close navigation"></label>
<aside class="sidebar" id="primary-sidebar">
    <div class="brand-row">
        <a class="brand" href="{{ route('dashboard') }}">
            <img src="{{ asset('images/cpsu-logo.png') }}" alt="CPSU Seal">
            <span><strong>CPSU</strong><small>Payroll</small></span>
        </a>
        <button class="sidebar-toggle" type="button" data-sidebar-toggle aria-controls="primary-sidebar" aria-expanded="true" aria-label="Collapse sidebar" title="Collapse sidebar">
            <x-icon name="chevron-left" class="sidebar-collapse-icon" />
            <x-icon name="chevron-right" class="sidebar-expand-icon" />
        </button>
    </div>
    <div class="nav-section">Main Menu</div>
    <nav>
        @foreach ($nav as [$label, $route, $key, $icon])
            <a class="{{ request()->routeIs($key.'*') || request()->routeIs($route) ? 'active' : '' }}" href="{{ route($route) }}" title="{{ $label }}">
                <x-icon :name="$icon" /> <span>{{ $label }}</span>
            </a>
        @endforeach
    </nav>
    <div class="identity">
        <span>{{ collect(explode(' ', $user->name))->map(fn ($part) => $part[0] ?? '')->take(2)->implode('') }}</span>
        <div>
            <strong>{{ $user->name }}</strong>
            <small>{{ $user->role?->name }}{{ $user->campus ? ' - '.$user->campus->code : '' }}</small>
        </div>
    </div>
</aside>
<div class="shell">
    <header class="topbar">
        <div style="display:flex;align-items:center;gap:12px;min-width:0">
            <label class="ghost-btn mobile-menu-btn" for="nav-toggle" aria-label="Open navigation" title="Open navigation"><x-icon name="menu" /></label>
            <div style="min-width:0">
                <h1>{{ $pageTitle ?? 'CPSU Payroll Management System' }}</h1>
                <p>{{ $pageSubtitle ?? 'Central Philippines State University' }}</p>
            </div>
        </div>
        <div class="topbar-actions">
            <span class="role-pill"><x-icon name="shield" /> {{ $user->role?->name }}{{ $user->campus ? ' - '.$user->campus->code : '' }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="ghost-btn" type="submit"><x-icon name="logout" /> Sign out</button>
            </form>
        </div>
    </header>
    <main @class(['content', $contentClass ?? null])>
        @if (session('status'))
            <div class="alert success"><x-icon name="check" /> <span>{{ session('status') }}</span></div>
        @endif
        @if ($errors->any())
            <div class="alert danger"><x-icon name="alert" /> <span>{{ $errors->first() }}</span></div>
        @endif
        {{ $slot }}
    </main>
    <footer>Central Philippines State University Payroll Management System</footer>
</div>
<script>
    (function () {
        const storageKey = 'cpsu-payroll-sidebar-collapsed';
        const toggles = document.querySelectorAll('[data-sidebar-toggle]');

        if (document.documentElement.classList.contains('sidebar-collapsed')) {
            document.body.classList.add('sidebar-collapsed');
        }

        function updateToggleLabels() {
            const collapsed = document.body.classList.contains('sidebar-collapsed');

            toggles.forEach(function (toggle) {
                toggle.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
                toggle.setAttribute('title', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
                toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            });
        }

        toggles.forEach(function (toggle) {
            toggle.addEventListener('click', function () {
                document.body.classList.toggle('sidebar-collapsed');
                document.documentElement.classList.toggle('sidebar-collapsed', document.body.classList.contains('sidebar-collapsed'));
                localStorage.setItem(storageKey, document.body.classList.contains('sidebar-collapsed') ? '1' : '0');
                updateToggleLabels();
            });
        });

        updateToggleLabels();
    })();
</script>
</body>
</html>
