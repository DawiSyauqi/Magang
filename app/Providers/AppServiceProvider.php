<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\PaperExtraction\MesinAliasStore::class, function () {
            return \App\Services\PaperExtraction\MesinAliasStore::makeProduction();
        });

        $this->app->bind(
            \App\Services\PaperExtraction\Contracts\MesinCandidateProvider::class,
            \App\Services\PaperExtraction\Repositories\EloquentMesinCandidateProvider::class
        );

        // Pemeta kode masalah formulir → MFPROBLEMD (dipakai hasil AI Baca Kertas E6).
        $this->app->singleton(\App\Services\PaperExtraction\ProblemCodeResolver::class, function () {
            return \App\Services\PaperExtraction\ProblemCodeResolver::makeProduction();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();

        // Keamanan (saran 2.15): maks. 5 percobaan login per menit per username+IP,
        // dan 30 per menit per IP (mencegah tebak password beruntun).
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('username')).'|'.$request->ip())
                ->response(fn () => back()->withInput($request->only('username'))
                    ->withErrors(['username' => 'Terlalu banyak percobaan login. Coba lagi dalam 1 menit.'])),
            Limit::perMinute(30)->by($request->ip()),
        ]);

        if (request()->header('X-Forwarded-Proto') === 'https'){
            URL::forceScheme('https');
        }
    }
}