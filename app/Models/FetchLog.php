<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FetchLog extends Model
{
    protected $fillable = ['run_id', 'source', 'request', 'response_status', 'response_body', 'error', 'duration_ms'];
    protected $casts = ['request' => 'array'];
}
