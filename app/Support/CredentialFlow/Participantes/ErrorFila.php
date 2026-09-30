<?php

namespace App\Support\CredentialFlow\Participantes;

/**
 * Error o aviso de importación, con la fila y la columna del archivo. `valor` es el contenido de la
 * celda ya truncado (máx. 50 caracteres): nunca se devuelve un documento completo sin necesidad.
 */
final readonly class ErrorFila
{
    public const ERROR = 'error';

    public const AVISO = 'aviso';

    public const VALOR_MAX = 50;

    // Archivo
    public const ARCHIVO_INVALIDO = 'ARCHIVO_INVALIDO';

    public const EXTENSION_NO_PERMITIDA = 'EXTENSION_NO_PERMITIDA';

    public const ARCHIVO_MUY_GRANDE = 'ARCHIVO_MUY_GRANDE';

    public const ARCHIVO_CORRUPTO = 'ARCHIVO_CORRUPTO';

    public const ARCHIVO_CON_MACROS = 'ARCHIVO_CON_MACROS';

    public const ARCHIVO_SOSPECHOSO = 'ARCHIVO_SOSPECHOSO';

    public const SIN_FILAS = 'SIN_FILAS';

    public const DEMASIADAS_FILAS = 'DEMASIADAS_FILAS';

    public const DEMASIADAS_COLUMNAS = 'DEMASIADAS_COLUMNAS';

    // Encabezados
    public const COLUMNA_FALTANTE = 'COLUMNA_FALTANTE';

    public const COLUMNA_DUPLICADA = 'COLUMNA_DUPLICADA';

    public const COLUMNA_NIVEL_LOTE = 'COLUMNA_NIVEL_LOTE';

    public const COLUMNA_IGNORADA = 'COLUMNA_IGNORADA';

    // Datos
    public const CELDA_MUY_LARGA = 'CELDA_MUY_LARGA';

    public const VALOR_NO_TEXTO = 'VALOR_NO_TEXTO';

    public const CARACTER_INVALIDO = 'CARACTER_INVALIDO';

    public const CARACTER_NO_SOPORTADO = 'CARACTER_NO_SOPORTADO';

    public const FORMULA_NO_PERMITIDA = 'FORMULA_NO_PERMITIDA';

    public const NOMBRE_VACIO = 'NOMBRE_VACIO';

    public const NOMBRE_MUY_CORTO = 'NOMBRE_MUY_CORTO';

    public const NOMBRE_MUY_LARGO = 'NOMBRE_MUY_LARGO';

    public const DOCUMENTO_VACIO = 'DOCUMENTO_VACIO';

    public const DOCUMENTO_MUY_CORTO = 'DOCUMENTO_MUY_CORTO';

    public const DOCUMENTO_MUY_LARGO = 'DOCUMENTO_MUY_LARGO';

    public const DOCUMENTO_NOTACION_CIENTIFICA = 'DOCUMENTO_NOTACION_CIENTIFICA';

    public const DOCUMENTO_INVALIDO = 'DOCUMENTO_INVALIDO';

    public const DOCUMENTO_DUPLICADO = 'DOCUMENTO_DUPLICADO';

    // Avisos
    public const DOCUMENTO_NUMERICO_EXCEL = 'DOCUMENTO_NUMERICO_EXCEL';

    public const NO_CABE_PROBABLE = 'NO_CABE_PROBABLE';

    public function __construct(
        public string $codigo,
        public string $mensaje,
        public ?int $fila = null,
        public ?string $columna = null,
        public mixed $valor = null,
        public string $tipo = self::ERROR,
    ) {}

    public static function error(string $codigo, string $mensaje, ?int $fila = null, ?string $columna = null, mixed $valor = null): self
    {
        return new self($codigo, $mensaje, $fila, $columna, $valor, self::ERROR);
    }

    public static function aviso(string $codigo, string $mensaje, ?int $fila = null, ?string $columna = null, mixed $valor = null): self
    {
        return new self($codigo, $mensaje, $fila, $columna, $valor, self::AVISO);
    }

    /** @return array{fila:?int, columna:?string, codigo:string, mensaje:string, valor:?string, tipo:string} */
    public function toArray(): array
    {
        return [
            'fila' => $this->fila,
            'columna' => $this->columna,
            'codigo' => $this->codigo,
            'mensaje' => $this->mensaje,
            'valor' => self::truncar($this->valor),
            'tipo' => $this->tipo,
        ];
    }

    public static function truncar(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        $texto = is_scalar($valor) ? (string) $valor : '';
        $texto = preg_replace('/[\p{Cc}]+/u', ' ', $texto) ?? '';

        return mb_strlen($texto) > self::VALOR_MAX ? mb_substr($texto, 0, self::VALOR_MAX).'…' : $texto;
    }
}
