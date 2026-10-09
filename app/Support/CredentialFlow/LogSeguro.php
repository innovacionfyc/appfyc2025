<?php

namespace App\Support\CredentialFlow;

use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * Resumen SEGURO de una excepción para el log (Fase 11A). El mensaje de una excepción puede contener parámetros enlazados de SQL (documento, correo, nombre) o rutas con
 * datos: NUNCA se registra. Solo: clase, código técnico (SQLSTATE si es de base de datos), y archivo:línea. Reemplaza al patrón `$e::class.': '.$e->getMessage()`.
 */
final class LogSeguro
{
    public static function resumen(Throwable $e): string
    {
        $codigo = '';
        if ($e instanceof QueryException || $e instanceof PDOException) {
            $sqlstate = $e instanceof QueryException ? ($e->errorInfo[0] ?? null) : ($e->errorInfo[0] ?? null);
            $codigo = ' [SQLSTATE '.(is_string($sqlstate) && preg_match('/^[0-9A-Z]{5}$/', $sqlstate) === 1 ? $sqlstate : '?').']';
        } elseif (property_exists($e, 'codigo') && is_string($e->codigo) && preg_match('/^[A-Z0-9_]{3,60}$/', $e->codigo) === 1) {
            $codigo = ' ['.$e->codigo.']';
        }

        return $e::class.$codigo.' @'.basename($e->getFile()).':'.$e->getLine();
    }
}
