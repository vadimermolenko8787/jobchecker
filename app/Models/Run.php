<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Run extends Model
{
    /**
     * A process killed from outside cannot mark its own run finished, so 'running' alone does
     * not mean alive. Past this age the row is presumed abandoned rather than active.
     */
    public const STALE_AFTER_HOURS = 2;

    protected $fillable = ['trigger', 'status', 'stats', 'log', 'started_at', 'finished_at'];
    protected $casts = [
        'stats' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** The search that is really running now, ignoring rows a killed process left behind. */
    public static function active(): ?self
    {
        return static::query()->where('status', 'running')
            ->where('started_at', '>', now()->subHours(self::STALE_AFTER_HOURS))
            ->latest('id')->first();
    }

    /** @return Collection<int, self> запуски, брошенные убитым процессом в статусе running */
    public static function stale(): Collection
    {
        return static::query()->where('status', 'running')
            ->where('started_at', '<=', now()->subHours(self::STALE_AFTER_HOURS))
            ->orderBy('id')->get();
    }

    public function fetchLogs(): HasMany
    {
        return $this->hasMany(FetchLog::class);
    }

    public function appendLog(string $line): void
    {
        $this->log = ($this->log ? $this->log . "\n" : '') . '[' . now()->format('H:i:s') . '] ' . $line;
        $this->save();
    }

    /**
     * Stopping is cooperative: the web request only raises this flag and the search runs in
     * another process, which notices it at its next checkpoint. Cache is the channel between
     * the two, so a single bit does not need a column of its own; the key carries the run id,
     * so a flag nobody consumed cannot leak into the next run.
     */
    public function requestStop(): void
    {
        Cache::put($this->stopKey(), true, now()->addHours(3));
    }

    public function stopRequested(): bool
    {
        return Cache::has($this->stopKey());
    }

    private function stopKey(): string
    {
        return "run-stop:{$this->id}";
    }
}
