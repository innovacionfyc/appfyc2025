<?php

// app/Models/InventoryItem.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'unit',
        'stock',
        'low_stock_threshold',
    ];

    /**
     * Un ingrediente puede ser parte de muchos productos (relación muchos a muchos).
     */
    public function products()
    {
        return $this->belongsToMany(Product::class)
                    ->withPivot('quantity_used');
    }
}
