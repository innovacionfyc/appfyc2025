<?php

// app/Models/User.php
namespace App\Models;

// ... (otros 'use' statements)
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'is_active',
    ];

    // ... (otras propiedades como $hidden, $casts)

    /**
     * Un usuario pertenece a un rol.
     */
    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Un usuario (mesero) puede crear muchas órdenes.
     */
    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}