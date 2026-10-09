<?php

namespace App\Support\CredentialFlow\Migracion;

use App\Models\CredentialFlow\MigracionCorrida;
use App\Models\Movimiento;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ROLLBACK TÉCNICO de una corrida de migración histórica. Es una herramienta de operación (solo el comando
 * credential-flow:legado:rollback-corrida): NO hay ruta, controlador ni pantalla. Usa consultas directas a la base de datos
 * (el modelo CertificadoLegado se niega a borrar a propósito) y borra SOLO lo que lleva el corrida_id de esa corrida.
 *
 * Solo se permite si la corrida está COMPLETADA y NADA ha pasado después: ninguna corrida posterior, ningún PDF congelado,
 * ningún certificado/correo/descarga/evento/plantilla modificado o creado por actividad posterior, ninguna referencia desde
 * bases o emisiones modernas. Si algo de eso ocurre, se NIEGA sin tocar nada.
 *
 * Orden (todo en UNA transacción): descargas → correos → certificados → (se desenlazan los eventos de sus plantillas) →
 * plantillas → contenidos sin referencias → eventos → mapas → corrida «revertida». El staging NO se toca.
 * Los contenidos que otra corrida o entrada siga usando se CONSERVAN.
 */
final class RollbackCorrida
{
    /** @return array<string,mixed> conteos borrados (sin datos personales) */
    public function revertir(int $corridaId): array
    {
        GuardiaMigracion::exigirDestinoSeguro();

        return DB::transaction(function () use ($corridaId) {
            $corrida = MigracionCorrida::lockForUpdate()->find($corridaId) ?? throw new RollbackNoPermitido(RollbackNoPermitido::NO_EXISTE, "No existe la corrida #{$corridaId}.");
            if ($corrida->estado !== MigracionCorrida::ESTADO_COMPLETADA) {
                throw new RollbackNoPermitido(RollbackNoPermitido::NO_COMPLETADA, "La corrida #{$corridaId} está «{$corrida->estado}»: solo se revierte una corrida completada.");
            }
            $this->exigirSinActividadPosterior($corrida);

            $mia = fn (string $tabla): Builder => DB::table($tabla)->where('corrida_id', $corridaId);
            $borrado = [];

            $borrado['descargas'] = $mia('cf_descargas')->delete();
            $borrado['correos'] = $mia('cf_correos')->delete();
            $borrado['certificados'] = $mia('cf_certificados_legado')->delete();

            // Los eventos apuntan a sus plantillas (RESTRICT): se desenlazan antes de borrar las plantillas.
            $mia('cf_eventos')->update(['plantilla_legado_id' => null]);
            $borrado['plantillas'] = $mia('cf_plantillas_legado')->delete();

            // Solo los contenidos de esta corrida que ya nadie referencia; el resto se conserva.
            $borrado['contenidos'] = $mia('cf_plantillas_legado_contenidos')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('cf_plantillas_legado')->whereColumn('cf_plantillas_legado.contenido_id', 'cf_plantillas_legado_contenidos.id'))
                ->delete();
            $conservados = $mia('cf_plantillas_legado_contenidos')->count();

            $borrado['eventos'] = $mia('cf_eventos')->delete();
            $borrado['mapas'] = DB::table('cf_migraciones_map')->where('corrida_id', $corridaId)->delete();

            // Lo borrado debe coincidir con lo que la corrida creó.
            $t = $corrida->totales ?? [];
            $esperado = [
                'descargas' => $t['descargas']['migradas'] ?? null, 'correos' => $t['correos']['total'] ?? null, 'certificados' => $t['certificados'] ?? null,
                'plantillas' => $t['plantillas'] ?? null, 'eventos' => $t['eventos'] ?? null,
            ];
            foreach ($esperado as $clave => $n) {
                if ($n !== null && (int) $n !== $borrado[$clave]) {
                    throw new RollbackNoPermitido(RollbackNoPermitido::CAMBIO_CONCURRENTE, "Se esperaba borrar {$n} de «{$clave}» y se borraron {$borrado[$clave]}. Se revierte el rollback.");
                }
            }
            if (($t['contenidos'] ?? null) !== null && (int) $t['contenidos'] !== $borrado['contenidos'] + $conservados) {
                throw new RollbackNoPermitido(RollbackNoPermitido::CAMBIO_CONCURRENTE, 'Los contenidos borrados y conservados no suman lo creado por la corrida.');
            }

            $resultado = $borrado + ['contenidos_conservados' => $conservados];
            $t['rollback'] = $resultado;
            MigracionCorrida::whereKey($corridaId)->update([
                'estado' => MigracionCorrida::ESTADO_REVERTIDA, 'rollback_at' => now(), 'totales' => json_encode($t, JSON_UNESCAPED_UNICODE),
            ]);
            // Solo conteos: nada de nombres, documentos ni correos.
            Movimiento::registrar('rollback', 'credential-flow', 'Rollback técnico de una corrida de migración histórica', ['corrida_id' => $corridaId] + $resultado);

            return $resultado;
        });
    }

    /** @throws RollbackNoPermitido */
    private function exigirSinActividadPosterior(MigracionCorrida $c): void
    {
        $id = $c->id;
        $no = fn (string $codigo, string $mensaje) => throw new RollbackNoPermitido($codigo, $mensaje);

        if (MigracionCorrida::where('tipo', $c->tipo)->where('id', '>', $id)->where('estado', MigracionCorrida::ESTADO_COMPLETADA)->exists()) {
            $no(RollbackNoPermitido::CORRIDA_POSTERIOR, 'Hay una corrida completada posterior de este tipo: se revierte primero esa.');
        }

        $certs = fn () => DB::table('cf_certificados_legado')->where('corrida_id', $id);

        // Las encuestas históricas (Fase 8) cuelgan de estos certificados y eventos (FK RESTRICT): se revierte primero la corrida de encuestas.
        if (Schema::hasTable('cf_encuestas_respuestas')
            && (DB::table('cf_encuestas_respuestas')->whereIn('certificado_legado_id', $certs()->select('id'))->exists()
                || DB::table('cf_encuestas_respuestas')->whereIn('evento_id', DB::table('cf_eventos')->where('corrida_id', $id)->select('id'))->exists())) {
            $no(RollbackNoPermitido::ENCUESTAS_VINCULADAS, 'Hay respuestas de encuestas ligadas a estos certificados o eventos: se revierte primero la corrida de encuestas.');
        }

        // Los códigos históricos asignados por Credential Flow (Fase 10B-1.5) son INMUTABLES y cuelgan de estos certificados y eventos.
        if (Schema::hasTable('cf_codigos_historicos')
            && DB::table('cf_codigos_historicos')->where(fn ($q) => $q->whereIn('certificado_canonico_id', $certs()->select('id'))->orWhereIn('evento_id', DB::table('cf_eventos')->where('corrida_id', $id)->select('id')))->exists()) {
            $no(RollbackNoPermitido::CODIGOS_ASIGNADOS, 'Hay códigos históricos asignados por Credential Flow sobre estos certificados: son permanentes; no se revierte.');
        }

        if ($certs()->where(fn ($q) => $q->whereNotNull('pdf_archivo')->orWhereNotNull('pdf_hash')->orWhereNotNull('materializado_at'))->exists()) {
            $no(RollbackNoPermitido::PDF_CONGELADO, 'Hay certificados con PDF congelado: ya se sirvieron o generaron; no se revierte.');
        }
        if ($certs()->where(fn ($q) => $q->where('visible_portal', true)->orWhere('estado', '!=', 'vigente')->orWhere('intentos_generacion', '>', 0)
            ->orWhereNotNull('revocado_at')->orWhereNotNull('reemplazado_por_emision_id')->orWhereNotNull('update_by')->orWhereColumn('updated_at', '!=', 'created_at'))->exists()) {
            $no(RollbackNoPermitido::CERTIFICADOS_MODIFICADOS, 'Hay certificados modificados después de la migración (visibles, revocados, reemplazados, con intentos o editados).');
        }

        $descargasAjenas = DB::table('cf_descargas')->whereIn('certificado_legado_id', $certs()->select('id'))->where(fn ($q) => $q->whereNull('corrida_id')->orWhere('corrida_id', '!=', $id));
        $descargasPropias = DB::table('cf_descargas')->where('corrida_id', $id);
        if ($descargasAjenas->exists() || (clone $descargasPropias)->where(fn ($q) => $q->whereNotNull('participante_id')->orWhereNotNull('emision_id')->orWhereColumn('updated_at', '!=', 'created_at'))->exists()) {
            $no(RollbackNoPermitido::DESCARGAS_NUEVAS, 'Hay descargas nuevas (o modificadas) sobre estos certificados: es actividad posterior.');
        }

        $correosAjenos = DB::table('cf_correos')->whereIn('certificado_legado_id', $certs()->select('id'))->where(fn ($q) => $q->whereNull('corrida_id')->orWhere('corrida_id', '!=', $id));
        $correosPropios = DB::table('cf_correos')->where('corrida_id', $id);
        if ($correosAjenos->exists() || (clone $correosPropios)->where(fn ($q) => $q->whereNotNull('update_by')->orWhere('es_principal', true)->orWhereColumn('updated_at', '!=', 'created_at'))->exists()) {
            $no(RollbackNoPermitido::CORREOS_NUEVOS, 'Hay correos nuevos o modificados sobre estos certificados: es actividad posterior.');
        }

        $eventos = fn () => DB::table('cf_eventos')->where('corrida_id', $id);
        $eventosIds = $eventos()->select('id');
        if (DB::table('cf_lotes')->whereIn('evento_id', $eventosIds)->exists()
            || DB::table('cf_certificados_legado')->whereIn('evento_id', $eventosIds)->where(fn ($q) => $q->whereNull('corrida_id')->orWhere('corrida_id', '!=', $id))->exists()
            || $eventos()->where(fn ($q) => $q->whereNotNull('deleted_at')->orWhereNotNull('update_by')->orWhereColumn('updated_at', '!=', 'created_at'))->exists()) {
            $no(RollbackNoPermitido::EVENTOS_EN_USO, 'Hay bases, certificados o ediciones posteriores sobre los eventos de la corrida.');
        }

        $plantillasIds = DB::table('cf_plantillas_legado')->where('corrida_id', $id)->select('id');
        if (DB::table('cf_certificados_legado')->whereIn('plantilla_legado_id', $plantillasIds)->where(fn ($q) => $q->whereNull('corrida_id')->orWhere('corrida_id', '!=', $id))->exists()
            || DB::table('cf_eventos')->whereIn('plantilla_legado_id', $plantillasIds)->where(fn ($q) => $q->whereNull('corrida_id')->orWhere('corrida_id', '!=', $id))->exists()
            || DB::table('cf_plantillas_legado')->where('corrida_id', $id)->where(fn ($q) => $q->whereNotNull('update_by')->orWhereColumn('updated_at', '!=', 'created_at'))->exists()) {
            $no(RollbackNoPermitido::PLANTILLAS_COMPARTIDAS, 'Hay certificados o eventos de otro origen (o ediciones) que usan las plantillas de la corrida.');
        }
    }
}
