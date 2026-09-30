<?php

namespace App\Support\CredentialFlow\Participantes;

/** Resultado de validar un archivo de participantes (preview). No toca la base de datos. */
final readonly class ResultadoImportacion
{
    public const PREVIEW_FILAS = 20;

    /** Tope de errores/avisos devueltos al navegador (siempre se informa el total real). */
    public const LISTA_MAX = 200;

    /**
     * @param  array<int,ErrorFila>  $errores
     * @param  array<int,ErrorFila>  $avisos
     * @param  array<int,string>  $columnasIgnoradas
     * @param  array<int,string>  $columnasFaltantes
     * @param  array<int,array{fila:int, nombre_completo:string, documento:string, valida:bool}>  $preview
     * @param  array<int,array{fila:int, nombre_completo:string, documento:string, documento_clave:string}>  $participantes  filas válidas a importar
     */
    public function __construct(
        public int $filasDetectadas,
        public int $filasValidas,
        public int $filasConError,
        public array $errores,
        public array $avisos,
        public array $columnasIgnoradas,
        public array $columnasFaltantes,
        public array $preview,
        public array $participantes,
    ) {}

    public function valido(): bool
    {
        return $this->errores === [] && $this->filasDetectadas > 0;
    }

    /** Resultado de un archivo que no se pudo ni leer. */
    public static function deArchivo(ErrorFila $error): self
    {
        return new self(0, 0, 0, [$error], [], [], [], [], []);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'valido' => $this->valido(),
            'filas_detectadas' => $this->filasDetectadas,
            'filas_validas' => $this->filasValidas,
            'filas_con_error' => $this->filasConError,
            'errores_total' => count($this->errores),
            'errores' => array_map(fn (ErrorFila $e) => $e->toArray(), array_slice($this->errores, 0, self::LISTA_MAX)),
            'avisos_total' => count($this->avisos),
            'avisos' => array_map(fn (ErrorFila $e) => $e->toArray(), array_slice($this->avisos, 0, self::LISTA_MAX)),
            'columnas_ignoradas' => $this->columnasIgnoradas,
            'columnas_faltantes' => $this->columnasFaltantes,
            'preview' => $this->preview,
        ];
    }
}
