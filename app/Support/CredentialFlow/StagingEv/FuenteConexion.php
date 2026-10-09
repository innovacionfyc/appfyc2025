<?php

namespace App\Support\CredentialFlow\StagingEv;

use Illuminate\Database\ConnectionInterface;

/** Lee el snapshot desde una conexión de base de datos que contiene una restauración del dump (solo SELECT). */
final class FuenteConexion implements FuenteSnapshot
{
    public function __construct(private readonly ConnectionInterface $conexion) {}

    public function filas(string $tabla): iterable
    {
        $this->validar($tabla);

        foreach ($this->conexion->table($tabla)->lazyById(1000, 'id') as $fila) {
            yield (array) $fila;
        }
    }

    public function conteos(): array
    {
        $r = [];
        foreach (self::TABLAS as $tabla) {
            $r[$tabla] = (int) $this->conexion->table($tabla)->count();
        }

        return $r;
    }

    public function esquemaSha256(): ?string
    {
        $esquema = [];
        foreach (self::TABLAS as $tabla) {
            $columnas = [];
            foreach ($this->conexion->getSchemaBuilder()->getColumns($tabla) as $c) {
                $columnas[] = $c['name'].':'.$c['type_name'].':'.($c['nullable'] ? 'null' : 'notnull');
            }
            $esquema[$tabla] = $columnas;
        }

        return hash('sha256', json_encode($esquema, JSON_THROW_ON_ERROR));
    }

    private function validar(string $tabla): void
    {
        if (! in_array($tabla, self::TABLAS, true)) {
            throw new \InvalidArgumentException('Tabla de origen no permitida.');
        }
    }
}
