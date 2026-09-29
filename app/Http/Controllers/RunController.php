<?php

namespace App\Http\Controllers;

use App\Models\Run;

class RunController extends Controller
{
    public function start()
    {
        if (Run::active()) {
            return back()->with('error', __('A search is already running.'));
        }

        $php = escapeshellarg(PHP_BINARY);
        $artisan = escapeshellarg(base_path('artisan'));
        exec("nohup {$php} {$artisan} jobs:search --trigger=manual > /dev/null 2>&1 &");

        return back()->with('status', __('Search started in the background.'));
    }

    public function stop()
    {
        $run = Run::query()->where('status', 'running')->latest('id')->first();
        if (! $run) {
            return back()->with('error', __('No search is running.'));
        }

        // Only the flag is raised here, the search process itself ends the run: see Run::requestStop().
        $run->requestStop();

        return back()->with('status', __('Stop requested, the search will finish after the current step.'));
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
