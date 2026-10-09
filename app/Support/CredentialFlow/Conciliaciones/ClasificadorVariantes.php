<?php

namespace App\Support\CredentialFlow\Conciliaciones;

use Illuminate\Support\Collection;

/**
 * Clasificación conceptual de las variantes de un grupo duplicado (Fase 10B-1.5). Con la nueva semántica del código (en el sistema viejo
 * se asignaba en la primera descarga a TODAS las filas del par que existían entonces), una variante con código y otra con NULL, y nada
 * más distinto, NO es un conflicto de contenido: la fila NULL se agregó después de la descarga. Es el caso «DIF_VERIF» puro
 * (SOLO_VERIF_NULL_VS_VALOR). Solo clasifica; no modifica nada y no resuelve ningún caso.
 */
final class ClasificadorVariantes
{
    /**
     * ¿Las variantes difieren ÚNICAMENTE en tener o no código (un solo código distinto y al menos una fila sin código)?
     *
     * @param  Collection<int,object>  $filas  todas las variantes del grupo, con `codigo_legado`
     * @param  array<string,mixed>  $snapshot  snapshot de cualquiera de ellas (lleva las etiquetas del grupo)
     */
    public static function soloDifiereEnCodigoNulo(Collection $filas, array $snapshot): bool
    {
        if ($filas->count() < 2 || DetectorConciliaciones::lista($snapshot['duplicado']['etiquetas'] ?? null) !== ['DIF_VERIF']) {
            return false;
        }
        $conCodigo = $filas->filter(fn ($f) => $f->codigo_legado !== null && $f->codigo_legado !== '');

        return $conCodigo->isNotEmpty() && $conCodigo->count() < $filas->count() && $conCodigo->pluck('codigo_legado')->map(fn ($x) => (string) $x)->unique(null, true)->count() === 1;
    }

    /** Variante canónica del par para 10B-2: la que tiene el código del sistema viejo; si ninguna, la de menor id. */
    public static function canonicoDelPar(Collection $filas): ?int
    {
        $f = $filas->sortBy('id')->first(fn ($x) => $x->codigo_legado !== null && $x->codigo_legado !== '') ?? $filas->sortBy('id')->first();

        return $f === null ? null : (int) $f->id;
    }
}
