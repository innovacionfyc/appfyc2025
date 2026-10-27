<?php

namespace App\Support\CredentialFlow\Programacion;

use App\Models\CredentialFlow\EmisionProgramada;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Usuario;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que la pantalla de la base muestra de la emisión programada: la programación activa (o la última terminada), el estado del programador automático según
 * su último latido y los límites para el selector de fecha. Solo presenta: las reglas viven en ServicioProgramacion y EjecutorProgramado. Sin datos
 * personales de los participantes (las cifras son cantidades) y, de los administradores, solo el nombre de quien programó o aprobó.
 */
final class PresentadorProgramacion
{
    /** Un latido más viejo que esto significa que la tarea programada del servidor no está corriendo. */
    public const LATIDO_VIGENTE_MINUTOS = 3;

    /** Una programación terminada se sigue mostrando (como resultado) durante estos días. */
    public const DIAS_ULTIMA = 14;

    public const PARTES = [
        'base' => 'Datos de la base (evento, fecha, intensidad, estado…)',
        'participantes' => 'Participantes pendientes (nombres, documentos, elegibilidad o nuevos)',
        'plantilla' => 'Plantilla oficial',
        'variante_qa' => 'Variante de pruebas internas (QA)',
        'vista_previa' => 'Confirmación de la vista previa',
    ];

    public function __construct(private readonly ServicioProgramacion $servicio) {}

    /** @return array<string,mixed>|null null si la tabla aún no existe (despliegue en curso) */
    public function para(Lote $lote): ?array
    {
        if (! Schema::hasTable('cf_emisiones_programadas')) {
            return null;
        }
        $activa = $this->servicio->activaDe($lote->id);
        $ultima = $activa === null ? EmisionProgramada::query()->where('lote_id', $lote->id)->where('updated_at', '>=', now()->subDays(self::DIAS_ULTIMA))
            ->orderByDesc('id')->first() : null;
        $zona = FechaProgramada::zona();
        $ahora = CarbonImmutable::now($zona);

        return [
            'habilitada' => ServicioProgramacion::habilitada(),
            'puede_gestionar' => ServicioProgramacion::autorizado(($u = auth()->user()) instanceof Usuario ? $u : null),
            'programador' => $this->programador(),
            'activa' => $activa === null ? null : $this->programacion($activa),
            'ultima' => $ultima === null ? null : $this->programacion($ultima),
            'limites' => [
                'zona' => 'Hora de Colombia (UTC−5)',
                'minimo_minutos' => (int) config('credential_flow.programacion.minimo_minutos', 5),
                'maximo_dias' => (int) config('credential_flow.programacion.maximo_dias', 90),
                // Para el <input type="datetime-local">: en hora de Colombia, sin zona.
                'min' => $ahora->addMinutes((int) config('credential_flow.programacion.minimo_minutos', 5) + 1)->format('Y-m-d\TH:i'),
                'max' => $ahora->addDays((int) config('credential_flow.programacion.maximo_dias', 90))->format('Y-m-d\TH:i'),
            ],
            'plantilla' => $lote->plantilla?->nombre,
        ];
    }

    /** @return array<string,mixed> */
    public function programacion(EmisionProgramada $p): array
    {
        $p->refresh();
        $zona = FechaProgramada::zona();
        $cuando = CarbonImmutable::parse($p->programada_para->format('Y-m-d H:i:s'), $zona);
        $cambios = match ($p->estado) {
            EmisionProgramada::PENDIENTE => $this->servicio->cambios($p),
            EmisionProgramada::REQUIERE_CONFIRMACION => array_values(array_unique([...(array) ($p->resultado['cambios'] ?? []), ...$this->servicio->cambios($p)])),
            default => [],
        };
        $resultado = (array) ($p->resultado ?? []);

        return [
            'id' => $p->id,
            'estado' => $p->estado,
            'version' => $p->version,
            'situacion' => $this->situacion($p),
            'programada_para' => $cuando->format('Y-m-d\TH:i'),
            'programada_para_texto' => self::fechaLegible($cuando),
            'programada_por' => self::nombre($p->programada_por),
            'confirmada_por' => self::nombre($p->confirmada_por),
            'oficiales_esperados' => $p->oficiales_esperados,
            'qa_esperados' => $p->qa_esperados,
            'plantilla' => self::nombrePlantilla($p->plantilla_id),
            'variante_qa' => self::nombrePlantilla($p->variante_qa_id) ?? ($p->lote === null ? null : app(ServicioQa::class)->plantillaPara($p->lote)?->nombre),
            'cambios' => array_map(fn (string $parte) => self::PARTES[$parte] ?? $parte, $cambios),
            'error_codigo' => $p->error_codigo,
            // El ejecutor nombra las partes con su clave interna; la lista legible de qué cambió va en `cambios` (y entonces no se repite aquí).
            'error_mensaje' => $p->error_codigo !== EjecutorProgramado::CAMBIOS ? $p->error_mensaje
                : ($cambios === [] ? 'Algo cambió después de programar. No se emitió nada automáticamente.' : null),
            'con_avisos' => (bool) $p->con_avisos,
            'oficiales_emitidos' => (int) ($resultado['oficiales_emitidos'] ?? 0),
            'qa_emitidos' => (int) ($resultado['qa_emitidos'] ?? 0),
            'parcial' => (bool) ($resultado['parcial'] ?? false),
            'intentos' => $p->intentos,
            'proximo_intento_texto' => $p->proximo_intento_at === null ? null : CarbonImmutable::parse($p->proximo_intento_at->format('Y-m-d H:i:s'), $zona)->locale('es')->isoFormat('h:mm a'),
            'terminada_texto' => $p->terminada_at === null ? null : self::fechaLegible(CarbonImmutable::parse($p->terminada_at->format('Y-m-d H:i:s'), $zona)),
            'motivo_cancelacion' => $p->motivo_cancelacion,
            // Pistas para la interfaz; el servicio vuelve a decidir al recibir la acción.
            'modificable' => in_array($p->estado, EmisionProgramada::MODIFICABLES, true),
            // Sello de la base tal como se muestra AHORA (con estos cambios): «Revisar y confirmar» lo envía y el servicio rechaza si la base cambió después.
            'sello' => in_array($p->estado, EmisionProgramada::MODIFICABLES, true) && $p->lote !== null && ! $p->lote->trashed()
                ? ServicioProgramacion::sello(HuellaProgramacion::calcular($p->lote)['huella']) : null,
            'puede_reconfirmar' => in_array($p->estado, EmisionProgramada::MODIFICABLES, true)
                && $cuando->greaterThanOrEqualTo(CarbonImmutable::now($zona)->addMinutes((int) config('credential_flow.programacion.minimo_minutos', 5))),
        ];
    }

    /**
     * Estado del programador automático según su ÚLTIMO LATIDO real. Nunca dice «activo» si el interruptor está apagado o si nunca hubo un latido (la tarea
     * programada del servidor todavía no existe).
     *
     * `etiqueta` es el estado en pocas palabras (para el indicador de la pantalla) y `detalle` lo explica; `texto` es la frase completa.
     *
     * @return array{estado:string, etiqueta:string, detalle:string, texto:string}
     */
    public function programador(): array
    {
        if (! ServicioProgramacion::habilitada()) {
            return ['estado' => 'desactivado', 'etiqueta' => 'Programación automática desactivada', 'detalle' => 'Las programaciones no se ejecutarán.',
                'texto' => 'La emisión programada está desactivada: las programaciones no se ejecutarán.'];
        }
        $latido = Cache::get(EjecutorProgramado::LATIDO);
        if (! is_string($latido) || $latido === '') {
            return ['estado' => 'sin_senal', 'etiqueta' => 'Programador sin señal reciente',
                'detalle' => 'Todavía no ha dado señales (falta activar su tarea en el servidor): las emisiones programadas no se ejecutarán hasta entonces.',
                'texto' => 'El programador automático todavía no ha dado señales (falta activar su tarea en el servidor). Las emisiones programadas no se ejecutarán hasta entonces.'];
        }
        $ultimo = CarbonImmutable::parse($latido)->setTimezone(FechaProgramada::zona());
        $minutos = (int) floor($ultimo->diffInSeconds(CarbonImmutable::now(FechaProgramada::zona()), true) / 60);
        if ($minutos <= self::LATIDO_VIGENTE_MINUTOS) {
            $hace = $minutos === 0 ? 'hace menos de un minuto' : "hace {$minutos} ".($minutos === 1 ? 'minuto' : 'minutos');

            return ['estado' => 'activo', 'etiqueta' => 'Programador automático activo', 'detalle' => "Última señal {$hace}.",
                'texto' => "Programador automático activo · última señal {$hace}."];
        }

        return ['estado' => 'detenido', 'etiqueta' => 'Programador sin señal reciente',
            'detalle' => 'Sin señales desde el '.self::fechaLegible($ultimo).': las emisiones programadas no se ejecutarán hasta que vuelva.',
            'texto' => 'El programador automático no da señales desde el '.self::fechaLegible($ultimo).'. Las emisiones programadas no se ejecutarán hasta que vuelva.'];
    }

    /** «viernes 30 de octubre de 2026 a las 8:00 a. m.» (hora de Colombia) */
    public static function fechaLegible(CarbonInterface $fecha): string
    {
        $f = CarbonImmutable::parse($fecha->format('Y-m-d H:i:s'), FechaProgramada::zona())->locale('es');

        return $f->isoFormat('dddd D [de] MMMM [de] YYYY').' a las '.$f->isoFormat('h:mm a');
    }

    /** Título y explicación del estado, en palabras de todos los días. @return array{titulo:string, texto:string, tono:string} */
    private function situacion(EmisionProgramada $p): array
    {
        return match (true) {
            $p->estado === EmisionProgramada::PENDIENTE && $p->error_codigo !== null && $p->proximo_intento_at !== null => [
                'titulo' => 'Pendiente · se reintentará', 'tono' => 'aviso',
                'texto' => 'Hubo un problema pasajero al emitir y el programador lo volverá a intentar solo. No hace falta hacer nada todavía.'],
            $p->estado === EmisionProgramada::PENDIENTE => ['titulo' => 'Programada', 'tono' => 'info',
                'texto' => 'Los certificados se emitirán solos a esa hora, aunque nadie tenga la sesión abierta.'],
            $p->estado === EmisionProgramada::EJECUTANDO => ['titulo' => 'Emitiendo ahora', 'tono' => 'info',
                'texto' => 'El programador está emitiendo los certificados de esta base. Mientras tanto no se puede emitir a mano.'],
            $p->estado === EmisionProgramada::COMPLETADA && $p->con_avisos => ['titulo' => 'Completada con avisos', 'tono' => 'aviso',
                'texto' => 'Los certificados oficiales se emitieron, pero las pruebas internas (QA) no. Puedes emitirlas con «Emitir pruebas internas».'],
            $p->estado === EmisionProgramada::COMPLETADA => ['titulo' => 'Completada', 'tono' => 'exito',
                'texto' => 'La emisión programada terminó correctamente.'],
            $p->estado === EmisionProgramada::FALLIDA => ['titulo' => 'No se completó'.((bool) (($p->resultado ?? [])['parcial'] ?? false) ? ' (parcial)' : ''), 'tono' => 'error',
                'texto' => (bool) (($p->resultado ?? [])['parcial'] ?? false)
                    ? 'Una parte de los certificados alcanzó a emitirse y el resto no. Revisa el motivo; los pendientes puedes emitirlos a mano o programarlos de nuevo.'
                    : 'No se emitió ningún certificado. Revisa el motivo y vuelve a programar o emite a mano.'],
            $p->estado === EmisionProgramada::CANCELADA => ['titulo' => 'Cancelada', 'tono' => 'neutro',
                'texto' => 'Esta programación se canceló y no se emitirá.'],
            $p->estado === EmisionProgramada::REQUIERE_CONFIRMACION => ['titulo' => 'Requiere confirmación', 'tono' => 'aviso',
                'texto' => 'Algo cambió después de programar o quien la aprobó ya no puede emitir. No se emitirá nada hasta que la revises y la confirmes o reprogrames.'],
            default => ['titulo' => $p->estado, 'tono' => 'neutro', 'texto' => ''],
        };
    }

    private static function nombre(?int $usuarioId): ?string
    {
        if ($usuarioId === null) {
            return null;
        }
        $u = Usuario::query()->with('perfilOrganizador')->find($usuarioId);
        $nombre = trim(($u?->perfilOrganizador?->primer_nombre ?? '').' '.($u?->perfilOrganizador?->primer_apellido ?? ''));

        return $nombre !== '' ? $nombre : 'Un administrador';
    }

    private static function nombrePlantilla(?int $id): ?string
    {
        return $id === null ? null : Plantilla::query()->whereKey($id)->value('nombre');
    }
}
