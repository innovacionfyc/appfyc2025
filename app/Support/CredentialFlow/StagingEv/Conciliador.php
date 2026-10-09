<?php

namespace App\Support\CredentialFlow\StagingEv;

use Illuminate\Support\Facades\DB;

/**
 * Cruza las entidades ya cargadas en el staging y deja calculado lo que depende de varias tablas: enlaces evento↔imagen,
 * imágenes huérfanas y candidatas, banderas de existencia, primera descarga, grupos de duplicados y validaciones.
 *
 * Es DETERMINISTA y se puede repetir: recalcula todo desde cero sobre las filas vigentes (no ausentes). No inventa
 * ni corrige datos: solo clasifica.
 */
final class Conciliador
{
    private const VIGENTE = "estado_fila <> 'ausente_en_origen'";

    public function ejecutar(bool $imagenesCargadas, int $snapshotId): void
    {
        $this->eventosEImagenes($imagenesCargadas);
        $this->correos($snapshotId);
        $this->participantes();
        $this->descargas();
        $this->tokens();
        $this->encuestas();
    }

    // ── Eventos e imágenes ─────────────────────────────────────────────────────────────────────────────────────

    private function eventosEImagenes(bool $imagenesCargadas): void
    {
        DB::statement('UPDATE stg_ev_evento SET participantes_count = (SELECT COUNT(*) FROM stg_ev_participante p '
            .'WHERE p.old_evento_id = stg_ev_evento.old_id AND p.'.self::VIGENTE.')');

        if ($imagenesCargadas) {
            $this->enlazarImagenes();
        }

        $this->validar('stg_ev_evento', ['imagen_estado', 'anio_deducido', 'anio_origen', 'imagen_original'], fn (array $f) => ReglasValidacion::evento($f));
        $this->validar('stg_ev_imagenes', ['renderizable_fpdf', 'huerfana', 'candidata_revision', 'nombre_original'], fn (array $f) => ReglasValidacion::imagen($f));
    }

    private function enlazarImagenes(): void
    {
        $imagenes = [];
        foreach (DB::table('stg_ev_imagenes')->whereRaw(self::VIGENTE)->get(['id', 'nombre_original', 'renderizable_fpdf']) as $i) {
            $imagenes[$i->nombre_original] = $i;
        }

        $refs = [];       // nombre de imagen => id viejo del primer evento que la usa
        $conteo = [];     // nombre de imagen => cantidad de eventos que la usan
        $faltantes = [];  // clave de archivo => [ids viejos de eventos cuya imagen no existe]
        $ahora = now();

        foreach (DB::table('stg_ev_evento')->whereRaw(self::VIGENTE)->orderBy('old_id')->get(['id', 'old_id', 'imagen_original', 'imagen_extension']) as $e) {
            $ref = $e->imagen_original;
            $estado = null;
            $imagenId = null;
            if ($ref === null) {
                $estado = 'sin_imagen';
            } else {
                $ext = $e->imagen_extension === 'jpeg' ? 'jpg' : $e->imagen_extension;
                $existe = isset($imagenes[$ref]);
                if ($existe) {
                    $imagenId = $imagenes[$ref]->id;
                    $refs[$ref] ??= (int) $e->old_id;
                    $conteo[$ref] = ($conteo[$ref] ?? 0) + 1;
                }
                if (! in_array($ext, ['jpg', 'png', 'gif'], true)) {
                    $estado = 'extension_invalida';
                } elseif (! $existe) {
                    $estado = 'archivo_faltante';
                    $faltantes[$this->claveSinExtension($ref)][] = (int) $e->old_id;
                } else {
                    $estado = $imagenes[$ref]->renderizable_fpdf ? 'ok' : 'no_renderizable';
                }
            }
            DB::table('stg_ev_evento')->where('id', $e->id)->update(['imagen_estado' => $estado, 'imagen_ref_id' => $imagenId, 'updated_at' => $ahora]);
        }

        DB::table('stg_ev_imagenes')->update(['huerfana' => false, 'old_evento_id' => null, 'eventos_enlazados' => 0, 'candidata_revision' => false, 'candidata_old_evento_id' => null]);
        $huerfanas = [];
        foreach ($imagenes as $nombre => $i) {
            if (isset($refs[$nombre])) {
                DB::table('stg_ev_imagenes')->where('id', $i->id)->update(['old_evento_id' => $refs[$nombre], 'eventos_enlazados' => $conteo[$nombre]]);
            } else {
                $huerfanas[$this->claveSinExtension($nombre)][] = $i->id;
                DB::table('stg_ev_imagenes')->where('id', $i->id)->update(['huerfana' => true]);
            }
        }

        // Candidatas: huérfana y evento sin archivo cuyos nombres coinciden al normalizarlos, de forma ÚNICA para ambos lados.
        foreach ($faltantes as $clave => $eventos) {
            if ($clave !== '' && count($eventos) === 1 && isset($huerfanas[$clave]) && count($huerfanas[$clave]) === 1) {
                DB::table('stg_ev_imagenes')->where('id', $huerfanas[$clave][0])->update(['candidata_revision' => true, 'candidata_old_evento_id' => $eventos[0]]);
            }
        }
    }

    private function claveSinExtension(string $nombre): string
    {
        $ext = Normalizador::extensionFpdf($nombre);
        $base = in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true) ? substr($nombre, 0, -(strlen($ext) + 1)) : $nombre;

        return Normalizador::claveArchivo($base);
    }

    // ── Correos candidatos ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * Reconstruye por completo la colección derivada stg_ev_participante_correos a partir de correo_original de las filas
     * vigentes. No elige ningún correo como principal: solo lista los candidatos en el orden en que aparecen.
     */
    private function correos(int $snapshotId): void
    {
        DB::table('stg_ev_participante_correos')->delete();

        $lote = [];
        $ahora = now();
        DB::table('stg_ev_participante')->whereRaw(self::VIGENTE)->orderBy('id')->select(['id', 'old_id', 'correo_original'])
            ->chunkById(1000, function ($filas) use (&$lote, $snapshotId, $ahora) {
                foreach ($filas as $f) {
                    foreach (Normalizador::candidatosCorreo($f->correo_original) as $orden => $c) {
                        $lote[] = [
                            'participante_old_id' => $f->old_id, 'snapshot_id' => $snapshotId, 'orden' => $orden + 1,
                            'correo_normalizado' => $c['correo'], 'correo_sha256' => hash('sha256', $c['correo']), 'estado' => $c['estado'],
                            'created_at' => $ahora, 'updated_at' => $ahora,
                        ];
                    }
                }
                foreach (array_chunk($lote, 500) as $trozo) {
                    DB::table('stg_ev_participante_correos')->insert($trozo);
                }
                $lote = [];
            });
    }

    // ── Participantes ──────────────────────────────────────────────────────────────────────────────────────────

    private function participantes(): void
    {
        DB::statement('UPDATE stg_ev_participante SET tiene_evento = CASE WHEN EXISTS (SELECT 1 FROM stg_ev_evento e '
            .'WHERE e.old_id = stg_ev_participante.old_evento_id AND e.'.self::VIGENTE.') THEN 1 ELSE 0 END');
        DB::statement('UPDATE stg_ev_participante SET '
            .'descargas_count = (SELECT COUNT(*) FROM stg_ev_descargas d WHERE d.old_participante_id = stg_ev_participante.old_id AND d.'.self::VIGENTE.'), '
            .'primera_descarga_at = (SELECT MIN(d.fecha) FROM stg_ev_descargas d WHERE d.old_participante_id = stg_ev_participante.old_id AND d.'.self::VIGENTE.')');

        $this->duplicados();

        $clasificacion = DB::table('stg_ev_duplicados')->pluck('clasificacion', 'id')->all();
        $this->validar(
            'stg_ev_participante',
            ['documento_estado', 'documento_detalle', 'documento_normalizado_ws', 'correo_estado', 'correos_candidatos', 'correos_validos', 'tipo_documento', 'grupo_duplicado_id'],
            fn (array $f) => ReglasValidacion::participante($f, $f['grupo_duplicado_id'] === null ? null : ($clasificacion[$f['grupo_duplicado_id']] ?? null)),
        );
    }

    /**
     * Grupos de duplicados por (evento viejo, documento_clave). Identico = todo lo relevante coincide; conflictivo = alguna
     * diferencia (etiquetas DIF_*). No se consolida ni se elige ganador: solo se reporta.
     */
    private function duplicados(): void
    {
        DB::table('stg_ev_participante')->update(['grupo_duplicado_id' => null]);
        DB::table('stg_ev_duplicados')->delete();

        $porClave = [];
        $filas = DB::table('stg_ev_participante')
            ->whereRaw(self::VIGENTE)->where('documento_clave', '<>', '')
            ->orderBy('old_id')
            ->get(['old_id', 'old_evento_id', 'documento_clave', 'nombre_normalizado', 'tipo_documento', 'documento_impreso', 'correos_firma', 'num_verificacion']);
        foreach ($filas as $f) {
            $porClave[$f->old_evento_id.'|'.$f->documento_clave][] = $f;
        }
        $grupos = array_filter($porClave, fn (array $g) => count($g) > 1);
        ksort($grupos, SORT_STRING);

        $id = 0;
        $insertar = [];
        $asignar = [];
        foreach ($grupos as $clave => $g) {
            $id++;
            $dif = [
                'DIF_NOMBRE' => $this->distintos($g, 'nombre_normalizado'),
                'DIF_TIPO' => $this->distintos($g, 'tipo_documento'),
                'DIF_DOCUMENTO' => $this->distintos($g, 'documento_impreso'),
                // Se compara la firma de TODOS los candidatos (no solo el «utilizable»): dos filas con listas distintas difieren.
                'DIF_CORREO' => $this->distintos($g, 'correos_firma'),
                'DIF_VERIF' => $this->distintos($g, 'num_verificacion'),
            ];
            $etiquetas = array_keys(array_filter($dif));
            // Subtipos informativos (siguen siendo conflictivos): la única diferencia es «sin dato» frente a UN solo valor.
            $subtipo = null;
            if ($etiquetas === ['DIF_VERIF'] && ! $this->masDeUnValor($g, 'num_verificacion')) {
                $subtipo = 'SOLO_VERIF_NULL_VS_VALOR';
            } elseif ($etiquetas === ['DIF_CORREO'] && ! $this->masDeUnValor($g, 'correos_firma')) {
                $subtipo = 'SOLO_CORREO_NULL_VS_VALOR';
            }
            $ids = array_map(fn ($f) => (int) $f->old_id, $g);
            sort($ids);
            $insertar[] = [
                'id' => $id,
                'old_evento_id' => (int) $g[0]->old_evento_id,
                'documento_clave_hash' => sha1($clave),
                'clasificacion' => $etiquetas === [] ? 'identico' : 'conflictivo',
                'etiquetas' => $etiquetas === [] ? null : implode(',', $etiquetas),
                'subtipo' => $subtipo,
                'filas' => count($g),
                'old_ids' => json_encode($ids, JSON_THROW_ON_ERROR),
            ];
            $asignar[$id] = $ids;
        }

        foreach (array_chunk($insertar, 300) as $trozo) {
            DB::table('stg_ev_duplicados')->insert($trozo);
        }
        foreach ($asignar as $gid => $ids) {
            DB::table('stg_ev_participante')->whereIn('old_id', $ids)->update(['grupo_duplicado_id' => $gid]);
        }
    }

    /** ¿Hay más de un valor distinto en la columna? (NULL y vacío cuentan como el mismo «sin dato».) */
    private function distintos(array $grupo, string $columna, bool $soloConValor = false, int $maximo = 0): bool
    {
        $valores = [];
        foreach ($grupo as $f) {
            $v = $f->{$columna};
            $valores[$v === null || $v === '' ? '' : (string) $v] = true;
        }
        if ($soloConValor) {
            unset($valores['']);

            return count($valores) > $maximo;
        }

        return count($valores) > 1;
    }

    /** ¿Hay más de un valor CON dato (no nulo) distinto? */
    private function masDeUnValor(array $grupo, string $columna): bool
    {
        return $this->distintos($grupo, $columna, soloConValor: true, maximo: 1);
    }

    // ── Descargas, tokens y encuestas ──────────────────────────────────────────────────────────────────────────

    private function descargas(): void
    {
        DB::statement('UPDATE stg_ev_descargas SET participante_existe = CASE WHEN EXISTS (SELECT 1 FROM stg_ev_participante p '
            .'WHERE p.old_id = stg_ev_descargas.old_participante_id AND p.'.self::VIGENTE.') THEN 1 ELSE 0 END');
        DB::statement('UPDATE stg_ev_descargas SET evento_coincide = (SELECT CASE WHEN p.old_evento_id = stg_ev_descargas.old_evento_id THEN 1 ELSE 0 END '
            .'FROM stg_ev_participante p WHERE p.old_id = stg_ev_descargas.old_participante_id AND p.'.self::VIGENTE.')');

        // Primera descarga de cada participante: la más antigua (por fecha y, a igualdad, por id).
        DB::table('stg_ev_descargas')->update(['es_primera' => false]);
        $primeras = [];
        $visto = [];
        foreach (DB::table('stg_ev_descargas')->whereRaw(self::VIGENTE)->whereNotNull('old_participante_id')
            ->orderBy('old_participante_id')->orderByRaw('fecha IS NULL')->orderBy('fecha')->orderBy('old_id')->cursor() as $d) {
            if (! isset($visto[$d->old_participante_id])) {
                $visto[$d->old_participante_id] = true;
                $primeras[] = $d->old_id;
            }
        }
        foreach (array_chunk($primeras, 400) as $trozo) {
            DB::table('stg_ev_descargas')->whereIn('old_id', $trozo)->update(['es_primera' => true]);
        }

        $this->validar('stg_ev_descargas', ['participante_existe', 'evento_coincide'], fn (array $f) => ReglasValidacion::descarga($f));
    }

    private function tokens(): void
    {
        DB::statement('UPDATE stg_ev_token SET participante_existe = CASE WHEN EXISTS (SELECT 1 FROM stg_ev_participante p '
            .'WHERE p.old_id = stg_ev_token.old_participante_id AND p.'.self::VIGENTE.') THEN 1 ELSE 0 END');
        DB::table('stg_ev_token')->update(['codigo_repetido' => false]);
        DB::statement('UPDATE stg_ev_token SET codigo_repetido = 1 WHERE codigo_sha256 IN (SELECT codigo_sha256 FROM ('
            .'SELECT codigo_sha256 FROM stg_ev_token WHERE codigo_sha256 IS NOT NULL GROUP BY codigo_sha256 HAVING COUNT(*) > 1) repetidos)');

        $this->validar('stg_ev_token', ['participante_existe', 'codigo_repetido'], fn (array $f) => ReglasValidacion::token($f));
    }

    private function encuestas(): void
    {
        DB::statement('UPDATE stg_ev_encuesta SET evento_existe = CASE WHEN EXISTS (SELECT 1 FROM stg_ev_evento e '
            .'WHERE e.old_id = stg_ev_encuesta.old_evento_id AND e.'.self::VIGENTE.') THEN 1 ELSE 0 END');
        DB::statement('UPDATE stg_ev_encuesta SET participante_existe = CASE WHEN EXISTS (SELECT 1 FROM stg_ev_participante p '
            .'WHERE p.old_evento_id = stg_ev_encuesta.old_evento_id AND p.documento_clave = stg_ev_encuesta.documento_clave AND p.documento_clave <> \'\' AND p.'.self::VIGENTE.') THEN 1 ELSE 0 END');

        $this->validar('stg_ev_encuesta', ['evento_existe', 'participante_existe'], fn (array $f) => ReglasValidacion::encuesta($f));
    }

    // ── Validación genérica ────────────────────────────────────────────────────────────────────────────────────

    /**
     * Recalcula validacion/motivo de las filas vigentes con la regla dada y actualiza solo las que cambian, agrupadas por
     * resultado (pocas sentencias aunque haya miles de filas).
     *
     * @param  array<int,string>  $columnas  columnas que la regla necesita
     * @param  callable(array<string,mixed>):array{0:string,1:?string}  $regla
     */
    private function validar(string $tabla, array $columnas, callable $regla): void
    {
        $cambios = [];
        DB::table($tabla)->whereRaw(self::VIGENTE)->orderBy('id')->select(array_merge(['id', 'validacion', 'motivo'], $columnas))
            ->chunkById(1000, function ($filas) use (&$cambios, $regla) {
                foreach ($filas as $f) {
                    $fila = (array) $f;
                    [$validacion, $motivo] = $regla($fila);
                    if ($validacion !== $f->validacion || $motivo !== $f->motivo) {
                        $cambios[$validacion."\0".($motivo ?? "\1")][] = $f->id;
                    }
                }
            });

        foreach ($cambios as $clave => $ids) {
            [$validacion, $motivo] = explode("\0", $clave);
            foreach (array_chunk($ids, 400) as $trozo) {
                DB::table($tabla)->whereIn('id', $trozo)->update(['validacion' => $validacion, 'motivo' => $motivo === "\1" ? null : $motivo]);
            }
        }
    }
}
