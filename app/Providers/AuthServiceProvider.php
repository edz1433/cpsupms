<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        //
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        Gate::define('view-campus', fn ($user, int $campusId) => $user->isUniversityWide() || $user->campus_id === $campusId);
        Gate::define('review-payroll', fn ($user) => in_array($user->role?->slug, ['super-administrator', 'university-payroll-administrator'], true));
        Gate::define('manage-settings', fn ($user) => $user->role?->slug === 'super-administrator');
    }
}
