<?php

// app/Models/Invoice.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'invoice_number',
        'subtotal',
        'taxes',
        'total',
        'payment_method',
    ];

    /**
     * Una factura pertenece a una orden.
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}