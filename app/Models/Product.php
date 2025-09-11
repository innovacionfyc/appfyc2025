<?php

// app/Models/Product.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'price',
        'category_id',
        'image_url',
        'is_available',
    ];

    /**
     * Un producto pertenece a una categoría.
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Un producto se compone de muchos ingredientes (relación muchos a muchos).
     */
    public function inventoryItems()
    {
        return $this->belongsToMany(InventoryItem::class)
                    ->withPivot('quantity_used'); // Para poder acceder a la cantidad usada en la receta
    }
}
