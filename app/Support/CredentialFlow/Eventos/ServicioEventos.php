<?php

namespace App\Support\CredentialFlow\Eventos;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Asociación de las BASES (lotes) de participantes con un EVENTO de certificación (`cf_eventos`, la misma tabla del histórico) y su consulta agregada.
 * Un evento puede tener VARIAS bases (p. ej. presencial y virtual). Nada de esto modifica emisiones, participantes ni certificados ya emitidos: solo
 * `cf_lotes.evento_id`, la marca `cf_participantes.es_control_qa` y la creación de eventos NATIVOS (origen `credential_flow`).
 *
 * Asociar por nombre y año NUNCA decide en silencio cuando hay duda: ante cualquier ambigüedad exige que el administrador elija el evento o confirme crear uno nuevo.
 */
final class ServicioEventos
{
    public const UNIR = 'unir';

    public const CREAR = 'crear';

    public const AMBIGUA = 'ambigua';

    private const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'setiembre', 'octubre', 'noviembre', 'diciembre'];

    /**
     * Qué ocurriría al asociar la base a su evento, SIN escribir nada.
     *  - `unir`: hay UN solo evento nativo con el mismo nombre normalizado y año (y fechas compatibles).
     *  - `crear`: no existe ninguno (ni nativo ni histórico homónimo) y el año es conocido.
     *  - `ambigua`: año desconocido, varios candidatos, fechas en meses distintos u homónimo histórico → hay que elegir o confirmar.
     *
     * @return array{accion:string,evento:?EventoCertificacion,candidatos:Collection<int,EventoCertificacion>,motivos:list<string>}
     */
    public function evaluar(Lote $lote): array
    {
        $nombre = trim((string) ($lote->datos_comunes['evento'] ?? ''));
        if ($nombre === '') {
            throw new InvalidArgumentException('La base no tiene nombre de evento.');
        }
        $fecha = (string) ($lote->datos_comunes['fecha'] ?? '');
        $norm = NombreConservador::normalizar($nombre);
        $anio = self::anioDe($fecha);
        $motivos = [];

        $mismoNombre = EventoCertificacion::query()->where('origen', EventoCertificacion::ORIGEN_CREDENTIAL_FLOW)->where('nombre_normalizado', $norm)->orderBy('id')->get();
        if ($anio === null) {
            $motivos[] = 'La fecha de la base no indica el año, así que no se puede saber a qué edición del evento pertenece.';

            return ['accion' => self::AMBIGUA, 'evento' => null, 'candidatos' => $mismoNombre, 'motivos' => $motivos];
        }

        $candidatos = $mismoNombre->where('anio', $anio)->values();
        $historico = EventoCertificacion::query()->where('origen', EventoCertificacion::ORIGEN_LEGADO)->where('nombre_normalizado', $norm)->where('anio', $anio)->exists();
        if ($historico) {
            $motivos[] = 'Existe un evento histórico con el mismo nombre y año: confirma que este es un evento nuevo y distinto.';
        }
        if ($candidatos->count() > 1) {
            $motivos[] = 'Hay varios eventos con el mismo nombre y año: elige a cuál pertenece la base.';
        }
        if ($candidatos->count() === 1 && self::mesesDistintos($fecha, (string) $candidatos->first()->fecha_texto)) {
            $motivos[] = 'Las fechas de la base y del evento caen en meses distintos: confirma que son el mismo evento.';
        }
        if ($motivos !== []) {
            return ['accion' => self::AMBIGUA, 'evento' => null, 'candidatos' => $candidatos, 'motivos' => $motivos];
        }

        return $candidatos->count() === 1
            ? ['accion' => self::UNIR, 'evento' => $candidatos->first(), 'candidatos' => $candidatos, 'motivos' => []]
            : ['accion' => self::CREAR, 'evento' => null, 'candidatos' => $candidatos, 'motivos' => []];
    }

    /**
     * Asocia la base a su evento. Es IDEMPOTENTE (una base ya asociada se devuelve tal cual). Sin elección explícita solo actúa si no hay ambigüedad;
     * con `$eventoId` une la base al evento NATIVO elegido; con `$crearNuevo` el administrador confirma que es un evento nuevo.
     *
     * @throws AsociacionAmbiguaException cuando hay duda y no se indicó ni evento ni confirmación
     */
    public function asociarDesdeDatosDeLaBase(Lote $lote, ?int $eventoId = null, bool $crearNuevo = false): EventoCertificacion
    {
        return DB::transaction(function () use ($lote, $eventoId, $crearNuevo) {
            $lote = Lote::query()->whereKey($lote->id)->lockForUpdate()->firstOrFail(); // dos clics sobre la misma base se serializan
            if ($lote->evento_id !== null && ($actual = EventoCertificacion::query()->find($lote->evento_id)) !== null && ($eventoId === null || (int) $eventoId === (int) $actual->id)) {
                return $actual;
            }

            if ($eventoId !== null) {
                $evento = EventoCertificacion::query()->findOrFail($eventoId);
            } else {
                $e = $this->evaluar($lote);
                if ($e['accion'] === self::AMBIGUA && ! $crearNuevo) {
                    throw new AsociacionAmbiguaException($e['motivos'], $e['candidatos']);
                }
                $evento = $e['accion'] === self::UNIR && ! $crearNuevo ? $e['evento'] : $this->crearDesdeBase($lote);
            }
            $this->asociar($lote, $evento);

            return $evento;
        });
    }

    private function crearDesdeBase(Lote $lote): EventoCertificacion
    {
        $nombre = trim((string) ($lote->datos_comunes['evento'] ?? ''));
        $fecha = (string) ($lote->datos_comunes['fecha'] ?? '');
        $evento = EventoCertificacion::create([
            'nombre' => $nombre,
            'nombre_normalizado' => NombreConservador::normalizar($nombre),
            'anio' => self::anioDe($fecha),
            'fecha_texto' => $fecha !== '' ? mb_substr($fecha, 0, 120) : null,
            'estado' => EventoCertificacion::ESTADO_ACTIVO,
            'origen' => EventoCertificacion::ORIGEN_CREDENTIAL_FLOW,
        ]);
        Movimiento::registrar(tipo: 'registro', modulo: 'credential-flow', descripcion: 'Se creó un evento de certificación desde una base', extra: ['evento_id' => $evento->id, 'lote_id' => $lote->id]);

        return $evento;
    }

    /** Asocia (o cambia) la base a un evento NATIVO existente. Solo toca `cf_lotes.evento_id`. */
    public function asociar(Lote $lote, EventoCertificacion $evento): void
    {
        if ($evento->origen !== EventoCertificacion::ORIGEN_CREDENTIAL_FLOW) {
            throw new InvalidArgumentException('Solo se pueden asociar bases a eventos creados en Credential Flow.');
        }
        if ((int) $lote->evento_id !== (int) $evento->id) {
            $lote->update(['evento_id' => $evento->id]);
            Movimiento::registrar(tipo: 'actualizacion', modulo: 'credential-flow', descripcion: 'Se asoció una base a un evento de certificación', extra: ['evento_id' => $evento->id, 'lote_id' => $lote->id]);
        }
    }

    public function desasociar(Lote $lote): void
    {
        if ($lote->evento_id !== null) {
            $anterior = $lote->evento_id;
            $lote->update(['evento_id' => null]);
            Movimiento::registrar(tipo: 'actualizacion', modulo: 'credential-flow', descripcion: 'Se quitó la asociación de una base con su evento', extra: ['evento_id' => $anterior, 'lote_id' => $lote->id]);
        }
    }

    /**
     * Marca (o desmarca) participantes concretos de la base como de control (QA). Devuelve cuántos cambiaron. No toca emisiones, PDF, códigos ni estados.
     *
     * @param  Collection<int,int>|list<int>  $participanteIds
     */
    public function marcarParticipantesQa(Lote $lote, Collection|array $participanteIds, bool $esQa): int
    {
        $ids = collect($participanteIds)->map(fn ($i) => (int) $i)->unique()->values();
        if ($ids->isEmpty()) {
            return 0;
        }

        $n = Participante::query()->where('lote_id', $lote->id)->whereIn('id', $ids)->where('es_control_qa', ! $esQa)->update(['es_control_qa' => $esQa]);
        if ($n > 0) {
            Movimiento::registrar(tipo: 'actualizacion', modulo: 'credential-flow', descripcion: $esQa ? 'Se marcaron participantes de una base como de control (QA)' : 'Se quitó la marca de control (QA) a participantes de una base', extra: ['lote_id' => $lote->id, 'cantidad' => $n]);
        }

        return $n;
    }

    /**
     * Emisiones de las bases de un evento filtradas por la naturaleza del PARTICIPANTE: `$qa` false = solo reales, true = solo QA, null = todas.
     * La clasificación es por participante (una base mixta aporta a ambos lados) y no depende de la base.
     *
     * @return Builder<Emision>
     */
    public static function emisiones(int $eventoId, ?bool $qa): Builder
    {
        return Emision::query()
            ->select('cf_emisiones.*')
            ->join('cf_participantes as p', 'p.id', '=', 'cf_emisiones.participante_id')
            ->whereIn('cf_emisiones.lote_id', Lote::query()->where('evento_id', $eventoId)->select('id'))
            ->when($qa !== null, fn ($q) => $q->where('p.es_control_qa', $qa));
    }

    /**
     * Resumen por evento nativo. Los participantes de control (QA) se cuentan APARTE: nunca suman a los participantes reales, aunque compartan base.
     *
     * @return array{reales:array{vigentes:int,revocadas:int,pendientes:int,participantes:int},qa:array{participantes:int,vigentes:int,revocadas:int},bases:int}
     */
    public function resumen(EventoCertificacion $evento): array
    {
        $bases = Lote::query()->where('evento_id', $evento->id)->count();
        $personas = fn (bool $qa) => (int) DB::table('cf_participantes as p')->join('cf_lotes as l', 'l.id', '=', 'p.lote_id')
            ->where('l.evento_id', $evento->id)->whereNull('l.deleted_at')->whereNull('p.deleted_at')->where('p.es_control_qa', $qa)->count();
        $emitidas = fn (bool $qa, string $estado) => self::emisiones($evento->id, $qa)->where('cf_emisiones.estado', $estado)->count();

        $participantes = $personas(false);
        $vigentes = $emitidas(false, Emision::EMITIDA);

        return [
            'reales' => ['vigentes' => $vigentes, 'revocadas' => $emitidas(false, Emision::REVOCADA), 'participantes' => $participantes, 'pendientes' => max(0, $participantes - $vigentes)],
            'qa' => ['participantes' => $personas(true), 'vigentes' => $emitidas(true, Emision::EMITIDA), 'revocadas' => $emitidas(true, Emision::REVOCADA)],
            'bases' => $bases,
        ];
    }

    /** Año (4 dígitos, 1990–2100) que aparece en el texto de fecha de la base, o null. */
    public static function anioDe(string $fecha): ?int
    {
        return preg_match('/\b(19[9]\d|20\d{2}|2100)\b/', $fecha, $m) === 1 ? (int) $m[1] : null;
    }

    /** @return list<string> meses (en minúscula, «setiembre» = «septiembre») que menciona un texto de fecha */
    public static function mesesDe(string $fecha): array
    {
        $texto = mb_strtolower($fecha);
        $meses = [];
        foreach (self::MESES as $mes) {
            if (preg_match('/\b'.$mes.'\b/u', $texto) === 1) {
                $meses[] = $mes === 'setiembre' ? 'septiembre' : $mes;
            }
        }

        return array_values(array_unique($meses));
    }

    /** Verdadero solo si AMBOS textos nombran meses y no comparten ninguno (sin meses no hay evidencia de que sean distintos). */
    public static function mesesDistintos(string $a, string $b): bool
    {
        $ma = self::mesesDe($a);
        $mb = self::mesesDe($b);

        return $ma !== [] && $mb !== [] && array_intersect($ma, $mb) === [];
    }
}
