<?php

namespace App\Support\CredentialFlow\Legado;

use RuntimeException;

/** Fallo controlado al resolver o asignar el código de un certificado histórico. El mensaje es técnico y no lleva datos personales. */
final class CodigoHistoricoException extends RuntimeException
{
    /** Ya no quedan números libres en el rango reservado (50000–99999 por defecto). */
    public const RANGO_AGOTADO = 'RANGO_CODIGOS_HISTORICOS_AGOTADO';

    /** Un mismo par tiene códigos distintos (varios legados, o legado y de Credential Flow): no se elige ninguno. */
    public const CODIGOS_EN_CONFLICTO = 'CODIGOS_HISTORICOS_EN_CONFLICTO';

    /** Estado imposible: hay un PDF congelado pero no se encuentra ningún código para su par. Nunca se inventa otro. */
    public const PDF_SIN_CODIGO = 'PDF_CONGELADO_SIN_CODIGO';

    public const CONTADOR_AUSENTE = 'CONTADOR_CODIGOS_AUSENTE';

    public function __construct(public readonly string $codigo, string $detalle = '')
    {
        parent::__construct($codigo.($detalle === '' ? '' : ': '.$detalle));
    }
}
