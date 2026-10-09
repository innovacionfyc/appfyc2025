<?php

namespace App\Support\CredentialFlow\Legado;

use App\Support\CredentialFlow\StagingEv\Normalizador;
use Illuminate\Support\Facades\DB;

/**
 * Evidencia técnica sobre las imágenes del staging histórico, SOLO LECTURA y sin decidir nada:
 *   - eventos con imagen pendiente (faltante / extensión inválida) y cuántos participantes afectan;
 *   - candidatas por normalización de nombre (la imagen de un evento pudo guardarse con otro nombre);
 *   - imágenes huérfanas (catalogadas, sin evento que las use).
 * NUNCA enlaza una candidata a un evento ni propone un ganador. No expone nombres de archivo ni datos personales: solo
 * identificadores, SHA-256, medidas y puntuaciones de similitud.
 */
final class EvidenciaPlantillas
{
    /** @return array<string,mixed> */
    public function generar(): array
    {
        $eventos = DB::table('stg_ev_evento')->whereIn('imagen_estado', ['archivo_faltante', 'extension_invalida'])->orderBy('old_id')
            ->get(['old_id', 'imagen_estado', 'imagen_original', 'participantes_count']);

        $candidatas = DB::table('stg_ev_imagenes')->where('candidata_revision', true)->orderBy('candidata_old_evento_id')->orderBy('sha256')->get();
        $porEvento = DB::table('stg_ev_evento')->whereIn('old_id', $candidatas->pluck('candidata_old_evento_id')->all())->get()->keyBy('old_id');

        $filaCandidata = function ($i) use ($porEvento): array {
            $evento = $porEvento[$i->candidata_old_evento_id] ?? null;
            $a = self::base((string) ($evento->imagen_original ?? ''));
            $b = self::base((string) $i->nombre_original);

            return [
                'old_evento_id' => (int) $i->candidata_old_evento_id,
                'evento_imagen_estado' => $evento->imagen_estado ?? null,
                'sha256' => $i->sha256,
                'similitud_nombre' => self::similitud($a, $b),
                'mime_real' => $i->mime_real,
                'ancho_px' => $i->ancho_px === null ? null : (int) $i->ancho_px,
                'alto_px' => $i->alto_px === null ? null : (int) $i->alto_px,
                'bytes' => (int) $i->bytes,
                'renderizable_fpdf' => (bool) $i->renderizable_fpdf,
                'decision' => 'NINGUNA: solo evidencia (no se enlaza automáticamente)',
            ];
        };

        $huerfanas = DB::table('stg_ev_imagenes')->where('huerfana', true)->orderBy('sha256')->get(['sha256', 'mime_real', 'ancho_px', 'alto_px', 'bytes', 'renderizable_fpdf', 'candidata_revision']);

        return [
            'eventos_pendientes' => [
                'total' => $eventos->count(),
                'participantes_afectados' => (int) $eventos->sum('participantes_count'),
                'por_estado' => $eventos->countBy('imagen_estado')->all(),
                'detalle' => $eventos->map(fn ($e) => [
                    'old_evento_id' => (int) $e->old_id,
                    'imagen_estado' => $e->imagen_estado,
                    'participantes' => (int) $e->participantes_count,
                    'tratamiento' => $e->imagen_estado === 'archivo_faltante' ? 'archivo_faltante: no se inventa plantilla' : 'extension_invalida: no renderizable automático',
                ])->values()->all(),
            ],
            'candidatas' => $candidatas->map($filaCandidata)->all(),
            'huerfanas' => [
                'total' => $huerfanas->count(),
                'que_ademas_son_candidata' => $huerfanas->where('candidata_revision', true)->count(),
                'detalle' => $huerfanas->map(fn ($h) => [
                    'sha256' => $h->sha256, 'mime_real' => $h->mime_real, 'ancho_px' => $h->ancho_px, 'alto_px' => $h->alto_px,
                    'bytes' => (int) $h->bytes, 'renderizable_fpdf' => (bool) $h->renderizable_fpdf, 'tratamiento' => 'se conserva catalogada (huerfana); no se elimina',
                ])->all(),
            ],
        ];
    }

    /** Nombre sin extensión, comparable (minúsculas, sin tildes, separadores unificados). */
    private static function base(string $nombre): string
    {
        $pos = strrpos($nombre, '.');

        return Normalizador::claveArchivo($pos === false ? $nombre : substr($nombre, 0, $pos));
    }

    /** @return array{similar_text_pct:float,levenshtein:int,igual_normalizado:bool} */
    private static function similitud(string $a, string $b): array
    {
        similar_text($a, $b, $pct);

        return [
            'similar_text_pct' => round($pct, 1),
            'levenshtein' => levenshtein(substr($a, 0, 255), substr($b, 0, 255)),
            'igual_normalizado' => $a !== '' && $a === $b,
        ];
    }
}
