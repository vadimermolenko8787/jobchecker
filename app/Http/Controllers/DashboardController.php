<?php

namespace App\Http\Controllers;

use App\Models\Resume;
use App\Models\Run;
use App\Models\Setting;
use App\Models\Vacancy;

class DashboardController extends Controller
{
    public function index()
    {
        return view('dashboard', [
            'settings' => Setting::all_settings(),
            'resume' => Resume::active(),
            'runs' => Run::query()->latest('id')->limit(10)->get(),
            'counts' => [
                'total' => Vacancy::query()->count(),
                'matched' => Vacancy::query()->where('status', 'matched')->count(),
                'done' => Vacancy::query()->where('status', 'done')->count(),
            ],
        ]);
    }
}
