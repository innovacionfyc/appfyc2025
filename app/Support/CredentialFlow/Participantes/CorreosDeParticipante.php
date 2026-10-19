<?php

namespace App\Support\CredentialFlow\Participantes;

use App\Models\CredentialFlow\Correo;
use App\Models\CredentialFlow\Participante;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Portal\NombreConservador;
use App\Support\CredentialFlow\Portal\ServicioOtp;
use App\Support\CredentialFlow\Portal\VinculosIdentidadModerna;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Correo de un participante nativo, editable desde el administrador SIN reimportar la base.
 *
 * Mantiene coherentes `cf_participantes` (correo, correo_normalizado, correo_estado) y `cf_correos` (la fuente que lee el portal), con el mismo convenio
 * que la importación: un correo válido → se guarda en la fila; varios → `multiple` (la fuente son las filas de cf_correos); ninguno → `sin_correo`.
 *
 * Retirar el acceso del correo anterior (sin ampliar nunca el de otra persona):
 *  - el portal reconoce a un participante moderno SOLO por los correos vivos de `cf_correos`: al reemplazarlos, el correo anterior deja de identificarlo
 *    en el acto (no se pueden pedir más códigos con él);
 *  - los códigos (OTP) que ya estaban emitidos para documento + correo anterior se INVALIDAN, porque validar un código no vuelve a mirar el correo;
 *  - si ese correo sigue identificando a la misma persona por otra vía (otro certificado con el mismo documento y el mismo nombre), esa vía no se toca;
 *  - un correo que ya identifica a OTRA persona con el mismo documento (otra grafía de nombre) se RECHAZA: unir identidades es un vínculo explícito
 *    (`credential-flow:identidad-vincular`), nunca un efecto lateral de editar un correo;
 *  - mientras un vínculo de identidad vigente dependa del correo actual, no se puede cambiar por otro (se revoca el vínculo y se crea uno nuevo);
 *  - las sesiones del portal abiertas ANTES del cambio dejan de ver ese certificado (`correo_actualizado_at` frente al instante de autenticación); deben entrar de nuevo.
 * Las emisiones, sus snapshots y sus PDF no se tocan: el correo no forma parte de lo impreso ni de la huella.
 */
final class CorreosDeParticipante
{
    /**
     * Valida sin escribir. @return array{correos:list<string>,errores:list<string>,avisos:list<string>}
     */
    public function evaluar(?Participante $participante, int $loteId, string $documentoClave, ?string $texto): array
    {
        $texto = trim((string) $texto);
        if ($texto === '') {
            return ['correos' => [], 'errores' => [], 'avisos' => ['Sin correo: esta persona no podrá entrar al portal de certificados.']];
        }

        $v = ValidadorCorreo::validar(CeldaCruda::texto($texto), 0);
        $errores = array_values(array_unique(array_map(fn (ErrorFila $e) => $e->mensaje, $v['errores'])));
        $avisos = [];
        if ($errores === [] && $v['correos'] !== []) {
            $normalizados = array_map(fn (string $c) => Correo::normalizar($c), $v['correos']);
            // Un conflicto de identidad se evalúa solo sobre los correos NUEVOS: el que la persona ya tiene (p. ej. el de un vínculo explícito) no se vuelve a juzgar.
            $nuevos = $participante === null ? $normalizados : array_values(array_diff($normalizados, $this->normalizadosActuales($participante)));
            $errores = [...$errores, ...$this->conflictosDeIdentidad($participante, $documentoClave, $nuevos), ...$this->vinculoLoImpide($participante, $normalizados)];
            if (Schema::hasTable('cf_correos') && $this->usadoPorOtroDocumentoEnLaBase($loteId, $participante?->id, $documentoClave, $normalizados)) {
                $avisos[] = 'Ese correo ya está registrado en otra persona de esta base. Revísalo por si se repitió por error.';
            }
        }

        return ['correos' => $errores === [] ? $v['correos'] : [], 'errores' => $errores, 'avisos' => $avisos];
    }

    /**
     * Cambia el correo del participante de forma transaccional. Devuelve si hubo cambio y cuáles correos se retiraron (enmascarados, nunca completos).
     *
     * @param  list<string>  $correos  direcciones YA validadas por `evaluar` (originales, sin espacios); vacía = sin correo
     * @return array{cambio:bool,retirados:list<string>,agregados:list<string>}
     */
    public function guardar(Participante $participante, array $correos, ?int $usuarioId = null): array
    {
        $hayTabla = Schema::hasTable('cf_correos');
        $resultado = DB::transaction(function () use ($participante, $correos, $hayTabla) {
            $p = Participante::query()->whereKey($participante->id)->lockForUpdate()->firstOrFail();
            $antes = $this->normalizadosActuales($p, $hayTabla);
            $despues = array_values(array_unique(array_map(fn (string $c) => Correo::normalizar($c), $correos)));
            if ($antes === $despues) {
                return ['cambio' => false, 'retirados' => [], 'agregados' => [], 'antes' => $antes, 'despues' => $despues];
            }

            if ($hayTabla) {
                Correo::query()->where('participante_id', $p->id)->get()->each->delete();
                foreach (array_values($correos) as $i => $original) {
                    Correo::create(['participante_id' => $p->id, 'correo' => $original, 'correo_normalizado' => Correo::normalizar($original), 'estado' => Correo::ESTADO_VALIDO,
                        'orden' => $i + 1, 'es_principal' => $i === 0, 'origen' => Correo::ORIGEN_CREDENTIAL_FLOW]);
                }
            }
            $unico = count($correos) === 1 ? $correos[0] : null;
            $p->forceFill([
                // Una sesión del portal abierta ANTES de este instante ya no ve este certificado (debe volver a entrar con el correo vigente).
                'correo_actualizado_at' => now(),
                'correo' => $unico,
                'correo_normalizado' => $unico === null ? null : Correo::normalizar($unico),
                'correo_estado' => match (true) {
                    $unico !== null => Participante::CORREO_VALIDO,
                    count($correos) > 1 => Participante::CORREO_MULTIPLE,
                    default => Participante::CORREO_SIN_CORREO,
                },
            ])->save();

            return ['cambio' => true, 'retirados' => array_values(array_diff($antes, $despues)), 'agregados' => array_values(array_diff($despues, $antes)), 'antes' => $antes, 'despues' => $despues];
        });

        if (! $resultado['cambio']) {
            return ['cambio' => false, 'retirados' => [], 'agregados' => []];
        }

        // Retiro de acceso: los códigos ya emitidos al correo anterior dejan de servir (el documento sigue siendo el mismo).
        foreach ($resultado['retirados'] as $anterior) {
            ServicioOtp::invalidarPendientes($participante->documento_clave, $anterior);
        }

        Movimiento::registrar(
            tipo: 'actualizacion',
            modulo: 'credential-flow',
            descripcion: 'Se corrigió el correo de un participante de Credential Flow',
            extra: [
                'lote_id' => $participante->lote_id, 'participante_id' => $participante->id,
                'retirados' => array_map(fn (string $c) => ValidadorCorreo::mascara($c), $resultado['retirados']),
                'agregados' => array_map(fn (string $c) => ValidadorCorreo::mascara($c), $resultado['agregados']),
            ],
        );

        return [
            'cambio' => true,
            'retirados' => array_map(fn (string $c) => ValidadorCorreo::mascara($c), $resultado['retirados']),
            'agregados' => array_map(fn (string $c) => ValidadorCorreo::mascara($c), $resultado['agregados']),
        ];
    }

    /** Correos (normalizados) que hoy tiene el participante: los de cf_correos y, si no hay tabla, el de su fila. @return list<string> */
    public function normalizadosActuales(Participante $p, ?bool $hayTabla = null): array
    {
        $hayTabla ??= Schema::hasTable('cf_correos');
        if ($hayTabla) {
            return DB::table('cf_correos')->where('participante_id', $p->id)->where('estado', Correo::ESTADO_VALIDO)->orderBy('orden')->orderBy('id')->pluck('correo_normalizado')->all();
        }

        return $p->correo_normalizado ? [(string) $p->correo_normalizado] : [];
    }

    /** Correos originales actuales (para mostrarlos en la edición, solo a administradores). @return list<string> */
    public function originalesActuales(Participante $p): array
    {
        if (Schema::hasTable('cf_correos')) {
            return DB::table('cf_correos')->where('participante_id', $p->id)->where('estado', Correo::ESTADO_VALIDO)->orderBy('orden')->orderBy('id')->pluck('correo')->all();
        }

        return $p->correo ? [(string) $p->correo] : [];
    }

    /**
     * Mensajes de bloqueo si algún correo ya identifica a OTRA persona (otro grupo de nombre) con el MISMO documento, entre participantes nativos o certificados
     * históricos. Con el mismo nombre no hay conflicto: es la misma persona.
     *
     * @param  list<string>  $normalizados
     * @return list<string>
     */
    private function conflictosDeIdentidad(?Participante $participante, string $documentoClave, array $normalizados): array
    {
        if (! Schema::hasTable('cf_correos') || $documentoClave === '') {
            return [];
        }
        $grupoPropio = $participante === null ? null : NombreConservador::grupoId((string) $participante->nombre_completo);

        $otros = DB::table('cf_correos as c')->join('cf_participantes as p', 'p.id', '=', 'c.participante_id')
            ->where('p.documento_clave', $documentoClave)->whereNull('p.deleted_at')->whereIn('c.correo_normalizado', $normalizados)
            ->when($participante, fn ($q) => $q->where('p.id', '!=', $participante->id))
            ->pluck('p.nombre_completo');
        $historicos = Schema::hasTable('cf_certificados_legado')
            ? DB::table('cf_correos as c')->join('cf_certificados_legado as l', 'l.id', '=', 'c.certificado_legado_id')
                ->where('l.documento_clave', $documentoClave)->whereIn('c.correo_normalizado', $normalizados)->pluck('l.nombre_completo')
            : collect();

        foreach ($otros->concat($historicos) as $nombre) {
            if ($grupoPropio === null || NombreConservador::grupoId((string) $nombre) !== $grupoPropio) {
                return ['Ese correo ya identifica a otra persona con el mismo documento (otra forma de escribir el nombre). Si es la misma persona, pide un vínculo de identidad: no se unen identidades al editar un correo.'];
            }
        }

        return [];
    }

    /**
     * Si el nombre de este participante tiene un vínculo de identidad VIGENTE, ese vínculo se autorizó con un correo concreto (el que estaba registrado en el
     * grupo histórico y en el moderno). Cambiarlo por otro heredaría, sin verificación, el acceso a los certificados históricos de la persona vinculada:
     * por eso, mientras el vínculo esté vigente, el correo autorizado debe conservarse; para cambiarlo se revoca el vínculo y se crea uno nuevo.
     *
     * @param  list<string>  $normalizados
     * @return list<string>
     */
    private function vinculoLoImpide(?Participante $participante, array $normalizados): array
    {
        if ($participante === null || ! VinculosIdentidadModerna::disponible()) {
            return [];
        }
        $vinculo = DB::table(VinculosIdentidadModerna::TABLA)
            ->where('documento_hash', VinculosIdentidadModerna::hashDocumento((string) $participante->documento_clave))
            ->where('grupo_moderno_hash', NombreConservador::grupoId((string) $participante->nombre_completo))
            ->where('estado', 'vigente')->first();
        if ($vinculo === null) {
            return [];
        }
        foreach ($normalizados as $c) {
            if (hash_equals((string) $vinculo->correo_hash, VinculosIdentidadModerna::hashCorreo($c))) {
                return [];
            }
        }

        return ['Esta persona tiene un vínculo de identidad vigente asociado a su correo actual. Para cambiarlo, primero hay que revocar ese vínculo y crear uno nuevo con el correo correcto (credential-flow:identidad-vincular).'];
    }

    /** @param  list<string>  $normalizados */
    private function usadoPorOtroDocumentoEnLaBase(int $loteId, ?int $propioId, string $documentoClave, array $normalizados): bool
    {
        return DB::table('cf_correos as c')->join('cf_participantes as p', 'p.id', '=', 'c.participante_id')
            ->where('p.lote_id', $loteId)->whereNull('p.deleted_at')->where('p.documento_clave', '!=', $documentoClave)
            ->whereIn('c.correo_normalizado', $normalizados)->when($propioId, fn ($q) => $q->where('p.id', '!=', $propioId))->exists();
    }
}
