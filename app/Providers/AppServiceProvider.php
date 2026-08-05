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
        $this->app->singleton(\App\Services\PaperExtraction\MesinAliasStore::class, function () {
            return \App\Services\PaperExtraction\MesinAliasStore::makeProduction();
        });

        $this->app->bind(
            \App\Services\PaperExtraction\Contracts\MesinCandidateProvider::class,
            \App\Services\PaperExtraction\Repositories\EloquentMesinCandidateProvider::class
        );

        $this->app->singleton(\App\Services\PaperExtraction\Contracts\MesinAiResolver::class, function () {
            return \App\Services\PaperExtraction\OllamaMesinAiResolver::makeProduction();
        });

        $this->app->singleton(\App\Services\PaperExtraction\MesinResolver::class, function () {
            return \App\Services\PaperExtraction\MesinResolver::makeProduction();
        });

        // --- Tambahan: rantai dependency PaperExtractionProcessor ---
        $this->app->singleton(\App\Services\PaperExtraction\ProblemCodeResolver::class, function () {
            return \App\Services\PaperExtraction\ProblemCodeResolver::makeProduction();
        });

        $this->app->singleton(\App\Services\PaperExtraction\OperatorMatcher::class, function () {
            return \App\Services\PaperExtraction\OperatorMatcher::makeProduction();
        });

        $this->app->singleton(\App\Services\PaperExtraction\PaperExtractionProcessor::class, function ($app) {
            return new \App\Services\PaperExtraction\PaperExtractionProcessor(
                new \App\Services\PaperExtraction\GridTimeMerger(new \App\Services\PaperExtraction\ProblemCodeConverter()),
                $app->make(\App\Services\PaperExtraction\ProblemCodeResolver::class),
                $app->make(\App\Services\PaperExtraction\OperatorMatcher::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();
    }
}