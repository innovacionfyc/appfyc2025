<?php

// app/Models/Table.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Table extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'status'];

    /**
     * Una mesa puede tener muchas órdenes a lo largo del tiempo.
     */
    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}