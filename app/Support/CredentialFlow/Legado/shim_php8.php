<?php

/**
 * Shim EXTERNO para ejecutar FPDF 1.81 (sin modificarlo) en PHP 8: `get_magic_quotes_runtime()` se eliminó en PHP 8.0 y
 * FPDF 1.81 la llama en _dochecks(). Devolver false es exactamente lo que PHP 7.4 hacía con magic quotes desactivadas.
 *
 * Solo se declara si no existe (en PHP 7.x ya existe y no se toca). Lo incluye únicamente Fpdf181::cargar().
 * Archivo sin namespace a propósito: la función debe ser global.
 */
if (! function_exists('get_magic_quotes_runtime')) {
    function get_magic_quotes_runtime(): bool
    {
        return false;
    }
}
