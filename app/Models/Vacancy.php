<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vacancy extends Model
{
    protected $fillable = [
        'source', 'external_id', 'title', 'company', 'location', 'url',
        'description', 'salary', 'published_at', 'raw', 'status',
        'score', 'score_reason', 'analysis', 'applied_at', 'resume_path', 'cover_letter_path', 'run_id',
    ];
    protected $casts = ['raw' => 'array', 'analysis' => 'array', 'published_at' => 'datetime', 'applied_at' => 'datetime'];
}
