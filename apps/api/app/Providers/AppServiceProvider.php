<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Catch mass-assignment and lazy-loading mistakes early outside production.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Every `can:` check goes through the role + bundle matrix (B3). Default deny.
        Gate::before(fn (User $user, string $ability) => Permissions::allows($user, $ability) ? true : null);

        RateLimiter::for('login', fn (Request $r) => [Limit::perMinute(5)->by(strtolower((string) $r->input('login')).'|'.$r->ip())]);
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(240)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('public', fn (Request $r) => Limit::perMinute(60)->by($r->ip()));
    }
}
