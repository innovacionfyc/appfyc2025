<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'email',
        'password',
        'is_active',
        'role_id'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(fn($user) => $user->uuid = (string) Str::uuid());
    }

    
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }


    public function managedEvents(): HasMany
    {
        return $this->hasMany(Event::class, 'manager_id');
    }
}