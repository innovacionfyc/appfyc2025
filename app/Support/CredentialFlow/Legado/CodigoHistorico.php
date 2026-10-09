<?php

namespace App\Support\CredentialFlow\Legado;

use App\Models\Movimiento;
use App\Support\CredentialFlow\Portal\Hmac;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Código EFECTIVO de un certificado histórico (Fase 10B-1.5). Un solo servicio, dos operaciones:
 *
 *  - resolver():         NO asigna nada. Devuelve el código del PAR lógico (evento + documento): el del sistema viejo
 *                        (`codigo_legado` en cualquiera de sus filas) o, si no hay, el que asignó Credential Flow
 *                        (`cf_codigos_historicos`), o null. Seguro para admin, portal, verificación y listados.
 *  - resolverOAsignar(): para un certificado ELEGIBLE que de verdad va a generarse. Si el par no tiene código, le asigna uno de forma
 *                        atómica e idempotente y lo CONFIRMA (commit) antes de que nadie renderice.
 *
 * Qué significa NULL: en el sistema viejo el código se asignaba en la primera descarga (MAX+1 sobre toda la tabla y un UPDATE a todas las
 * filas del mismo documento+evento). Un certificado sin código es válido y simplemente nunca se descargó. Aquí NO se reproduce el
 * MAX+1: un contador de una sola fila con `lockForUpdate`, rango reservado, UNIQUE por código y por par, y defensa contra cualquier
 * colisión con los códigos históricos. `codigo_legado` NUNCA se escribe: conserva solo lo que existía en el sistema viejo.
 *
 * Reglas: UN código por par (todas las filas y duplicados lo comparten); si una fila ya tiene código legado se reutiliza (no se emite
 * otro); un código asignado es permanente (nunca cambia, se recicla ni se borra).
 */
final class CodigoHistorico
{
    public const ORIGEN_LEGADO = 'legado';

    public const ORIGEN_CREDENTIAL_FLOW = 'credential_flow';

    /** Intentos ante un deadlock del motor (la transacción es corta). */
    private const INTENTOS = 3;

    /** HMAC estable del par (evento + documento lógico): identifica al par sin guardar el documento. */
    public static function parHash(int $eventoId, string $documentoClave): string
    {
        return Hmac::de('par_historico', $eventoId."\0".$documentoClave);
    }

    /**
     * Código del par del certificado, sin asignar nada.
     *
     * @param  object  $c  con `evento_id` y `documento_clave`
     * @return array{codigo:string,origen:string}|null
     *
     * @throws CodigoHistoricoException si el par tiene códigos distintos (no se elige ninguno)
     */
    public function resolver(object $c): ?array
    {
        $filas = DB::table('cf_certificados_legado')->where('evento_id', $c->evento_id)->where('documento_clave', $c->documento_clave)->get(['id', 'codigo_legado']);
        $registro = DB::table('cf_codigos_historicos')->where('par_hash', self::parHash((int) $c->evento_id, (string) $c->documento_clave))->value('codigo');

        return self::decidir($filas, $registro === null ? null : (string) $registro);
    }

    /**
     * Código de cada fila para listados (portal), SIN consultas por fila. `$filas` debe incluir todas las filas de los pares que contiene.
     * Un par con códigos en conflicto devuelve null (no se muestra ninguno).
     *
     * @param  Collection<int,object>  $filas  con `id`, `evento_id`, `documento_clave`, `codigo_legado`
     * @return array<int,?string> id del certificado → código efectivo
     */
    public static function paraFilas(Collection $filas): array
    {
        $pares = $filas->groupBy(fn ($f) => $f->evento_id."\0".$f->documento_clave);
        $hashes = $pares->map(fn ($g) => self::parHash((int) $g->first()->evento_id, (string) $g->first()->documento_clave));
        $registro = $hashes->isEmpty() ? collect() : DB::table('cf_codigos_historicos')->whereIn('par_hash', $hashes->values()->all())->pluck('codigo', 'par_hash');

        $r = [];
        foreach ($pares as $clave => $grupo) {
            try {
                $codigo = self::decidir($grupo, ($registro[$hashes[$clave]] ?? null) === null ? null : (string) $registro[$hashes[$clave]])['codigo'] ?? null;
            } catch (CodigoHistoricoException) {
                $codigo = null;
            }
            foreach ($grupo as $f) {
                $r[(int) $f->id] = $codigo;
            }
        }

        return $r;
    }

    /**
     * Código del par del certificado CANÓNICO, asignándolo si no existe. Idempotente y atómico (una transacción corta con el par y el
     * contador bloqueados). Se llama FUERA de la transacción de render para que el código quede confirmado antes de dibujar: si el render
     * falla después, el número queda reservado y nunca se recicla.
     *
     * @param  object  $canonico  con `id`, `evento_id` y `documento_clave`
     *
     * @throws CodigoHistoricoException
     */
    public function resolverOAsignar(object $canonico): string
    {
        return DB::transaction(function () use ($canonico) {
            // Lock lógico del par: dos procesos sobre el mismo par se serializan aquí y el segundo ve el código del primero.
            $filas = DB::table('cf_certificados_legado')->where('evento_id', $canonico->evento_id)->where('documento_clave', $canonico->documento_clave)->orderBy('id')->lockForUpdate()->get(['id', 'codigo_legado']);
            $hash = self::parHash((int) $canonico->evento_id, (string) $canonico->documento_clave);
            $registro = DB::table('cf_codigos_historicos')->where('par_hash', $hash)->value('codigo');

            // Reutilizar siempre lo que ya exista (legado primero): jamás un segundo código para el mismo par.
            if (($existente = self::decidir($filas, $registro === null ? null : (string) $registro)) !== null) {
                return $existente['codigo'];
            }

            $contador = DB::table('cf_codigo_historico_contador')->where('id', 1)->lockForUpdate()->first()
                ?? throw new CodigoHistoricoException(CodigoHistoricoException::CONTADOR_AUSENTE);
            $n = (int) $contador->siguiente;
            while (true) {
                if ($n > (int) $contador->fin) {
                    throw new CodigoHistoricoException(CodigoHistoricoException::RANGO_AGOTADO, "inicio {$contador->inicio}, fin {$contador->fin}");
                }
                $codigo = (string) $n;
                // Defensa fuerte: aunque el contador diga que está libre, nunca se pisa un código existente (se avanza al siguiente).
                $ocupado = DB::table('cf_certificados_legado')->where('codigo_legado', $codigo)->exists() || DB::table('cf_codigos_historicos')->where('codigo', $codigo)->exists();
                if (! $ocupado) {
                    break;
                }
                $n++;
            }

            DB::table('cf_codigos_historicos')->insert([
                'codigo' => $codigo, 'evento_id' => $canonico->evento_id, 'certificado_canonico_id' => $canonico->id, 'par_hash' => $hash,
                'asignado_at' => now(), 'origen' => 'credential_flow', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('cf_codigo_historico_contador')->where('id', 1)->update(['siguiente' => $n + 1, 'updated_at' => now()]);
            $this->auditar($codigo, (int) $canonico->id, (int) $canonico->evento_id);

            return $codigo;
        }, self::INTENTOS);
    }

    /**
     * @param  Collection<int,object>  $filas  filas del par (con `codigo_legado`)
     * @return array{codigo:string,origen:string}|null
     */
    private static function decidir(Collection $filas, ?string $registro): ?array
    {
        $legados = $filas->pluck('codigo_legado')->filter(fn ($x) => $x !== null && $x !== '')->map(fn ($x) => (string) $x)->unique(null, true)->values();
        if ($legados->count() > 1) {
            throw new CodigoHistoricoException(CodigoHistoricoException::CODIGOS_EN_CONFLICTO, 'el par tiene varios códigos legados');
        }
        $legado = $legados->first();
        if ($legado !== null && $registro !== null && $legado !== $registro) {
            throw new CodigoHistoricoException(CodigoHistoricoException::CODIGOS_EN_CONFLICTO, 'el par tiene un código legado y otro asignado por Credential Flow');
        }

        return match (true) {
            $legado !== null => ['codigo' => $legado, 'origen' => self::ORIGEN_LEGADO],
            $registro !== null => ['codigo' => $registro, 'origen' => self::ORIGEN_CREDENTIAL_FLOW],
            default => null,
        };
    }

    /** Auditoría global sin datos personales (la tabla es la fuente de verdad: si esto falla, la asignación sigue). */
    private function auditar(string $codigo, int $certificadoId, int $eventoId): void
    {
        try {
            Movimiento::create(['user_id' => null, 'tipo' => 'codigo_historico', 'modulo' => 'credential_flow', 'descripcion' => "Código histórico {$codigo} asignado (certificado canónico #{$certificadoId})",
                'metadata' => ['codigo' => $codigo, 'certificado_canonico_id' => $certificadoId, 'evento_id' => $eventoId, 'origen' => self::ORIGEN_CREDENTIAL_FLOW]]);
        } catch (Throwable $e) {
            Log::warning('Credential Flow: no se pudo registrar el movimiento del código histórico.', ['clase' => $e::class]);
        }
    }
}
