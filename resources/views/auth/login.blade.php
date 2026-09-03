<x-layouts.guest title="Sign in - CPSU Payroll">
    <div class="login-wrap">
        <div class="login-logo"><img src="{{ asset('images/cpsu-logo.png') }}" alt="CPSU Seal"></div>
        <div class="login-title">
            <h1>CPSU Payroll Management System</h1>
            <p>Central Philippines State University</p>
        </div>
        <form class="login-card" method="POST" action="{{ route('login.store') }}">
            @csrf
            <h2>Sign in</h2>
            <p class="login-subtitle">Authorized payroll personnel access</p>
            @error('email')<div class="alert danger"><x-icon name="alert" /> <span>{{ $message }}</span></div>@enderror
            <label class="field">
                <span>Email address</span>
                <div class="input-icon">
                    <x-icon name="user" />
                    <input class="input" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email">
                </div>
            </label>
            <label class="field">
                <span>Password</span>
                <div class="input-icon">
                    <x-icon name="lock" />
                    <input class="input" name="password" type="password" required autocomplete="current-password">
                </div>
            </label>
            <label class="field checkline">
                <input name="remember" type="checkbox" value="1"> <span>Remember me</span>
            </label>
            <button class="primary-btn" style="width:100%;margin-top:16px" type="submit"><x-icon name="shield" /> Sign in securely</button>
        </form>
    </div>
</x-layouts.guest>
