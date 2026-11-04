<?php

namespace App\Support\CredentialFlow\Plantillas;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C2 · Protección del diseño de las plantillas EN USO. El diseño (y el fondo) de una plantilla se puede editar mientras no tenga dependencias; en cuanto
 * tiene emisiones (de cualquier estado, también revocadas) o la usa más de una base, el original queda protegido: se consulta, pero los cambios se hacen
 * en una COPIA (duplicar). Así un cambio nunca altera en silencio lo que verán las reemisiones, las programaciones ni las demás bases que la comparten.
 *
 * Una variante QA cuenta las bases de las plantillas oficiales que la tienen asociada (es el diseño con el que salen sus pruebas internas).
 *
 * COORDINACIÓN (sin carreras entre la comprobación y el guardado). Todo lo que crea una dependencia toma un bloqueo COMPARTIDO sobre la fila de la plantilla
 * y lo mantiene hasta su COMMIT; el guardado toma el bloqueo EXCLUSIVO de esa misma fila y solo con él vuelve a contar las dependencias:
 *  - Emisiones (EmisorCredencial, EmisorLote, ReemisorBase; por ellas pasan también la reemisión individual, el reemplazo histórico y el programador):
 *    releen la plantilla con `sharedLock()` en su fase crítica y la comparan con la huella del PDF ya generado. Si el guardado llegó antes, no emiten.
 *  - Bases (crear una base o cambiarle la plantilla): la clave foránea cf_lotes.plantilla_id → cf_plantillas hace que InnoDB tome el bloqueo compartido
 *    de la plantilla al insertar o actualizar la base. Las emisiones también lo toman al insertarse (cf_emisiones.plantilla_id).
 *  - Asociar una variante QA (sin clave foránea): ServicioQa::asignarVariante bloquea la variante en modo compartido.
 * Los bloqueos duran lo que dura cada fase crítica (los PDF se generan ANTES, fuera de la transacción), así que no frenan al programador.
 * En SQLite (pruebas) no hay bloqueos de fila: la escritura es serial. La concurrencia real se prueba contra MariaDB (tests/Integracion).
 */
final class ProteccionDiseno
{
    public const MENSAJE = 'Esta plantilla tiene dependencias y su diseño está protegido. Para realizar cambios, duplique la plantilla y edite la copia.';

    public const MENSAJE_ARCHIVADA = 'La plantilla está archivada: su diseño no se modifica. Para un diseño nuevo, duplica una plantilla vigente.';

    /** Solo para las pruebas de concurrencia: se ejecuta con la plantilla ya bloqueada y comprobada, justo antes de escribir. */
    public static ?Closure $antesDeGuardar = null;

    /** Con más bases que estas, el diseño queda protegido. */
    public const MAX_BASES_EDITABLE = 1;

    /** @return array{protegida:bool, emisiones:int, revocadas:int, bases:int, motivos:list<string>, mensaje:?string} */
    public static function estado(Plantilla $plantilla): array
    {
        $porEstado = Emision::query()->where('plantilla_id', $plantilla->id)->selectRaw('estado, COUNT(1) n')->groupBy('estado')->pluck('n', 'estado');
        $emisiones = (int) $porEstado->sum();
        $revocadas = (int) ($porEstado[Emision::REVOCADA] ?? 0);
        $bases = self::bases($plantilla);

        $motivos = [];
        if ($emisiones > 0) {
            $motivos[] = $revocadas > 0 ? "Tiene {$emisiones} emisión(es) asociada(s), {$revocadas} de ellas revocada(s)." : "Tiene {$emisiones} emisión(es) asociada(s).";
        }
        if ($bases > self::MAX_BASES_EDITABLE) {
            $motivos[] = "La usan {$bases} bases de participantes.";
        }
        $protegida = $motivos !== [];
        // Una variante QA no se duplica sola (DuplicadorPlantilla no copia plantillas de pruebas): se duplica su oficial y se crea la variante de la copia.
        if ($protegida && $plantilla->esPlantillaQa()) {
            $motivos[] = 'Es una variante de pruebas internas: duplique su plantilla oficial y cree, a partir de la copia, su nueva variante QA.';
        }

        return ['protegida' => $protegida, 'emisiones' => $emisiones, 'revocadas' => $revocadas, 'bases' => $bases, 'motivos' => $motivos, 'mensaje' => $protegida ? self::MENSAJE : null];
    }

    /**
     * Guarda el diseño SOLO si, con la plantilla bloqueada en exclusiva, sigue vigente y sin dependencias. Devuelve null si guardó, o el mensaje del bloqueo.
     *
     * @param  Closure(Plantilla): void  $escribir  la escritura del diseño (y su Movimiento), dentro de la misma transacción
     */
    public static function guardarSiEditable(int $plantillaId, Closure $escribir): ?string
    {
        return DB::transaction(function () use ($plantillaId, $escribir) {
            // Primera lectura de la transacción, y con bloqueo: las cuentas que siguen ven todo lo confirmado por quien tenía el bloqueo compartido.
            $plantilla = Plantilla::query()->lockForUpdate()->find($plantillaId);
            if ($plantilla === null) {
                return 'La plantilla ya no existe.';
            }
            if ($plantilla->estaArchivada()) {
                return self::MENSAJE_ARCHIVADA;
            }
            if (self::protegida($plantilla)) {
                return self::MENSAJE;
            }
            if (self::$antesDeGuardar !== null) {
                (self::$antesDeGuardar)($plantilla);
            }
            $escribir($plantilla);

            return null;
        });
    }

    public static function protegida(Plantilla $plantilla): bool
    {
        return self::estado($plantilla)['protegida'];
    }

    /** Bases (no eliminadas) que dependen del diseño: las que la usan como oficial y, para una variante QA, las de sus plantillas oficiales. */
    public static function bases(Plantilla $plantilla): int
    {
        $plantillas = [$plantilla->id];
        if (Schema::hasColumn('cf_plantillas', 'variante_qa_id')) {
            $plantillas = array_merge($plantillas, Plantilla::withTrashed()->where('variante_qa_id', $plantilla->id)->pluck('id')->map(fn ($id) => (int) $id)->all());
        }

        return Lote::query()->whereIn('plantilla_id', array_unique($plantillas))->count();
    }
}
