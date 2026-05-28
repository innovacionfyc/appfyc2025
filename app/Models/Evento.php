<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasAuditFields;

class Evento extends Model
{
    use SoftDeletes, HasAuditFields;

    protected $table = 'eventos';

    protected $fillable = [
        'organizador_id',
        'formulario_base_id',
        'contenido_tematico_id',
        'estado_id',
        'area_formacion_id',
        'modo_evento',
        'titulo',
        'slug',
        'subtitulo',
        'imagen_relacionada',
        'modalidad',
        'url_folleto',
        'url_formulario_inscripcion',
        'ubicacion',
        'fecha_hora_inicio',
        'fecha_hora_fin',
        'precio_jornada',
        'precio_seminario',
        'precio_modulo',
        'precio_cng',
        'precio_curso_intensivo',
        'precio_diplomado',
        'tipo_evento',
        'texto_dinamico',
        'color_hex_secundario',
        'tiene_oferta_valor',
        'oferta_valor',
        'estilo_temario',
        'estilo_expertos',
        'estilo_card',
        'estilo_plantilla'

    ];

    protected $casts = [
        'fecha_hora' => 'datetime',
        'precio_jornada' => 'decimal:2',
        'precio_modulo' => 'decimal:2',
    ];

    public function organizador()
    {
        return $this->belongsTo(Usuario::class, 'organizador_id');
    }

    public function perfilOrganizador()
    {
        return $this->belongsTo(PerfilOrganizador::class, 'usuario_id');
    }

    public function estado()
    {
        return $this->belongsTo(Estado::class, 'estado_id');
    }

    public function conferencistas()
    {
        return $this->belongsToMany(
            PerfilConferencista::class,
            'evento_conferencista',
            'evento_id',
            'conferencista_id'
        )->withTimestamps();
    }

    public function formularioInscripcion()
    {
        return $this->belongsTo(FormularioInscripcion::class, 'formulario_base_id');
    }

    public function contenidoTematico()
    {
        return $this->belongsTo(ContenidoTematico::class, 'contenido_tematico_id');
    }

    public function areaFormacion()
    {
        return $this->belongsTo(AreaFormacion::class, 'area_formacion_id');
    }

    public function formularioBase()
    {
        return $this->belongsTo(FormularioBase::class, 'formulario_base_id');
    }

}