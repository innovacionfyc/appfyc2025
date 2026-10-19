<?php

namespace App\Console\Commands;

use App\Models\CredentialFlow\Correo;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Support\CredentialFlow\Eventos\ServicioEventos;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Marca (o desmarca) como «de control (QA)» a participantes concretos de una base: los contactos de prueba permanentes que conviven con los reales.
 * Solo escribe `cf_participantes.es_control_qa`: no toca emisiones, PDF, códigos ni estados. Por defecto solo MUESTRA a quiénes alcanzaría (ids, sin datos
 * personales); con --ejecutar aplica el cambio.
 */
class CredentialFlowQaParticipantes extends Command
{
    protected $signature = 'credential-flow:qa-participantes
        {lote : Id de la base}
        {--correo=* : Correo de un contacto QA (repetible); se compara con el correo del participante, normalizado}
        {--todos : Toda la base es de control (solo para bases exclusivamente QA). Con --correo, EXIGE además que todos sus participantes sean de esos correos y aborta si alguno no lo es}
        {--quitar : Quita la marca en lugar de ponerla}
        {--ejecutar : Aplica el cambio (sin esta opción solo se muestra)}';

    protected $description = 'Marca participantes de control (QA) dentro de una base, por correo o la base completa; por defecto solo muestra';

    public function handle(ServicioEventos $servicio): int
    {
        $lote = Lote::query()->find((int) $this->argument('lote'));
        if ($lote === null) {
            $this->error('No existe esa base.');

            return self::FAILURE;
        }
        $correos = collect((array) $this->option('correo'))->map(fn ($c) => Correo::normalizar((string) $c))->filter()->unique()->values();
        $todos = (bool) $this->option('todos');
        if (! $todos && $correos->isEmpty()) {
            $this->error('Indica --correo=... (uno o varios) o --todos.');

            return self::FAILURE;
        }

        $coincide = fn ($w) => $w->whereIn('correo_normalizado', $correos->all())
            ->orWhereIn('id', DB::table('cf_correos')->whereNotNull('participante_id')->whereIn('correo_normalizado', $correos->all())->select('participante_id'));
        $q = Participante::query()->where('lote_id', $lote->id);
        if ($todos && $correos->isNotEmpty()) {
            // Salvaguarda: toda la base solo si TODOS sus participantes son contactos QA conocidos (nunca se marca a un real por accidente).
            // Se compara por ids (no con NOT sobre el correo): un participante sin correo (NULL) también cuenta como ajeno.
            $propios = (clone $q)->where($coincide)->pluck('id');
            $ajenos = (clone $q)->whereNotIn('id', $propios)->pluck('id');
            if ($ajenos->isNotEmpty()) {
                $this->error("La base #{$lote->id} tiene {$ajenos->count()} participante(s) que NO son de los correos QA indicados (ids: ".$ajenos->implode(', ').'). No se marcó nada: usa --correo sin --todos.');

                return self::FAILURE;
            }
        } elseif (! $todos) {
            $q->where($coincide);
        }
        $quitar = (bool) $this->option('quitar');
        $alcance = $q->get(['id', 'es_control_qa']);
        $cambian = $alcance->where('es_control_qa', $quitar)->pluck('id');

        $this->line("Base #{$lote->id}: {$alcance->count()} participante(s) alcanzado(s), {$cambian->count()} cambiarían a ".($quitar ? 'REAL' : 'QA').'.');
        if ($cambian->isNotEmpty()) {
            $this->line('Ids: '.$cambian->implode(', '));
        }
        if (! $this->option('ejecutar')) {
            $this->line('Vista previa: no se escribió nada. Repite con --ejecutar para aplicar.');

            return self::SUCCESS;
        }

        $n = $servicio->marcarParticipantesQa($lote, $cambian, ! $quitar);
        $this->info("{$n} participante(s) actualizados. No se tocó ninguna emisión.");

        return self::SUCCESS;
    }
}
