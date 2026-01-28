<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Speaker extends Model
{
    protected $fillable = [
        'uuid',
        'full_name',
        'email',
        'specialty',
        'bio',
        'profile_photo',
        'social_links'
    ];

    protected $casts = [
        'social_links' => 'array'
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(fn($speaker) => $speaker->uuid = (string) Str::uuid());
    }

    
    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class)
            ->withPivot('role_in_event')
            ->withTimestamps();
    }
}