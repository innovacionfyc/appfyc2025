<?php

namespace App\Support\CredentialFlow\Emisiones;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\Usuario;
use App\Support\CredentialFlow\Generacion\GeneracionCredencialException;
use Throwable;

/**
 * «Corregir toda la base» desde el panel: la misma reemisión masiva controlada que `credential-flow:base-reemitir` (ReemisorBase), con las mismas reglas:
 *
 *  - Revisar no escribe nada: cantidades por grupo, motivos, bloqueos y una huella de TODO lo revisado (ambos grupos).
 *  - Confirmar exige esa huella: con la base reservada se vuelve a planear y, si algo cambió desde la revisión, no se reemite nada.
 *  - Si un grupo con pendientes está bloqueado, no empieza ninguno (nunca queda la base a medias por algo que se sabía antes).
 *  - Primero los oficiales y después QA; cada grupo es su propia operación todo-o-nada. Si fallan los oficiales no se intenta QA (nada cambió); si fallan los QA,
 *    los oficiales quedan hechos (PARCIAL) y QA intacto. Reintentar solo toma lo que sigue desactualizado: nunca duplica versiones.
 *  - Solo trabaja sobre la base elegida (sus datos comunes y sus emisiones). No toca el evento de certificación ni otras bases. No envía correos.
 *  - Solo el super-admin puede usarla (las rutas lo exigen y `ejecutar` lo vuelve a comprobar). El admin corrige persona por persona («Reemitir certificado»).
 */
final class CorreccionBase
{
    public const COMPLETO = 'COMPLETO';

    public const PARCIAL = 'PARCIAL';

    public const SIN_CAMBIOS = 'SIN_CAMBIOS';

    /** Roles que pueden corregir toda una base desde el panel. */
    public const ROLES = ['super-admin'];

    private const GRUPOS = [ReemisorBase::OFICIALES, ReemisorBase::QA];

    public function __construct(private readonly ReemisorBase $reemisor) {}

    public static function puedeUsar(?Usuario $usuario): bool
    {
        return $usuario !== null && in_array($usuario->loadMissing('perfilOrganizador.rol')->rol, self::ROLES, true);
    }

    /**
     * @return array{grupos:array<string,array<string,mixed>>, total:int, bloqueo:?array<string,mixed>, huella:string}
     */
    public function planear(Lote $lote): array
    {
        $planes = array_map(fn (string $g) => $this->reemisor->planear($lote, $g), self::GRUPOS);
        $bloqueo = null;
        foreach ($planes as $p) {
            if ($p['bloqueo'] !== null && $p['a_reemitir'] > 0) {
                $bloqueo ??= ['grupo' => $p['grupo']] + $p['bloqueo'];
            }
        }

        $grupos = [];
        foreach ($planes as $p) {
            $grupos[$p['grupo']] = [
                'plantilla' => $p['plantilla'],
                'vigentes' => $p['vigentes'],
                'desactualizadas' => $p['desactualizadas'],
                'a_reemitir' => $p['a_reemitir'],
                'ya_correctas' => $p['vigentes'] - $p['desactualizadas'],
                'motivos' => $p['motivos'],
                'bloqueo' => $p['bloqueo'],
                'personas' => $this->personas($p['emisiones']),
            ];
        }

        return [
            'grupos' => $grupos,
            'total' => array_sum(array_column($planes, 'a_reemitir')),
            'bloqueo' => $bloqueo,
            'huella' => self::huella($lote, $planes),
        ];
    }

    /**
     * @return array{estado:string, grupos:array<string,array<string,mixed>>, correos_enviados:int}
     *
     * @throws EmisionException si el usuario no es super-admin (NO_AUTORIZADO, antes de tocar nada) o si otra corrección de la base está en curso
     * @throws CorreccionBaseException si no se reemitió nada por un error (huella, bloqueo o fallo del primer grupo); `resultado` lleva el detalle por grupo
     */
    public function ejecutar(Lote $lote, string $huella, string $motivo, Usuario $usuario): array
    {
        if (! self::puedeUsar($usuario)) {
            throw new EmisionException(EmisionException::NO_AUTORIZADO, 'Solo un super-administrador puede corregir toda la base.', 403);
        }

        return AdministradorAutorizado::corregir($usuario, $lote->id, function () use ($lote, $huella, $motivo, $usuario) {
            $planes = array_map(fn (string $g) => $this->reemisor->planear($lote->fresh(), $g), self::GRUPOS);
            $grupos = [];
            foreach ($planes as $p) {
                $grupos[$p['grupo']] = ['estado' => $p['a_reemitir'] === 0 ? 'al_dia' : 'no_intentado', 'total' => 0, 'esperados' => $p['a_reemitir'], 'restantes' => $p['desactualizadas']];
            }
            if (! hash_equals(self::huella($lote->fresh(), $planes), $huella)) {
                throw self::sinCambios(new EmisionException(EmisionException::PLAN_DESACTUALIZADO, 'La base, sus certificados o la plantilla cambiaron desde que revisaste. No se reemitió nada: vuelve a revisar.', 409), $grupos);
            }
            foreach ($planes as $p) {
                if ($p['bloqueo'] !== null && $p['a_reemitir'] > 0) {
                    throw self::sinCambios(new EmisionException($p['bloqueo']['code'], $p['bloqueo']['message'].' No se reemitió nada.', 409, $p['bloqueo']['detalles']), $grupos);
                }
            }
            if (array_sum(array_column($planes, 'a_reemitir')) === 0) {
                return ['estado' => self::SIN_CAMBIOS, 'grupos' => $grupos, 'correos_enviados' => 0];
            }

            $hechos = 0;
            foreach ($planes as $p) {
                if ($p['a_reemitir'] === 0) {
                    continue;
                }
                try {
                    $r = $this->reemisor->reemitir($lote->fresh(), $p['grupo'], $p['huella'], $motivo, $usuario->id);
                } catch (Throwable $e) {
                    $grupos[$p['grupo']] = ['estado' => 'fallido', 'total' => 0, 'esperados' => $p['a_reemitir'], 'restantes' => $p['desactualizadas'], 'error' => self::error($e)];
                    if ($hechos === 0) {
                        // Ningún grupo reemitido: es un error, no un resultado parcial.
                        throw self::sinCambios($e instanceof EmisionException ? $e : new EmisionException(self::error($e)['code'], self::error($e)['message'], 409), $grupos);
                    }

                    return ['estado' => self::PARCIAL, 'grupos' => $grupos, 'correos_enviados' => 0];
                }
                $hechos++;
                $grupos[$p['grupo']] = ['estado' => 'reemitido', 'total' => $r['total'], 'esperados' => $p['a_reemitir'], 'restantes' => $r['restantes'],
                    'operacion' => $r['operacion'], 'integridad' => ['nuevas' => $r['integridad']['nuevas'], 'anteriores' => $r['integridad']['anteriores'], 'fallidas' => count($r['integridad']['fallidas'])]];
            }

            return ['estado' => self::COMPLETO, 'grupos' => $grupos, 'correos_enviados' => 0];
        });
    }

    /** Emisión vigente o desactualizada de ESTA base (para ver el antes y el después); null si no es de la base. */
    public function emisionDe(Lote $lote, Emision $emision): ?Participante
    {
        if ((int) $emision->lote_id !== $lote->id) {
            return null;
        }
        $p = Participante::withTrashed()->find($emision->participante_id);

        return $p !== null && (int) $p->lote_id === $lote->id ? $p : null;
    }

    /** @param  array<int,int>  $emisiones  ids de las emisiones a reemitir: para elegir a quién ver en el antes y el después. */
    private function personas(array $emisiones): array
    {
        if ($emisiones === []) {
            return [];
        }
        $filas = Emision::query()->whereIn('id', $emisiones)->orderBy('id')->get(['id', 'participante_id', 'version', 'codigo', 'emitido_at']);
        $nombres = Participante::withTrashed()->whereIn('id', $filas->pluck('participante_id'))->pluck('nombre_completo', 'id');

        return $filas->map(fn (Emision $e) => ['emision_id' => $e->id, 'participante_id' => $e->participante_id, 'nombre' => (string) ($nombres[$e->participante_id] ?? ''),
            'version' => (int) $e->version, 'codigo' => $e->codigo, 'emitido_at' => $e->emitido_at?->toIso8601String()])->values()->all();
    }

    /**
     * Huella de lo REVISADO: el plan de cada grupo (certificados a reemplazar y plantilla) y, además, los datos con que se vio el «después» (datos comunes de la
     * base y las personas de la base). Si alguien cambia la base o a una persona entre revisar y confirmar, la confirmación no se ejecuta: hay que volver a revisar.
     *
     * @param  array<int,array<string,mixed>>  $planes
     */
    private static function huella(Lote $lote, array $planes): string
    {
        $personas = Participante::withTrashed()->where('lote_id', $lote->id)->orderBy('id')->get(['id', 'updated_at', 'deleted_at'])
            ->map(fn (Participante $p) => [$p->id, $p->updated_at?->format('Y-m-d H:i:s.u'), $p->deleted_at?->format('Y-m-d H:i:s.u')])->all();

        return hash('sha256', json_encode([
            array_map(fn (array $p) => $p['grupo'].':'.$p['huella'], $planes),
            (int) $lote->plantilla_id,
            $lote->datos_comunes,
            $personas,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** Un error inesperado no se muestra tal cual (puede llevar rutas o datos): ReemisorBase ya lo dejó resumido en el registro. */
    private static function error(Throwable $e): array
    {
        return $e instanceof EmisionException || $e instanceof GeneracionCredencialException
            ? ['code' => $e->codigo, 'message' => $e->getMessage(), 'detalles' => array_slice($e instanceof EmisionException ? $e->detalles : [], 0, 20)]
            : ['code' => 'ERROR_INESPERADO', 'message' => 'Falló la reemisión de este grupo y se revirtió completa: ninguno de sus certificados cambió. Puedes reintentar.', 'detalles' => []];
    }

    private static function sinCambios(EmisionException $e, array $grupos): CorreccionBaseException
    {
        return new CorreccionBaseException($e, ['estado' => self::SIN_CAMBIOS, 'grupos' => $grupos, 'correos_enviados' => 0]);
    }
}
