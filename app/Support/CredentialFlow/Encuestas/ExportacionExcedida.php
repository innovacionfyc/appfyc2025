<?php

namespace App\Support\CredentialFlow\Encuestas;

use RuntimeException;

/** La exportación solicitada supera el límite de filas: se pide reducir el alcance con los filtros. */
final class ExportacionExcedida extends RuntimeException {}
