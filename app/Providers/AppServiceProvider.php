<?php

namespace App\Providers;

use App\Models\Resume;
use App\Models\Run;
use App\Models\Vacancy;
use Illuminate\Support\Facades\View;
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
        $shared = null;
        View::composer(
            ['layouts.app', 'dashboard', 'vacancies.index', 'vacancies.show', 'runs.show'],
            function ($view) use (&$shared) {
                if ($shared === null) {
                    $shared = [
                        'globalCounts' => [
                            'total' => Vacancy::query()->count(),
                            'matched' => Vacancy::query()->where('status', 'matched')->count(),
                            'done' => Vacancy::query()->where('status', 'done')->count(),
                            'applied' => Vacancy::query()->whereNotNull('applied_at')->count(),
                        ],
                        'sidebarResume' => Resume::active(),
                        'sidebarLatestRun' => Run::query()->latest('id')->first(),
                    ];
                }
                $view->with($shared);
            }
        );
    }
}
