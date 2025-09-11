<?php

// app/Models/OrderItem.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'quantity',
        'price',
        'notes',
        'station'
    ];

    /**
     * Un item de la orden pertenece a una orden.
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Un item de la orden corresponde a un producto del menú.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}