<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Resume extends Model
{
    protected $fillable = ['original_name', 'path', 'text', 'keywords', 'is_active'];
    protected $casts = ['keywords' => 'array', 'is_active' => 'boolean'];

    public static function active(): ?self
    {
        return static::query()->where('is_active', true)->latest()->first();
    }
}
