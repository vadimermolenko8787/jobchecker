<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Run extends Model
{
    protected $fillable = ['trigger', 'status', 'stats', 'log', 'started_at', 'finished_at'];
    protected $casts = [
        'stats' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function fetchLogs(): HasMany
    {
        return $this->hasMany(FetchLog::class);
    }

    public function appendLog(string $line): void
    {
        $this->log = ($this->log ? $this->log . "\n" : '') . '[' . now()->format('H:i:s') . '] ' . $line;
        $this->save();
    }
}
