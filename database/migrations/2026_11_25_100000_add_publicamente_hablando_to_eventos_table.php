<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla sobre la que actúa la migración. Las pruebas la apuntan a tablas temporales.
     */
    public string $tabla = 'eventos';

    /**
     * Columna nueva para el banner en celular (opcional en cualquier evento) y la columna tras la cual se ubica.
     */
    private array $columnas = [
        'imagen_movil' => 'imagen_relacionada',
    ];

    private string $columnaTipo = 'tipo_evento';

    private string $tipoNuevo = 'PUB_HABLANDO';

    /**
     * Nombre de columna seguido de enum('...','...'); captura el prefijo y la lista de valores.
     */
    private const PATRON_ENUM = "/^(`(?:[^`]|``)+`\s+)enum\(((?:'(?:[^'\\\\]|''|\\\\.)*'\s*,?\s*)+)\)/i";

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable($this->tabla)) {
            return;
        }

        foreach ($this->columnas as $columna => $despuesDe) {
            if (Schema::hasColumn($this->tabla, $columna)) {
                continue;
            }

            Schema::table($this->tabla, function (Blueprint $table) use ($columna, $despuesDe) {
                $definicion = $table->string($columna)->nullable();

                if (Schema::hasColumn($this->tabla, $despuesDe)) {
                    $definicion->after($despuesDe);
                }
            });
        }

        $plan = $this->planAmpliacion();

        if ($plan['sentencia'] !== null) {
            $this->ejecutarConservandoDefinicion($plan['sentencia']);
        }
    }

    /**
     * Reverse the migrations.
     *
     * Solo revierte si no se pierde ningún dato: ningún evento (incluidos los de la papelera)
     * puede usar PUB_HABLANDO ni tener un banner para celular guardado.
     */
    public function down(): void
    {
        if (! Schema::hasTable($this->tabla)) {
            return;
        }

        $bloqueos = $this->bloqueosReversion();

        if ($bloqueos !== []) {
            throw new RuntimeException(
                'No se revierte la migración de PUB_HABLANDO para no perder datos: '.implode('; ', $bloqueos).'.'
            );
        }

        $definicion = $this->definicionTipo();

        if ($definicion !== null && in_array($this->tipoNuevo, $definicion['valores'], true)) {
            $valores = array_values(array_diff($definicion['valores'], [$this->tipoNuevo]));
            $this->ejecutarConservandoDefinicion($this->sentenciaModificar($definicion, $valores));
        }

        foreach (array_keys($this->columnas) as $columna) {
            if (! Schema::hasColumn($this->tabla, $columna)) {
                continue;
            }

            Schema::table($this->tabla, function (Blueprint $table) use ($columna) {
                $table->dropColumn($columna);
            });
        }
    }

    /**
     * Qué haría up() con el ENUM, sin ejecutar nada. Útil para revisar el plan antes de migrar.
     *
     * @return array{motor: string, definicion: ?array, sentencia: ?string, motivo: string}
     */
    public function planAmpliacion(): array
    {
        $motor = DB::getDriverName();
        $definicion = $this->definicionTipo();

        if ($definicion === null) {
            return ['motor' => $motor, 'definicion' => null, 'sentencia' => null,
                'motivo' => 'La columna no existe, no es ENUM o el motor no es MySQL/MariaDB: no se modifica.'];
        }

        if (in_array($this->tipoNuevo, $definicion['valores'], true)) {
            return ['motor' => $motor, 'definicion' => $definicion, 'sentencia' => null,
                'motivo' => "El ENUM ya incluye {$this->tipoNuevo}: no se modifica."];
        }

        return [
            'motor' => $motor,
            'definicion' => $definicion,
            'sentencia' => $this->sentenciaModificar($definicion, [...$definicion['valores'], $this->tipoNuevo]),
            'motivo' => "Se añade {$this->tipoNuevo} al final conservando el resto de la definición.",
        ];
    }

    /**
     * Motivos por los que down() no puede ejecutarse sin perder datos.
     */
    public function bloqueosReversion(): array
    {
        $bloqueos = [];

        if (Schema::hasColumn($this->tabla, $this->columnaTipo)) {
            $enUso = DB::table($this->tabla)->where($this->columnaTipo, $this->tipoNuevo)->count();

            if ($enUso > 0) {
                $bloqueos[] = "{$enUso} evento(s) con {$this->columnaTipo} = {$this->tipoNuevo}";
            }
        }

        foreach (array_keys($this->columnas) as $columna) {
            if (! Schema::hasColumn($this->tabla, $columna)) {
                continue;
            }

            $conArchivo = DB::table($this->tabla)->whereNotNull($columna)->where($columna, '<>', '')->count();

            if ($conArchivo > 0) {
                $bloqueos[] = "{$conArchivo} evento(s) con archivo en {$columna}";
            }
        }

        $definicion = $this->definicionTipo();

        if ($definicion !== null && $definicion['atributos']['COLUMN_DEFAULT'] !== null
            && trim($definicion['atributos']['COLUMN_DEFAULT'], "'") === $this->tipoNuevo) {
            $bloqueos[] = "el valor por defecto de {$this->columnaTipo} es {$this->tipoNuevo}";
        }

        return $bloqueos;
    }

    /**
     * Definición real de la columna: la línea de SHOW CREATE TABLE, los valores del ENUM
     * y los atributos de information_schema que no deben cambiar.
     */
    private function definicionTipo(): ?array
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            || ! Schema::hasColumn($this->tabla, $this->columnaTipo)) {
            return null;
        }

        $crear = (array) DB::selectOne('SHOW CREATE TABLE '.$this->identificador($this->tabla));
        $sql = (string) ($crear['Create Table'] ?? array_values($crear)[1] ?? '');

        $linea = null;
        foreach (preg_split('/\R/', $sql) as $candidata) {
            $candidata = trim($candidata);
            if (str_starts_with($candidata, $this->identificador($this->columnaTipo).' ')) {
                $linea = rtrim($candidata, ',');
                break;
            }
        }

        if ($linea === null || ! preg_match(self::PATRON_ENUM, $linea, $partes)) {
            return null;
        }

        preg_match_all("/'((?:[^'\\\\]|''|\\\\.)*)'/", $partes[2], $valores);

        return [
            'linea' => $linea,
            'valores' => array_map(fn ($v) => str_replace("''", "'", $v), $valores[1]),
            'atributos' => $this->atributosTipo(),
        ];
    }

    private function atributosTipo(): array
    {
        $fila = DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $this->tabla)
            ->where('column_name', $this->columnaTipo)
            ->first(['IS_NULLABLE', 'COLUMN_DEFAULT', 'CHARACTER_SET_NAME', 'COLLATION_NAME', 'COLUMN_COMMENT']);

        return array_change_key_case((array) $fila, CASE_UPPER);
    }

    /**
     * Sustituye solo la lista de valores en la definición existente; nulabilidad, valor por
     * defecto, juego de caracteres, collation y comentario se conservan tal como están.
     */
    private function sentenciaModificar(array $definicion, array $valores): string
    {
        $lista = implode(',', array_map(fn ($v) => "'".str_replace(['\\', "'"], ['\\\\', "''"], $v)."'", $valores));
        $linea = preg_replace_callback(self::PATRON_ENUM, fn ($m) => $m[1]."enum({$lista})", $definicion['linea'], 1);

        return 'ALTER TABLE '.$this->identificador($this->tabla).' MODIFY COLUMN '.$linea;
    }

    /**
     * Ejecuta el cambio y comprueba después que la definición de la columna no se alteró.
     */
    private function ejecutarConservandoDefinicion(string $sentencia): void
    {
        $antes = $this->atributosTipo();

        DB::statement($sentencia);

        $despues = $this->atributosTipo();

        if ($antes != $despues) {
            throw new RuntimeException(
                "La definición de {$this->tabla}.{$this->columnaTipo} cambió al modificar el ENUM. Antes: "
                .json_encode($antes, JSON_UNESCAPED_UNICODE).' Después: '.json_encode($despues, JSON_UNESCAPED_UNICODE)
            );
        }
    }

    private function identificador(string $nombre): string
    {
        return '`'.str_replace('`', '``', $nombre).'`';
    }
};
