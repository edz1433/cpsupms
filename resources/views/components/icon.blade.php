@props(['name' => 'circle'])

<svg {{ $attributes->merge(['class' => 'icon']) }} xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        @case('campus')
            <path d="M3 21h18" /><path d="M5 21V8l7-4 7 4v13" /><path d="M9 21v-5h6v5" /><path d="M9 11h2" /><path d="M13 11h2" />
            @break
        @case('dashboard')
            <path d="M4 13h6V4H4v9Z" /><path d="M14 20h6v-9h-6v9Z" /><path d="M4 20h6v-3H4v3Z" /><path d="M14 7h6V4h-6v3Z" />
            @break
        @case('payroll')
            <path d="M6 3h9l3 3v15H6V3Z" /><path d="M14 3v4h4" /><path d="M8.5 11h7" /><path d="M8.5 15h7" /><path d="M8.5 18h4" />
            @break
        @case('employees')
            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z" /><path d="M22 21v-2a4 4 0 0 0-3-3.87" /><path d="M16 3.13a4 4 0 0 1 0 7.75" />
            @break
        @case('funds')
            <path d="M3 7h18" /><path d="M6 7V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v2" /><path d="M5 7l1 14h12l1-14" /><path d="M9 11h6" /><path d="M9 15h6" />
            @break
        @case('calendar')
            <path d="M8 2v4" /><path d="M16 2v4" /><path d="M3 10h18" /><path d="M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2Z" />
            @break
        @case('settings')
            <path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z" /><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21a2 2 0 1 1-4 0v-.09a1.7 1.7 0 0 0-.4-1.1 1.7 1.7 0 0 0-1-.6 1.7 1.7 0 0 0-1.88.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H3a2 2 0 1 1 0-4h.09a1.7 1.7 0 0 0 1.1-.4 1.7 1.7 0 0 0 .6-1 1.7 1.7 0 0 0-.34-1.88l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V3a2 2 0 1 1 4 0v.09a1.7 1.7 0 0 0 .4 1.1 1.7 1.7 0 0 0 1 .6 1.7 1.7 0 0 0 1.88-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9c.26.34.6.54 1 .6.34.04.7.04 1.1.04H21a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.1.4 1.7 1.7 0 0 0-.4 1Z" />
            @break
        @case('logout')
            <path d="M10 17l5-5-5-5" /><path d="M15 12H3" /><path d="M21 19V5a2 2 0 0 0-2-2h-5" />
            @break
        @case('plus')
            <path d="M12 5v14" /><path d="M5 12h14" />
            @break
        @case('open')
            <path d="M14 3h7v7" /><path d="M10 14L21 3" /><path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5" />
            @break
        @case('check')
            <path d="M20 6L9 17l-5-5" />
            @break
        @case('return')
            <path d="M9 14l-4-4 4-4" /><path d="M5 10h11a4 4 0 0 1 0 8h-1" />
            @break
        @case('refresh')
            <path d="M20 6v5h-5" /><path d="M4 18v-5h5" /><path d="M18.5 9A7 7 0 0 0 6.2 6.2L4 8" /><path d="M5.5 15A7 7 0 0 0 17.8 17.8L20 16" />
            @break
        @case('printer')
            <path d="M6 9V3h12v6" /><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" /><path d="M6 14h12v7H6v-7Z" />
            @break
        @case('download')
            <path d="M12 3v12" /><path d="M7 10l5 5 5-5" /><path d="M5 21h14" />
            @break
        @case('search')
            <path d="m21 21-4.35-4.35" /><path d="M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16Z" />
            @break
        @case('filter')
            <path d="M4 5h16" /><path d="M7 12h10" /><path d="M10 19h4" />
            @break
        @case('x')
            <path d="M18 6 6 18" /><path d="m6 6 12 12" />
            @break
        @case('clock')
            <path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z" /><path d="M12 7v5l3 2" />
            @break
        @case('edit')
            <path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5Z" />
            @break
        @case('trash')
            <path d="M3 6h18" /><path d="M8 6V4h8v2" /><path d="M6 6l1 15h10l1-15" /><path d="M10 11v6" /><path d="M14 11v6" />
            @break
        @case('shield')
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z" /><path d="M9 12l2 2 4-5" />
            @break
        @case('menu')
            <path d="M4 6h16" /><path d="M4 12h16" /><path d="M4 18h16" />
            @break
        @case('chevron-left')
            <path d="m15 18-6-6 6-6" />
            @break
        @case('chevron-right')
            <path d="m9 18 6-6-6-6" />
            @break
        @case('user')
            <path d="M20 21a8 8 0 0 0-16 0" /><path d="M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z" />
            @break
        @case('lock')
            <path d="M6 10V8a6 6 0 0 1 12 0v2" /><path d="M5 10h14v11H5V10Z" />
            @break
        @case('alert')
            <path d="M12 9v4" /><path d="M12 17h.01" /><path d="M10.3 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.7 3.86a2 2 0 0 0-3.4 0Z" />
            @break
        @default
            <path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z" />
    @endswitch
</svg>
