<?php

namespace App\Providers;

use App\Models\Resume;
use App\Models\Run;
use App\Models\Setting;
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
        try {
            $locale = Setting::get('locale');
        } catch (\Throwable) {
            // DB not migrated yet (e.g. during composer install), keep the configured locale.
            $locale = null;
        }
        if (in_array($locale, Setting::LOCALES, true)) {
            app()->setLocale($locale);
        }

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
