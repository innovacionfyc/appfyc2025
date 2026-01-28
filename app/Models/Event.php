<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Event extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'title',
        'slug',
        'content',
        'start_date',
        'end_date',
        'location_type',
        'address',
        'status',
        'manager_id'
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($event) {
            $event->uuid = (string) Str::uuid();
            $event->slug = Str::slug($event->title);
        });
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function speakers(): BelongsToMany
    {
        return $this->belongsToMany(Speaker::class)
            ->withPivot('role_in_event')
            ->withTimestamps();
    }

  

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }
}