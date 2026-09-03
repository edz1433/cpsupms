<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0B6E2E">
    <link rel="apple-touch-icon" href="{{ asset('images/cpsu-logo.png') }}">
    <title>{{ $title ?? 'CPSU Payroll Management System' }}</title>
    @include('partials.styles')
</head>
<body class="guest-body">
    {{ $slot }}
</body>
</html>
