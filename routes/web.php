<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ResumeController;
use App\Http\Controllers\RunController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\VacancyController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::post('/resume', [ResumeController::class, 'store'])->name('resume.store');
Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
Route::post('/settings/telegram-test', [SettingsController::class, 'telegramTest'])->name('settings.telegram-test');

Route::post('/run', [RunController::class, 'start'])->name('run.start');
Route::get('/runs/latest', [RunController::class, 'latest'])->name('runs.latest');
Route::get('/runs/{run}', [RunController::class, 'show'])->name('runs.show');

Route::get('/vacancies', [VacancyController::class, 'index'])->name('vacancies.index');
Route::get('/vacancies/{vacancy}', [VacancyController::class, 'show'])->name('vacancies.show');
Route::get('/vacancies/{vacancy}/download/{doc}/{format?}', [VacancyController::class, 'download'])
    ->whereIn('doc', ['resume', 'cover_letter'])
    ->whereIn('format', ['md', 'html', 'pdf'])
    ->name('vacancies.download');
Route::get('/vacancies/{vacancy}/preview/resume', [VacancyController::class, 'previewResume'])
    ->name('vacancies.preview-resume');
Route::post('/vacancies/{vacancy}/generate/{doc}', [VacancyController::class, 'generate'])
    ->whereIn('doc', ['resume', 'cover_letter', 'both'])->name('vacancies.generate');
Route::post('/vacancies/{vacancy}/applied', [VacancyController::class, 'toggleApplied'])
    ->name('vacancies.applied');
Route::post('/vacancies/{vacancy}/research-company', [VacancyController::class, 'researchCompany'])
    ->name('vacancies.research-company');
