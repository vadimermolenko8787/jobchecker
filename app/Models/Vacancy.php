<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vacancy extends Model
{
    protected $fillable = [
        'source', 'external_id', 'title', 'company', 'location', 'url',
        'description', 'salary', 'published_at', 'bumped_at', 'notified_at', 'raw', 'status',
        'score', 'score_reason', 'score_breakdown', 'analysis', 'applied_at', 'resume_path', 'cover_letter_path', 'run_id',
    ];
    protected $casts = [
        'raw' => 'array',
        'analysis' => 'array',
        'score_breakdown' => 'array',
        'published_at' => 'datetime',
        'bumped_at' => 'datetime',
        'notified_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    /**
     * Identity of the job itself rather than of the row: boards carry the same vacancy
     * under their own external ids, so company plus title is what makes two rows one job.
     * Company::normalize() is the same case/whitespace cleanup both fields need.
     */
    public function duplicateKey(): string
    {
        return Company::normalize((string) $this->company) . '|' . Company::normalize($this->title);
    }
}
