<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class Movimiento extends Model
{
    protected $fillable = ['user_id', 'tipo', 'modulo', 'descripcion', 'metadata'];

    protected $casts = [
        'metadata' => 'array',
    ];

  
    public static function registrar($tipo, $modulo, $descripcion, $extra = [])
    {
        return self::create([
            'user_id'     => Auth::id(),
            'tipo'        => $tipo,
            'modulo'      => $modulo,
            'descripcion' => $descripcion,
            'metadata'    => array_merge([
                'ip' => request()->ip(),
                'ua' => request()->userAgent(),
            ], $extra),
        ]);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }
}