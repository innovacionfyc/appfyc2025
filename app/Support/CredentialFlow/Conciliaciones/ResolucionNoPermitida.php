<?php

namespace App\Support\CredentialFlow\Conciliaciones;

use RuntimeException;

/** Una acción de resolución se NIEGA (no se toca nada). El mensaje es humano y no lleva datos personales ni rutas. */
final class ResolucionNoPermitida extends RuntimeException
{
    public const CASO_YA_RESUELTO = 'CASO_YA_RESUELTO';

    public const TIPO_NO_ADMITIDO = 'TIPO_NO_ADMITIDO';

    public const PDF_CONGELADO = 'PDF_CONGELADO';

    public const CERTIFICADOS_CAMBIARON = 'CERTIFICADOS_CAMBIARON';

    public const CANDIDATA_NO_DISPONIBLE = 'CANDIDATA_NO_DISPONIBLE';

    public const CONTENIDO_NO_COINCIDE = 'CONTENIDO_NO_COINCIDE';

    public const TIPO_REAL_NO_SOPORTADO = 'TIPO_REAL_NO_SOPORTADO';

    public const ARCHIVO_INVALIDO = 'ARCHIVO_INVALIDO';

    public const ALMACENAMIENTO = 'ALMACENAMIENTO';

    public const NO_REVERSIBLE = 'NO_REVERSIBLE';

    // Consolidación de DIF_VERIF (10B-2A)
    public const NO_ES_DIFERENCIA_DE_CODIGO = 'NO_ES_DIFERENCIA_DE_CODIGO';

    public const HAY_OTRAS_DIFERENCIAS = 'HAY_OTRAS_DIFERENCIAS';

    public const CODIGO_NO_UNICO = 'CODIGO_NO_UNICO';

    public const EVIDENCIA_DE_DESCARGA = 'EVIDENCIA_DE_DESCARGA';

    // Consolidación de variantes y gestión de casos (10B-2B-1)
    public const NOMBRE_REALMENTE_DISTINTO = 'NOMBRE_REALMENTE_DISTINTO';

    public const CANONICO_INVALIDO = 'CANONICO_INVALIDO';

    // Reemplazo de un certificado histórico por una emisión moderna (10B-2B-2A)
    public const YA_REEMPLAZADO = 'YA_REEMPLAZADO';

    public const CERTIFICADO_NO_PERTENECE = 'CERTIFICADO_NO_PERTENECE';

    public const ESTADO_HISTORICO_INVALIDO = 'ESTADO_HISTORICO_INVALIDO';

    public const REGLA_NO_APLICA = 'REGLA_NO_APLICA';

    public const VALOR_NO_VALIDO = 'VALOR_NO_VALIDO';

    public const CONFIRMACION_REQUERIDA = 'CONFIRMACION_REQUERIDA';

    public const EVIDENCIA_REQUERIDA = 'EVIDENCIA_REQUERIDA';

    public const PLANTILLA_INVALIDA = 'PLANTILLA_INVALIDA';

    public const PLANTILLA_HISTORICA_EXCEDE_CAPACIDAD = 'PLANTILLA_HISTORICA_EXCEDE_CAPACIDAD';

    public const CAMPO_SIN_VALOR = 'CAMPO_SIN_VALOR';

    public const DATO_NO_IMPRIMIBLE = 'DATO_NO_IMPRIMIBLE';

    public const ACTOR_NO_AUTORIZADO = 'ACTOR_NO_AUTORIZADO';

    public const OPERACION_INCONSISTENTE = 'OPERACION_INCONSISTENTE';

    // Interfaz de reemplazo (10B-2B-2B)
    public const DISENO_NO_CONFIRMADO = 'DISENO_NO_CONFIRMADO';

    public const PREVIEW_DESACTUALIZADO = 'PREVIEW_DESACTUALIZADO';

    public const PREVIEW_REQUERIDO = 'PREVIEW_REQUERIDO';

    // Reemplazo único por certificado lógico (10B-2B-2C.1)
    public const YA_REEMPLAZADO_LOGICAMENTE = 'YA_REEMPLAZADO_LOGICAMENTE';

    public const VARIANTE_NO_CANONICA = 'VARIANTE_NO_CANONICA';

    // Decisiones de identidad (10B-3A)
    public const DECISION_CONFLICTIVA = 'DECISION_CONFLICTIVA';

    public const DECISION_YA_REVOCADA = 'DECISION_YA_REVOCADA';

    public const GRUPO_NO_PERTENECE = 'GRUPO_NO_PERTENECE';

    public const CORREO_NO_PERTENECE = 'CORREO_NO_PERTENECE';

    // Autorización masiva con doble control (10B-3C-3)
    public const SEGUNDO_APROBADOR_REQUERIDO = 'SEGUNDO_APROBADOR_REQUERIDO';

    public const YA_APROBADA = 'YA_APROBADA';

    public const APROBACION_NO_APLICA = 'APROBACION_NO_APLICA';

    public const FUENTE_EVIDENCIA_REQUERIDA = 'FUENTE_EVIDENCIA_REQUERIDA';

    public function __construct(public readonly string $codigo, string $mensaje)
    {
        parent::__construct($mensaje);
    }
}
