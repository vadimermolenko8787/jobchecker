<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $fillable = ['name', 'normalized_name', 'research', 'researched_at', 'last_error'];

    protected $casts = ['research' => 'array', 'researched_at' => 'datetime'];

    /**
     * Legal suffixes (GmbH, Inc.) are kept on purpose: "Acme GmbH" and
     * "Acme Inc." may be different employers.
     */
    public static function normalize(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = (string) preg_replace('/\s+/u', ' ', $name);

        return trim($name, " \t.,;");
    }

    public static function forName(?string $name): ?self
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        return static::query()->where('normalized_name', static::normalize($name))->first();
    }

    public static function firstOrCreateForName(string $name): self
    {
        return static::query()->firstOrCreate(
            ['normalized_name' => static::normalize($name)],
            ['name' => trim($name)],
        );
    }
}
