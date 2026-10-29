<?php

namespace App\Support\CredentialFlow\Emisiones;

use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use Illuminate\Support\Facades\Schema;

/**
 * Recorrido de una base: Evento → Importar → Revisar → Plantilla → Vista previa → Emitir → Descargar.
 *
 * Solo LEE el estado actual y propone el siguiente paso con lo que falta; no escribe nada y no reemplaza ninguna pantalla. «Revisar» no bloquea los pasos siguientes
 * (las personas con tipo de documento por confirmar o pendientes de validación simplemente no se emiten), pero se muestra mientras haya algo por resolver.
 */
final class FlujoDeBase
{
    public const PASOS = [
        'evento' => 'Evento',
        'importar' => 'Importar',
        'revisar' => 'Revisar',
        'plantilla' => 'Plantilla',
        'vista_previa' => 'Vista previa',
        'emitir' => 'Emitir',
        'descargar' => 'Descargar',
    ];

    public function __construct(private readonly ServicioVistaPrevia $vistaPrevia) {}

    /**
     * @param  array<string,mixed>  $resumen  el de `EmisorLote::resumen`
     * @return array<string,mixed>
     */
    public function para(Lote $lote, array $resumen): array
    {
        $reales = (int) $resumen['participantes'];
        $listos = (int) $resumen['pendientes'];
        $vigentes = (int) $resumen['vigentes'];
        $tipoPendiente = (int) ($resumen['tipo_pendiente'] ?? 0);
        $enValidacion = (int) ($resumen['en_validacion'] ?? 0);

        $conEvento = Schema::hasColumn('cf_lotes', 'evento_id') && $lote->evento_id !== null;
        $plantillaOk = ServicioVistaPrevia::plantillaValida($lote->plantilla);
        $yaEmitio = $this->vistaPrevia->yaEmitio($lote);
        // Tras un cambio autorizado de plantilla en una base ya emitida, la plantilla nueva vuelve a exigir su vista previa (ver ServicioVistaPrevia).
        $exenta = $this->vistaPrevia->exentaPorEmisiones($lote, $yaEmitio);
        $confirmada = $plantillaOk && $this->vistaPrevia->confirmada($lote);
        $generada = $plantillaOk && $this->vistaPrevia->generada($lote);
        $vistaOk = $plantillaOk && (! ServicioVistaPrevia::exigida() || $exenta || $confirmada);

        $hecho = [
            'evento' => $conEvento,
            'importar' => $reales > 0,
            'revisar' => $reales > 0 && $tipoPendiente === 0 && $enValidacion === 0,
            'plantilla' => $plantillaOk,
            'vista_previa' => $vistaOk,
            'emitir' => $vigentes > 0 && $listos === 0,
            'descargar' => false,
        ];

        // Siguiente paso: primero lo que falta para poder emitir; después emitir; después lo que queda por resolver; al final, descargar.
        $siguiente = match (true) {
            ! $hecho['evento'] => 'evento',
            ! $hecho['importar'] => 'importar',
            ! $hecho['plantilla'] => 'plantilla',
            ! $hecho['vista_previa'] => 'vista_previa',
            $listos > 0 => 'emitir',
            $tipoPendiente > 0 || $enValidacion > 0 => 'revisar',
            $vigentes > 0 => 'descargar',
            default => 'revisar',
        };

        $pasos = [];
        foreach (self::PASOS as $clave => $rotulo) {
            $estado = $clave === $siguiente ? 'actual' : ($hecho[$clave] ? 'hecho' : ($clave === 'revisar' && $reales > 0 ? 'atencion' : 'pendiente'));
            $pasos[] = ['clave' => $clave, 'rotulo' => $rotulo, 'estado' => $estado];
        }

        $faltan = [];
        if (! $conEvento) {
            $faltan[] = 'Asociar la base a un evento.';
        }
        if ($reales === 0) {
            $faltan[] = 'Importar o agregar participantes.';
        }
        if (! $plantillaOk) {
            $faltan[] = 'Elegir una plantilla con diseño guardado.';
        } elseif (! $vistaOk) {
            $faltan[] = $generada ? 'Confirmar la plantilla después de revisar la vista previa.' : 'Abrir y revisar la vista previa de un participante con la plantilla actual.';
        }
        if ($plantillaOk) {
            // La modalidad puede venir por persona: la general de la base solo hace falta para quien no tenga la suya. Se mira a quienes faltan por emitir; si ya
            // no queda nadie, a todos los reales (así no aparece un aviso falso cuando ya se emitió todo con modalidades propias).
            $porEmitir = $lote->participantes()->where('es_control_qa', false)->whereDoesntHave('emisiones')->get();
            if ($porEmitir->isEmpty()) {
                $porEmitir = $lote->participantes()->where('es_control_qa', false)->get();
            }
            $sinModalidad = DatosRequeridos::sinModalidad($lote->plantilla->diseno, $lote, $porEmitir);
            $conPropia = $porEmitir->isNotEmpty() && count($sinModalidad) < $porEmitir->count();
            $datosDelDiseno = DatosRequeridos::faltantes($lote->plantilla->diseno, $lote->datos_comunes ?? [], $conPropia);
            if ($datosDelDiseno !== []) {
                $faltan[] = 'Completar en «Editar base» lo que imprime la plantilla: '.implode(', ', $datosDelDiseno).'.';
            }
            if ($conPropia && $sinModalidad !== []) {
                $n = count($sinModalidad);
                $faltan[] = "{$n} ".($n === 1 ? 'participante sin modalidad' : 'participantes sin modalidad').' (complétala en cada uno o como modalidad general en «Editar base»).';
            }
        }
        if ($tipoPendiente > 0) {
            $faltan[] = "{$tipoPendiente} ".($tipoPendiente === 1 ? 'participante con tipo de documento por confirmar' : 'participantes con tipo de documento por confirmar').' (no se emiten hasta confirmarlo).';
        }
        if ($enValidacion > 0) {
            $faltan[] = "{$enValidacion} ".($enValidacion === 1 ? 'participante pendiente de validación' : 'participantes pendientes de validación').' (no se emiten hasta confirmarlos).';
        }
        if ($reales > 0 && $listos === 0 && $vigentes === 0) {
            $faltan[] = 'Todavía no hay participantes elegibles listos para emitir.';
        }
        if (! isset($resumen['qa'])) {
            $faltan[] = 'Incorporar los contactos de control (QA) para probar la descarga.';
        }

        return [
            'pasos' => $pasos,
            'siguiente' => $this->describir($siguiente, $listos, $tipoPendiente + $enValidacion, $generada),
            'faltan' => $faltan,
            'plantilla' => ['nombre' => $lote->plantilla?->nombre, 'valida' => $plantillaOk, 'puede_cambiar' => ! $yaEmitio],
            'vista_previa' => ['exigida' => ServicioVistaPrevia::exigida(), 'generada' => $generada, 'confirmada' => $confirmada, 'exenta_por_emisiones' => $exenta],
            'plantillas_disponibles' => $yaEmitio ? [] : $this->plantillasDisponibles(),
        ];
    }

    /** @return array{clave:string,titulo:string,detalle:string,accion:array<string,mixed>} */
    private function describir(string $clave, int $listos, int $porResolver, bool $generada): array
    {
        return match ($clave) {
            'evento' => ['clave' => $clave, 'titulo' => 'Asociar la base a un evento', 'detalle' => 'Crea o elige el evento en «Eventos» y asocia esta base (evita eventos duplicados).', 'accion' => ['tipo' => 'enlace', 'ruta' => 'credential-flow.eventos.index']],
            'importar' => ['clave' => $clave, 'titulo' => 'Importar participantes', 'detalle' => 'Esta base no tiene participantes: importa el Excel o agrega uno a uno.', 'accion' => ['tipo' => 'agregar']],
            'plantilla' => ['clave' => $clave, 'titulo' => 'Elegir la plantilla', 'detalle' => 'La plantilla de la base no tiene un diseño válido.', 'accion' => ['tipo' => 'plantilla']],
            'vista_previa' => $generada
                ? ['clave' => $clave, 'titulo' => 'Confirmar plantilla y vista previa', 'detalle' => 'Ya abriste la vista previa. Si se ve bien, confirma la plantilla para poder emitir.', 'accion' => ['tipo' => 'confirmar_vista_previa']]
                : ['clave' => $clave, 'titulo' => 'Revisar la vista previa', 'detalle' => 'Abre la vista previa de un participante para comprobar cómo queda el certificado.', 'accion' => ['tipo' => 'abrir_vista_previa']],
            'emitir' => ['clave' => $clave, 'titulo' => 'Emitir los certificados', 'detalle' => "Se emitirán {$listos} certificado(s) a participantes elegibles con documento confirmado.", 'accion' => ['tipo' => 'emitir']],
            'revisar' => ['clave' => $clave, 'titulo' => 'Revisar a los participantes', 'detalle' => $porResolver > 0 ? 'Hay participantes por confirmar (tipo de documento o validación). Edítalos en la tabla.' : 'Revisa los datos de los participantes.', 'accion' => ['tipo' => 'revisar']],
            default => ['clave' => 'descargar', 'titulo' => 'Descargar los certificados', 'detalle' => 'Descarga el ZIP de la base o los certificados uno a uno.', 'accion' => ['tipo' => 'descargar']],
        };
    }

    /** @return list<array{id:int,nombre:string}> */
    private function plantillasDisponibles(): array
    {
        return Plantilla::query()->whereNotNull('diseno')
            ->when(Schema::hasColumn('cf_plantillas', 'es_plantilla_qa'), fn ($q) => $q->where('es_plantilla_qa', false))
            ->latest()->get(['id', 'nombre', 'diseno'])
            ->filter(fn (Plantilla $p) => ServicioVistaPrevia::plantillaValida($p))
            ->map(fn (Plantilla $p) => ['id' => $p->id, 'nombre' => $p->nombre])->values()->all();
    }
}
