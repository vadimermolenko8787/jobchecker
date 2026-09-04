<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vacancy extends Model
{
    protected $fillable = [
        'source', 'external_id', 'title', 'company', 'location', 'url',
        'description', 'salary', 'published_at', 'bumped_at', 'raw', 'status',
        'score', 'score_reason', 'score_breakdown', 'analysis', 'applied_at', 'resume_path', 'cover_letter_path', 'run_id',
    ];
    protected $casts = [
        'raw' => 'array',
        'analysis' => 'array',
        'score_breakdown' => 'array',
        'published_at' => 'datetime',
        'bumped_at' => 'datetime',
        'applied_at' => 'datetime',
    ];
}
