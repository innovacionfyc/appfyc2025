<?php

namespace App\Console\Commands;

use App\Models\CredentialFlow\Lote;
use App\Support\CredentialFlow\Emisiones\AdministradorAutorizado;
use App\Support\CredentialFlow\Emisiones\EmisionException;
use App\Support\CredentialFlow\Emisiones\ReemisorBase;
use App\Support\CredentialFlow\Generacion\GeneracionCredencialException;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reemisión MASIVA controlada de los certificados vigentes DESACTUALIZADOS de una base (ver ReemisorBase). Por defecto SOLO simula: cantidades de oficiales y QA,
 * por qué están desactualizados, qué lo impediría y la huella del plan. Para ejecutar hacen falta --ejecutar, esa huella (--plan), un administrador (--usuario)
 * y un motivo. Primero los oficiales y después los QA, cada grupo todo-o-nada; repetirla nunca duplica versiones. No envía correos. Sin datos personales.
 *
 * Al ejecutar, la última línea dice siempre «ESTADO FINAL: COMPLETO | PARCIAL | SIN CAMBIOS» con el resultado de cada grupo. Código de salida: 0 completo o
 * nada que hacer, 1 nada reemitido (error o bloqueo), 2 PARCIAL (un grupo reemitido y el otro sin cambios: volver a simular completa lo pendiente).
 */
class CredentialFlowBaseReemitir extends Command
{
    /** Código de salida propio del resultado parcial (un grupo reemitido y otro sin cambios): distinto del éxito (0) y del fallo sin cambios (1). */
    public const PARCIAL = 2;

    protected $signature = 'credential-flow:base-reemitir
        {base : Id de la base (cf_lotes)}
        {--grupo=todos : oficiales, qa o todos (primero oficiales y después QA)}
        {--motivo= : Por qué se reemite (10 a 500 caracteres; queda en cada versión reemplazada)}
        {--usuario= : Id del administrador que autoriza (rol super-admin o admin)}
        {--plan= : Huella que mostró la simulación (obligatoria para ejecutar)}
        {--ejecutar : Reemite de verdad (sin esta opción solo simula)}';

    protected $description = 'Reemite en masa, con autorización, los certificados vigentes desactualizados de una base; por defecto solo simula';

    public function handle(ReemisorBase $reemisor): int
    {
        $lote = Lote::query()->find((int) $this->argument('base'));
        $grupos = match ((string) $this->option('grupo')) {
            'todos' => [ReemisorBase::OFICIALES, ReemisorBase::QA],
            ReemisorBase::OFICIALES => [ReemisorBase::OFICIALES],
            ReemisorBase::QA => [ReemisorBase::QA],
            default => null,
        };
        if ($lote === null || $grupos === null) {
            $this->error($lote === null ? 'La base no existe (o está eliminada).' : '--grupo debe ser oficiales, qa o todos.');

            return self::FAILURE;
        }

        $planes = $this->simular($reemisor, $lote, $grupos);
        $huella = self::huella($planes);
        $bloqueados = array_filter($planes, fn (array $p) => $p['bloqueo'] !== null);
        $total = array_sum(array_column($planes, 'a_reemitir'));
        $this->line("  Huella del plan: {$huella}");

        if (! $this->option('ejecutar')) {
            if ($total > 0 && $bloqueados === []) {
                $this->line("Para ejecutar: --usuario=ID --motivo=\"...\" --plan={$huella} --ejecutar (requiere autorización). No se envía ningún correo.");
            }

            return $bloqueados === [] ? self::SUCCESS : self::FAILURE;
        }

        if (! is_string($this->option('plan')) || $this->option('plan') === '') {
            $this->error('La simulación es obligatoria: ejecuta primero sin --ejecutar y pasa su huella con --plan=...');

            return self::FAILURE;
        }
        if ($total === 0) {
            $this->info('Nada que reemitir: no hay certificados vigentes desactualizados (repetir una reemisión ya hecha no duplica nada).');
            $this->info('ESTADO FINAL: SIN CAMBIOS — todo está al día.');

            return self::SUCCESS;
        }

        try {
            $usuario = AdministradorAutorizado::exigir($this->option('usuario'));

            return AdministradorAutorizado::corregir($usuario, $lote->id, function () use ($reemisor, $lote, $grupos, $usuario) {
                // Con la base reservada se vuelve a planear TODO: si algo cambió desde la simulación (u otro administrador actuó), no se reemite nada.
                $planes = array_map(fn (string $g) => $reemisor->planear($lote->fresh(), $g), $grupos);
                if (! hash_equals(self::huella($planes), (string) $this->option('plan'))) {
                    throw new EmisionException(EmisionException::PLAN_DESACTUALIZADO, 'Los certificados, la base o la plantilla cambiaron desde la simulación. No se reemitió nada: vuelve a simular.', 409);
                }
                // Si un grupo no se puede reemitir, no empieza ninguno: nunca queda la base a medias por algo que se sabía antes.
                foreach ($planes as $p) {
                    if ($p['bloqueo'] !== null) {
                        throw new EmisionException($p['bloqueo']['code'], "Grupo {$p['grupo']}: {$p['bloqueo']['message']} No se reemitió nada.", 409, $p['bloqueo']['detalles']);
                    }
                }

                // Cada grupo es su propia operación todo-o-nada: su estado final es UNO de «reemitido», «al día» o «sin cambios (error)», nunca a medias.
                $estados = [];
                $hechos = 0;
                foreach ($planes as $p) {
                    if ($p['a_reemitir'] === 0) {
                        $estados[] = "{$p['grupo']}: al día (nada que reemitir)";

                        continue;
                    }
                    try {
                        $r = $reemisor->reemitir($lote->fresh(), $p['grupo'], $p['huella'], (string) $this->option('motivo'), $usuario->id);
                    } catch (Throwable $e) {
                        if ($hechos === 0) {
                            throw $e;   // no se reemitió nada en ningún grupo: es un error, no un resultado parcial
                        }
                        $this->error("Grupo {$p['grupo']}: ".self::codigo($e).': '.self::mensaje($e));
                        $estados[] = "{$p['grupo']}: SIN CAMBIOS (0 de {$p['a_reemitir']}; error ".self::codigo($e).')';
                        $this->error('ESTADO FINAL: PARCIAL — '.implode(' · ', $estados).'. Vuelve a simular: solo aparecerá lo pendiente (no se duplica nada).');

                        return self::PARCIAL;
                    }
                    $hechos++;
                    $estados[] = "{$r['grupo']}: REEMITIDOS {$r['total']} de {$p['a_reemitir']}";
                    $this->info("Grupo {$r['grupo']}: {$r['total']} certificado(s) reemitido(s) · operación {$r['operacion']} · quedan desactualizados: {$r['restantes']}.");
                    $this->line("  Integridad: {$r['integridad']['nuevas']} PDF nuevos y {$r['integridad']['anteriores']} PDF anteriores verificados".($r['integridad']['fallidas'] === [] ? '.' : '; NO verificados (emisiones '.implode(', ', $r['integridad']['fallidas']).'): ejecuta credential-flow:verificar-emisiones.'));
                }
                $this->line('Las versiones anteriores quedan como «reemplazadas» con sus PDF intactos; el portal muestra solo las vigentes. No se envió ningún correo.');
                $this->info('ESTADO FINAL: COMPLETO — '.implode(' · ', $estados).'.');

                return self::SUCCESS;
            });
        } catch (Throwable $e) {
            $this->error(self::codigo($e).': '.self::mensaje($e));
            $this->detalles($e instanceof EmisionException ? $e->detalles : []);
            $this->error('ESTADO FINAL: SIN CAMBIOS — no se reemitió ningún certificado.');

            return self::FAILURE;
        }
    }

    private static function codigo(Throwable $e): string
    {
        return $e instanceof EmisionException || $e instanceof GeneracionCredencialException ? $e->codigo : 'ERROR_INESPERADO';
    }

    /** Un error inesperado no se muestra tal cual (puede llevar rutas o datos): ReemisorBase ya lo dejó en el registro, resumido. */
    private static function mensaje(Throwable $e): string
    {
        return $e instanceof EmisionException || $e instanceof GeneracionCredencialException
            ? $e->getMessage()
            : 'Falló la reemisión de este grupo y se revirtió completa (ningún certificado cambió en él). Revisa storage/logs/laravel.log y ejecuta credential-flow:verificar-emisiones.';
    }

    /**
     * @param  array<int,string>  $grupos
     * @return array<int,array<string,mixed>>
     */
    private function simular(ReemisorBase $reemisor, Lote $lote, array $grupos): array
    {
        $this->line(($this->option('ejecutar') ? 'PLAN' : 'SIMULACIÓN (no se escribió nada)').": base #{$lote->id} «{$lote->nombre}»");
        $planes = [];
        foreach ($grupos as $g) {
            $p = $reemisor->planear($lote, $g);
            $planes[] = $p;
            $motivos = implode(', ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($p['motivos']), $p['motivos']));
            $this->line(sprintf('  %-9s plantilla %s · vigentes %d · desactualizados %d (%s) · a reemitir ahora %d%s', strtoupper($g),
                $p['plantilla'] ? "#{$p['plantilla']['id']} «{$p['plantilla']['nombre']}»" : 'NINGUNA', $p['vigentes'], $p['desactualizadas'], $motivos, $p['a_reemitir'],
                $p['desactualizadas'] > $p['a_reemitir'] ? ' (límite '.ReemisorBase::LIMITE.' por operación: repetir después)' : ''));
            if ($p['bloqueo'] !== null) {
                $this->error("    Bloqueo {$p['bloqueo']['code']}: {$p['bloqueo']['message']}");
                $this->detalles($p['bloqueo']['detalles']);
            }
        }

        return $planes;
    }

    /** @param  array<int,array<string,mixed>>  $detalles  solo ids y códigos: nunca nombres ni documentos */
    private function detalles(array $detalles): void
    {
        foreach (array_slice($detalles, 0, 20) as $d) {
            $this->line('    · participante #'.($d['participante_id'] ?? '?').': '.($d['codigo'] ?? '').(isset($d['mensaje']) ? ' — '.$d['mensaje'] : ''));
        }
        if (count($detalles) > 20) {
            $this->line('    · … y '.(count($detalles) - 20).' más.');
        }
    }

    /** @param  array<int,array<string,mixed>>  $planes */
    private static function huella(array $planes): string
    {
        return hash('sha256', implode('|', array_map(fn (array $p) => $p['grupo'].':'.$p['huella'], $planes)));
    }
}
