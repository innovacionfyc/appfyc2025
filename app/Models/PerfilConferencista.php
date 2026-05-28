<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasAuditFields;

class PerfilConferencista extends Model
{
    use SoftDeletes, HasAuditFields;
    protected $table = 'perfil_conferencistas';

     protected $fillable = [
        'usuario_id',
        'primer_nombre',
        'segundo_nombre',
        'primer_apellido',
        'segundo_apellido',
        'foto',
        'biografia',
        'telefono',
        'correo',
        'url_hv',
        'areas_encargadas',
       
    ];

    protected $casts = [
        'areas_encargadas' => 'array', 
    ];


    public function usuario() {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
    public function areaEncargada() {
        return $this->belongsTo(AreaFormacion::class, 'area_encargada_id');
    }
    
    public function eventosParticipados() {
        return $this->belongsToMany(Evento::class, 'evento_conferencista', 'conferencista_id', 'evento_id')->withTimestamps();
    }
    public function contenidosImpartidos() {
        return $this->belongsToMany(ContenidoTematico::class, 'contenido_conferencista', 'conferencista_id', 'contenido_tematico_id')->withTimestamps();
    }
}