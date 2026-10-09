<?php

namespace App\Support\CredentialFlow\Migracion;

use RuntimeException;

/** El rollback técnico de una corrida se NIEGA (no se toca nada). El mensaje es técnico y no lleva datos personales. */
final class RollbackNoPermitido extends RuntimeException
{
    public const NO_EXISTE = 'NO_EXISTE';

    public const NO_COMPLETADA = 'NO_COMPLETADA';

    public const CORRIDA_POSTERIOR = 'CORRIDA_POSTERIOR';

    public const PDF_CONGELADO = 'PDF_CONGELADO';

    public const CERTIFICADOS_MODIFICADOS = 'CERTIFICADOS_MODIFICADOS';

    public const DESCARGAS_NUEVAS = 'DESCARGAS_NUEVAS';

    public const CORREOS_NUEVOS = 'CORREOS_NUEVOS';

    public const EVENTOS_EN_USO = 'EVENTOS_EN_USO';

    public const PLANTILLAS_COMPARTIDAS = 'PLANTILLAS_COMPARTIDAS';

    public const CAMBIO_CONCURRENTE = 'CAMBIO_CONCURRENTE';

    /** Encuestas (Fase 8): hay respuestas posteriores a la corrida sobre sus versiones. */
    public const RESPUESTAS_POSTERIORES = 'RESPUESTAS_POSTERIORES';

    public const ESTRUCTURA_MODIFICADA = 'ESTRUCTURA_MODIFICADA';

    public const RESPUESTAS_MODIFICADAS = 'RESPUESTAS_MODIFICADAS';

    /** Las respuestas de una corrida de encuestas están ligadas a eventos/certificados de esta corrida de certificados. */
    public const ENCUESTAS_VINCULADAS = 'ENCUESTAS_VINCULADAS';

    /** Conciliaciones (Fase 10A): hay casos con decisiones humanas o eventos posteriores a la detección. */
    /** Códigos históricos (Fase 10B-1.5): hay códigos asignados por Credential Flow sobre estos certificados o eventos; son inmutables. */
    public const CODIGOS_ASIGNADOS = 'CODIGOS_ASIGNADOS';

    public const CASOS_CON_DECISIONES = 'CASOS_CON_DECISIONES';

    public function __construct(public readonly string $codigo, string $mensaje)
    {
        parent::__construct($codigo.': '.$mensaje);
    }
}
