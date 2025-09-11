<?php

// app/Models/Order.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'table_id',
        'user_id',
        'total',
        'status',
    ];

    /**
     * Una orden pertenece a una mesa.
     */
    public function table()
    {
        return $this->belongsTo(Table::class);
    }

    /**
     * Una orden fue creada por un usuario (mesero).
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Una orden tiene muchos items/productos.
     */
    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Una orden tiene una factura asociada.
     */
    public function invoice()
    {
        return $this->hasOne(Invoice::class);
    }
}
