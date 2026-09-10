<?php

namespace App\Http\Controllers;

use App\Models\Run;

class RunController extends Controller
{
    public function start()
    {
        if (Run::active()) {
            return back()->with('error', 'Поиск уже выполняется.');
        }

        $php = escapeshellarg(PHP_BINARY);
        $artisan = escapeshellarg(base_path('artisan'));
        exec("nohup {$php} {$artisan} jobs:search --trigger=manual > /dev/null 2>&1 &");

        return back()->with('status', 'Поиск запущен в фоне.');
    }

    public function stop()
    {
        $run = Run::query()->where('status', 'running')->latest('id')->first();
        if (! $run) {
            return back()->with('error', 'Активного поиска нет.');
        }

        // Only the flag is raised here, the search process itself ends the run: see Run::requestStop().
        $run->requestStop();

        return back()->with('status', 'Остановка запрошена, поиск завершится после текущего шага.');
    }

    public function latest()
    {
        $run = Run::query()->latest('id')->first();

        return response()->json($run ? [
            'id' => $run->id,
            'status' => $run->status,
            'trigger' => $run->trigger,
            'stats' => $run->stats,
            'log' => $run->log,
            'started_at' => $run->started_at?->format('d.m H:i:s'),
            'finished_at' => $run->finished_at?->format('d.m H:i:s'),
        ] : null);
    }

    public function show(Run $run)
    {
        return view('runs.show', [
            'run' => $run,
            'fetchLogs' => $run->fetchLogs()->orderBy('id')->get(),
        ]);
    }
}
