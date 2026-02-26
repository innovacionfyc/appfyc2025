<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasAuditFields;

class Usuario extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes, HasAuditFields;

    protected $table = 'usuarios';

    protected $fillable = [
        'estado_id',
        'perfil_completo',
        'correo_principal',
        'numero_documento',
        'contrasena',
        'ultima_sesion'
    ];

    protected $hidden = [
        'contrasena',
        'remember_token',
    ];

    protected $appends = ['rol'];
    public function getAuthPassword()
    {
        return $this->contrasena;
    }


    public function perfilOrganizador()
    {
        return $this->hasOne(PerfilOrganizador::class, 'usuario_id');
    }

    public function perfilConferencista()
    {
        return $this->hasOne(PerfilConferencista::class, 'usuario_id');
    }

    public function eventosOrganizados()
    {
        return $this->hasMany(Evento::class, 'organizador_id');
    }
    public function estado()
    {
        return $this->belongsTo(Estado::class, 'estado_id');
    }

    public function getRolAttribute()
    {
        // Verificamos si tiene perfil de organizador y retornamos el slug del rol
        if ($this->perfilOrganizador && $this->perfilOrganizador->rol) {
            return $this->perfilOrganizador->rol->slug;
        }

        // Si es conferencista, podrías retornar un string fijo o manejarlo según tu lógica
        if ($this->perfilConferencista) {
            return 'conferencista';
        }

        return null;
    }
}