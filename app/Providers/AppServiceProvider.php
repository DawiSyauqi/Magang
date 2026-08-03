<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Default Laravel 11 pakai style Tailwind untuk pagination — diganti
        // ke Bootstrap 5 supaya konsisten dengan Bootstrap yang dipakai
        // di seluruh aplikasi ini.
        Paginator::useBootstrapFive();
    }
}
