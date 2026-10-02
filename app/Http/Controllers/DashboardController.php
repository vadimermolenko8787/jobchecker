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
            'keywords' => Setting::get('search_keywords'),
            'resume' => Resume::active(),
            'runs' => Run::query()->latest('id')->limit(10)->get(),
            'counts' => [
                'total' => Vacancy::query()->count(),
                'matched' => Vacancy::query()->where('status', 'matched')->count(),
                'done' => Vacancy::query()->where('status', 'done')->count(),
            ],
            'bySource' => Vacancy::query()
                ->selectRaw("source, COUNT(*) AS total, SUM(CASE WHEN status = 'matched' THEN 1 ELSE 0 END) AS matched")
                ->groupBy('source')
                ->orderByDesc('total')
                ->get(),
        ]);
    }
}
