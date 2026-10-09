<?php

namespace App\Support\CredentialFlow\Reemplazo;

use App\Support\CredentialFlow\Conciliaciones\ResolucionNoPermitida;
use App\Support\CredentialFlow\Participantes\CeldaCruda;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Participantes\ValidadorParticipante;

/**
 * Reglas EXPLÍCITAS con las que un administrador aprueba el documento (o el nombre) que se imprimirá en la emisión moderna. Nada se normaliza
 * en silencio: cada regla produce un valor concreto, que además debe estar YA en el formato moderno (si el validador del importador lo cambiaría,
 * se rechaza). El histórico no se toca nunca.
 *
 *  Documento                      Categoría del caso            Exigencias
 *  documento_sin_nbsp             DOC_WHITESPACE_*              ninguna (quita el espacio de no separación y los de los bordes)
 *  documento_con_separadores      DOC_SEPARADORES               valor aprobado por el admin + confirmación; mismos caracteres que el histórico
 *  documento_sin_signo            DOC_OTRO                      confirmación explícita; solo letras y dígitos del histórico
 *  valor_confirmado_manual        cualquiera (única para DOC_LETRAS)   valor + confirmación + evidencia manual
 *  documento_sin_cambio           DIF_NOMBRE (Fase 10B-3C-2)    ninguna: el documento histórico ya es imprimible y se imprime tal cual
 *  Nombre
 *  nombre_historico               —                             el nombre histórico tal cual, ya en formato moderno
 *  nombre_confirmado              —                             valor + confirmación + evidencia manual
 *                                                                En DIF_NOMBRE es la ÚNICA regla de nombre: el valor es una variante histórica EXACTA elegida a propósito, o un nombre externo; ambos
 *                                                                exigen confirmación y evidencia (10–1000). Jamás se elige por frecuencia, distancia, longitud, descarga, código ni correo.
 */
final class ReglasValorAprobado
{
    public const DOC_SIN_NBSP = 'documento_sin_nbsp';

    public const DOC_CON_SEPARADORES = 'documento_con_separadores';

    public const DOC_SIN_SIGNO = 'documento_sin_signo';

    public const DOC_MANUAL = 'valor_confirmado_manual';

    public const DOC_SIN_CAMBIO = 'documento_sin_cambio';

    public const CAT_DIF_NOMBRE = 'DIF_NOMBRE';

    public const ORIGEN_VARIANTE = 'variante_historica';

    public const ORIGEN_EXTERNO = 'externo';

    public const NOMBRE_HISTORICO = 'nombre_historico';

    public const NOMBRE_CONFIRMADO = 'nombre_confirmado';

    public const EVIDENCIA_MIN = 10;

    public const EVIDENCIA_MAX = 500;

    /** Evidencia de un nombre aprobado en DIF_NOMBRE (texto que declara la fuente; en la auditoría solo queda su SHA-256 y longitud). */
    public const EVIDENCIA_NOMBRE_MAX = 1000;

    /** Motivo de origen de un caso de revisión de documento → reglas de documento que admite. */
    private const REGLAS_POR_CATEGORIA = [
        'DOC_WHITESPACE_CAMBIA_IMPRESION' => [self::DOC_SIN_NBSP, self::DOC_MANUAL],
        'DOC_SEPARADORES' => [self::DOC_CON_SEPARADORES, self::DOC_MANUAL],
        'DOC_OTRO' => [self::DOC_SIN_SIGNO, self::DOC_MANUAL],
        'DOC_LETRAS' => [self::DOC_MANUAL],
        // 10B-3C-4: documento VACÍO con nombre. Solo con el documento correcto aportado desde fuera (evidencia externa registrada) y la regla manual; jamás se infiere.
        'DOC_VACIO' => [self::DOC_MANUAL],
        self::CAT_DIF_NOMBRE => [self::DOC_SIN_CAMBIO],
    ];

    /** @return list<string> motivos de origen que admiten reemplazo */
    public static function categorias(): array
    {
        return array_keys(self::REGLAS_POR_CATEGORIA);
    }

    /** @return list<string> reglas de documento que admite la categoría */
    public static function reglasPara(string $categoria): array
    {
        return self::REGLAS_POR_CATEGORIA[$categoria] ?? [];
    }

    /**
     * Lo que la pantalla de reemplazo PROPONE por categoría (sin aplicar nada): el valor que cada regla produciría y qué exige. DOC_LETRAS
     * alfanumérico no tiene propuesta automática: solo la regla manual, que parte vacía.
     *
     * @param  object  $cert  fila con `documento`
     * @return list<array{regla:string,valor:?string,requiere_confirmacion:bool,requiere_evidencia:bool,reforzada:bool,editable:bool}>
     */
    public static function propuestas(string $categoria, object $cert): array
    {
        $doc = (string) $cert->documento;

        return array_map(fn (string $regla) => [
            'regla' => $regla,
            'valor' => match ($regla) {
                self::DOC_SIN_CAMBIO => $doc,
                self::DOC_SIN_NBSP, self::DOC_CON_SEPARADORES => Texto::limpiar($doc),
                self::DOC_SIN_SIGNO => (string) preg_replace('/[^\p{L}\p{N}]/u', '', $doc),
                default => null,
            },
            'requiere_confirmacion' => ! in_array($regla, [self::DOC_SIN_NBSP, self::DOC_SIN_CAMBIO], true),
            'requiere_evidencia' => $regla === self::DOC_MANUAL,
            'reforzada' => $regla === self::DOC_SIN_SIGNO,
            'editable' => in_array($regla, [self::DOC_CON_SEPARADORES, self::DOC_MANUAL], true),
        ], self::reglasPara($categoria));
    }

    /**
     * @param  object  $cert  fila con `documento` y `documento_clave`
     * @return array{regla:string,valor:string}
     *
     * @throws ResolucionNoPermitida
     */
    public static function documento(SolicitudReemplazo $s, object $cert, string $categoria): array
    {
        $regla = $s->reglaDocumento;
        $historico = (string) $cert->documento;
        $clave = (string) $cert->documento_clave;

        if (! in_array($regla, self::reglasPara($categoria), true)) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::REGLA_NO_APLICA, $categoria === 'DOC_LETRAS'
                ? 'Este documento alfanumérico no se corrige de forma automática: requiere un valor confirmado con evidencia manual.'
                : 'Esa regla no corresponde a este tipo de diferencia en el documento.');
        }

        $valor = match ($regla) {
            self::DOC_SIN_NBSP => Texto::limpiar($historico),
            self::DOC_SIN_CAMBIO => $historico,
            self::DOC_CON_SEPARADORES, self::DOC_MANUAL => self::requerido($s->valorDocumento, 'Indica el documento aprobado.'),
            self::DOC_SIN_SIGNO => (string) preg_replace('/[^\p{L}\p{N}]/u', '', $historico),
        };

        if ($regla === self::DOC_MANUAL) {
            self::exigirConfirmacion($s);
            self::exigirEvidencia($s);
        } elseif (! in_array($regla, [self::DOC_SIN_NBSP, self::DOC_SIN_CAMBIO], true)) {
            self::exigirConfirmacion($s);
        }

        // Una regla de FORMATO nunca cambia la identidad: mismas letras y dígitos que el histórico. Solo el valor manual puede ser otro.
        if ($regla !== self::DOC_MANUAL && Texto::claveDocumento($valor) !== $clave) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::VALOR_NO_VALIDO, 'El documento aprobado no conserva las letras y dígitos del documento histórico.');
        }

        self::validarDocumento($valor);

        return ['regla' => $regla, 'valor' => $valor];
    }

    /**
     * @param  array<int,string>  $variantes  id del certificado → nombre histórico exacto de cada variante del caso (solo DIF_NOMBRE)
     * @return array{regla:string,valor:string,origen:?string,variante_id:?int}
     *
     * @throws ResolucionNoPermitida
     */
    public static function nombre(SolicitudReemplazo $s, object $cert, string $categoria = '', array $variantes = []): array
    {
        $regla = $s->reglaNombre;
        if ($categoria === self::CAT_DIF_NOMBRE) {
            // Sin valor por defecto: nunca se imprime «el nombre de la raíz» por ser la raíz. Debe aprobarse un valor, con confirmación y evidencia.
            if ($regla !== self::NOMBRE_CONFIRMADO) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::REGLA_NO_APLICA, 'Elige explícitamente el nombre que se imprimirá (una variante histórica o un nombre confirmado con evidencia).');
            }
            $valor = self::requerido($s->valorNombre, 'Indica el nombre aprobado.');
            self::exigirConfirmacion($s);
            self::exigirEvidencia($s, self::EVIDENCIA_NOMBRE_MAX);
            self::validarNombre($valor);
            $varianteId = array_search($valor, $variantes, true);

            return ['regla' => $regla, 'valor' => $valor, 'origen' => $varianteId === false ? self::ORIGEN_EXTERNO : self::ORIGEN_VARIANTE, 'variante_id' => $varianteId === false ? null : (int) $varianteId];
        }
        $valor = match ($regla) {
            self::NOMBRE_HISTORICO => (string) $cert->nombre_completo,
            self::NOMBRE_CONFIRMADO => self::requerido($s->valorNombre, 'Indica el nombre confirmado.'),
            default => throw new ResolucionNoPermitida(ResolucionNoPermitida::REGLA_NO_APLICA, 'La regla de nombre no existe.'),
        };
        if ($regla === self::NOMBRE_CONFIRMADO) {
            self::exigirConfirmacion($s);
            self::exigirEvidencia($s);
        }

        self::validarNombre($valor);

        return ['regla' => $regla, 'valor' => $valor, 'origen' => null, 'variante_id' => null];
    }

    /** SHA-256 y longitud de un valor aprobado: lo ÚNICO que la auditoría conserva de él. @return array{sha256:string,longitud:int} */
    public static function huella(string $valor): array
    {
        return ['sha256' => hash('sha256', $valor), 'longitud' => mb_strlen($valor)];
    }

    /** El documento debe estar YA en el formato moderno: lo que el importador aceptaría y no cambiaría. @throws ResolucionNoPermitida */
    public static function validarDocumento(string $valor): void
    {
        $r = ValidadorParticipante::validar(CeldaCruda::texto('PERSONA PRUEBA'), CeldaCruda::texto($valor));
        if ($r->documento === null || $r->documento !== $valor || self::hayErrorDe($r, 'documento')) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::VALOR_NO_VALIDO, 'El documento aprobado no es válido para imprimir (vacío, con espacios o caracteres no permitidos, o demasiado corto).');
        }
    }

    /** @throws ResolucionNoPermitida */
    public static function validarNombre(string $valor): void
    {
        $r = ValidadorParticipante::validar(CeldaCruda::texto($valor), CeldaCruda::texto('0000'));
        if ($r->nombre === null || $r->nombre !== $valor || self::hayErrorDe($r, 'nombre_completo')) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::VALOR_NO_VALIDO, 'El nombre aprobado no es válido para imprimir (vacío, demasiado largo, en minúsculas o con caracteres que la fuente no imprime).');
        }
    }

    private static function hayErrorDe(object $r, string $columna): bool
    {
        foreach ($r->errores as $e) {
            if (($e->columna ?? null) === $columna) {
                return true;
            }
        }

        return false;
    }

    /** @throws ResolucionNoPermitida */
    private static function requerido(?string $valor, string $mensaje): string
    {
        if ($valor === null || trim($valor) === '') {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::VALOR_NO_VALIDO, $mensaje);
        }

        return $valor;
    }

    /** @throws ResolucionNoPermitida */
    private static function exigirConfirmacion(SolicitudReemplazo $s): void
    {
        if (! $s->confirmado) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::CONFIRMACION_REQUERIDA, 'Debes confirmar explícitamente el valor aprobado.');
        }
    }

    /** @throws ResolucionNoPermitida */
    private static function exigirEvidencia(SolicitudReemplazo $s, int $max = self::EVIDENCIA_MAX): void
    {
        $largo = mb_strlen(trim((string) $s->evidencia));
        if ($largo < self::EVIDENCIA_MIN || $largo > $max) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::EVIDENCIA_REQUERIDA, 'Indica la evidencia que respalda el valor (entre '.self::EVIDENCIA_MIN.' y '.$max.' caracteres).');
        }
    }
}
