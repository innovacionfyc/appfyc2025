<?php

namespace App\Support\CredentialFlow\Envios;

use App\Models\CredentialFlow\Envio;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Consultas de SOLO LECTURA de los envíos de correo para el administrador (Fase 9). Una sola consulta paginada (sin N+1) y una agrupada
 * para el resumen. Solo expone lo necesario: fecha, tipo, estado, destinatario ENMASCARADO, intentos y el código técnico de error.
 * Nunca el HMAC, la dirección, el código OTP, el cuerpo, el documento ni la IP.
 */
final class ConsultaEnvios
{
    public const POR_PAGINA = 25;

    /** Columnas que se leen (todo lo demás, incluido el hash del destinatario, ni siquiera sale de la base). */
    private const COLUMNAS = ['id', 'tipo', 'estado', 'destinatario_mascara', 'intentos', 'max_intentos', 'solicitado_at', 'aceptado_at', 'error_clase', 'error_codigo', 'plantilla_version'];

    /** @param array{estado?:?string,tipo?:?string,desde?:?string,hasta?:?string} $f */
    public function listar(array $f): LengthAwarePaginator
    {
        return $this->base($f)->select(self::COLUMNAS)->orderByDesc('solicitado_at')->orderByDesc('id')->paginate(self::POR_PAGINA)
            ->through(fn (Envio $e) => [
                'id' => $e->id,
                'fecha' => $e->solicitado_at?->format('Y-m-d H:i:s'),
                'tipo' => $e->tipo,
                'tipo_etiqueta' => CatalogoEnvios::tipo($e->tipo),
                'estado' => $e->estado,
                'estado_info' => CatalogoEnvios::estado($e->estado),
                'destinatario' => $e->destinatario_mascara,
                'intentos' => (int) $e->intentos,
                'max_intentos' => (int) $e->max_intentos,
                'error_codigo' => $e->error_codigo,
                'error_texto' => CatalogoEnvios::error($e->error_codigo, $e->error_clase),
                'aceptado_at' => $e->aceptado_at?->format('Y-m-d H:i:s'),
            ]);
    }

    /** @return array{total:int,por_estado:array<string,int>} conteos de los envíos que cumplen los filtros de tipo y fecha (no el de estado) */
    public function resumen(array $f): array
    {
        $porEstado = $this->base(['estado' => null] + $f)->selectRaw('estado, count(*) n')->groupBy('estado')->pluck('n', 'estado')->map(fn ($n) => (int) $n)->all();

        return ['total' => array_sum($porEstado), 'por_estado' => array_replace(array_fill_keys(Envio::ESTADOS, 0), $porEstado)];
    }

    private function base(array $f)
    {
        return Envio::query()
            ->when($f['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))
            ->when($f['tipo'] ?? null, fn ($q, $v) => $q->where('tipo', $v))
            ->when($f['desde'] ?? null, fn ($q, $v) => $q->where('solicitado_at', '>=', $v.' 00:00:00'))
            ->when($f['hasta'] ?? null, fn ($q, $v) => $q->where('solicitado_at', '<=', $v.' 23:59:59'));
    }
}
