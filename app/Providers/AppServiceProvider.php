<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Ligne de base sécurité : throttling global de l'API, par utilisateur
        // authentifié ou par IP pour les appels anonymes (cf. étude d'architecture).
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // verify-otp : le throttle par IP seul (route api.php) est
        // contournable en changeant d'IP entre chaque tentative — celui-ci
        // limite en plus par compte ciblé (user_id du body), quelle que soit
        // l'IP d'origine.
        RateLimiter::for('otp-verify', function (Request $request) {
            return Limit::perMinute(5)->by('otp-verify:'.$request->input('user_id'));
        });
    }
}
