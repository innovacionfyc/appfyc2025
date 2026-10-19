<?php

namespace App\Support\CredentialFlow\Qa;

use App\Models\CredentialFlow\ContactoQa;
use App\Models\CredentialFlow\Correo;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Participantes\CeldaCruda;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Participantes\ValidadorCorreo;
use App\Support\CredentialFlow\Participantes\ValidadorParticipante;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Administración de los contactos de control (QA) desde la propia pantalla (sin SSH): listar con su verificación y las bases donde está cada uno, corregir con
 * motivo y confirmación, activar/desactivar y — solo tras comprobarlo — inicializar el catálogo desde las bases QA oficiales.
 *
 * Reglas de seguridad:
 *  - NUNCA se inventa ni se sustituye una identidad sola: todo cambio lo pide una persona, con motivo, y queda en la bitácora SIN datos personales completos
 *    (correos y documentos enmascarados);
 *  - corregir un contacto NO reescribe las bases que ya lo incorporaron (cada base conserva su fila); solo afecta a las bases que se creen después;
 *  - desactivar un contacto solo deja de incorporarlo a bases nuevas; no borra nada;
 *  - el catálogo solo se inicializa cuando todas las bases QA oficiales elegidas contienen EXACTAMENTE los mismos contactos (el número esperado), con documento y correo válidos.
 */
final class AdministradorContactosQa
{
    public const MOTIVO_MIN = 10;

    /** Número de contactos oficiales esperado al inicializar. */
    public static function esperados(): int
    {
        return (int) config('credential_flow.qa.contactos_esperados', 8);
    }

    /**
     * Cada contacto con su verificación y las bases donde está.
     *
     * @return list<array<string,mixed>>
     */
    public function panorama(): array
    {
        if (! ServicioQa::disponible()) {
            return [];
        }
        $contactos = ContactoQa::query()->orderBy('id')->get();
        $porDocumento = $contactos->groupBy('documento_clave');
        $porCorreo = $contactos->groupBy('correo_normalizado');

        $enBases = Participante::query()
            ->join('cf_lotes as l', 'l.id', '=', 'cf_participantes.lote_id')
            ->whereNull('l.deleted_at')->where('cf_participantes.es_control_qa', true)
            ->whereIn('cf_participantes.documento_clave', $contactos->pluck('documento_clave'))
            ->orderBy('l.id')
            ->get(['cf_participantes.documento_clave', 'cf_participantes.nombre_completo', 'cf_participantes.correo_normalizado', 'l.id as lote_id', 'l.nombre as lote_nombre', 'l.datos_comunes'])
            ->groupBy('documento_clave');

        return $contactos->map(function (ContactoQa $c) use ($porDocumento, $porCorreo, $enBases) {
            $bases = ($enBases[$c->documento_clave] ?? collect())->map(function ($b) use ($c) {
                $comunes = is_string($b->datos_comunes) ? (json_decode($b->datos_comunes, true) ?: []) : (array) $b->datos_comunes;

                return [
                    'lote_id' => (int) $b->lote_id, 'nombre' => $b->lote_nombre, 'evento' => $comunes['evento'] ?? null,
                    // ¿La fila de la base coincide con el catálogo? Si no, se informa; jamás se corrige sola.
                    'coincide' => $this->mismoNombre((string) $b->nombre_completo, $c->nombre_completo) && (string) $b->correo_normalizado === $c->correo_normalizado,
                ];
            })->values()->all();

            $avisos = [];
            if (ValidadorParticipante::validar(CeldaCruda::texto($c->nombre_completo), CeldaCruda::texto($c->documento))->errores !== []) {
                $avisos[] = 'El nombre o el documento no son válidos.';
            }
            if (Correo::estadoDe((string) $c->correo_normalizado) !== Correo::ESTADO_VALIDO) {
                $avisos[] = 'El correo no tiene un formato válido.';
            }
            if (($porDocumento[$c->documento_clave] ?? collect())->count() > 1) {
                $avisos[] = 'Otro contacto comparte este documento.';
            }
            if (($porCorreo[$c->correo_normalizado] ?? collect())->count() > 1) {
                $avisos[] = 'Otro contacto comparte este correo.';
            }
            $propiosOk = $avisos === [];
            if (collect($bases)->contains(fn ($b) => ! $b['coincide'])) {
                $avisos[] = 'Alguna base tiene datos distintos a los del catálogo (no se corrigen solas).';
            }

            return [
                'id' => $c->id, 'nombre_completo' => $c->nombre_completo, 'documento' => $c->documento, 'correo' => $c->correo, 'activo' => (bool) $c->activo,
                'verificado' => $avisos === [], 'propios_ok' => $propiosOk, 'avisos' => $avisos, 'bases' => $bases,
            ];
        })->values()->all();
    }

    /**
     * ¿Está el catálogo listo para incorporarse solo a las bases nuevas? Exige EXACTAMENTE los contactos oficiales esperados, activos, con nombre, documento y correo
     * válidos y sin repetirse. (Que una base antigua tenga datos distintos no lo impide: eso se informa en la pantalla, no bloquea.) No escribe.
     *
     * @return array{listo:bool,motivos:list<string>,activos:int,esperados:int}
     */
    public function catalogoListo(): array
    {
        $esperados = self::esperados();
        if (! ServicioQa::disponible()) {
            return ['listo' => false, 'motivos' => ['Los contactos QA todavía no están disponibles en este entorno (falta ejecutar su migración).'], 'activos' => 0, 'esperados' => $esperados];
        }
        $panorama = collect($this->panorama());
        $activos = $panorama->where('activo', true);
        $motivos = [];
        if ($activos->count() !== $esperados) {
            $motivos[] = "El catálogo tiene {$activos->count()} contactos activos y se esperan exactamente {$esperados}.";
        }
        $malos = $activos->where('propios_ok', false)->count();
        if ($malos > 0) {
            $motivos[] = "{$malos} contacto(s) del catálogo no están verificados (nombre, documento o correo inválidos o repetidos).";
        }

        return ['listo' => $motivos === [], 'motivos' => $motivos, 'activos' => $activos->count(), 'esperados' => $esperados];
    }

    /**
     * Corrige nombre, documento y correo de un contacto. Exige motivo y que quien lo pide lo haya confirmado.
     *
     * @throws InvalidArgumentException con el mensaje para mostrar
     */
    public function actualizar(ContactoQa $contacto, string $nombre, string $documento, string $correo, string $motivo, bool $confirmado, ?int $usuarioId = null): ContactoQa
    {
        $this->exigirMotivoYConfirmacion($motivo, $confirmado);

        $r = ValidadorParticipante::validar(CeldaCruda::texto($nombre), CeldaCruda::texto($documento));
        if ($r->errores !== []) {
            throw new InvalidArgumentException($r->errores[0]->mensaje);
        }
        $c = ValidadorCorreo::validar(CeldaCruda::texto($correo), 0);
        if ($c['errores'] !== [] || count($c['correos']) !== 1) {
            throw new InvalidArgumentException('Escribe un único correo con formato válido.');
        }
        $normalizado = Correo::normalizar($c['correos'][0]);

        if (ContactoQa::query()->where('documento_clave', $r->clave)->whereKeyNot($contacto->id)->exists()) {
            throw new InvalidArgumentException('Ya hay otro contacto con ese documento.');
        }
        if (ContactoQa::query()->where('correo_normalizado', $normalizado)->whereKeyNot($contacto->id)->exists()) {
            throw new InvalidArgumentException('Ya hay otro contacto con ese correo.');
        }

        $antes = ['nombre' => $contacto->nombre_completo, 'documento' => $contacto->documento, 'correo' => $contacto->correo_normalizado];
        $contacto->update(['nombre_completo' => $r->nombre, 'documento' => $r->documento, 'documento_clave' => $r->clave, 'correo' => $c['correos'][0], 'correo_normalizado' => $normalizado]);

        $cambios = array_keys(array_filter([
            'nombre' => $antes['nombre'] !== $contacto->nombre_completo,
            'documento' => $antes['documento'] !== $contacto->documento,
            'correo' => $antes['correo'] !== $normalizado,
        ]));
        Movimiento::registrar(tipo: 'actualizacion', modulo: 'credential-flow', descripcion: 'Se corrigió un contacto de control (QA)', extra: [
            'contacto_id' => $contacto->id, 'campos' => $cambios, 'motivo' => Texto::limpiar($motivo),
            'correo_anterior' => ValidadorCorreo::mascara($antes['correo']), 'correo_nuevo' => ValidadorCorreo::mascara($normalizado),
            'documento_anterior' => self::mascaraDocumento($antes['documento']), 'documento_nuevo' => self::mascaraDocumento($contacto->documento),
        ]);

        return $contacto->fresh();
    }

    /** @throws InvalidArgumentException */
    public function cambiarActivo(ContactoQa $contacto, bool $activo, string $motivo, bool $confirmado): ContactoQa
    {
        $this->exigirMotivoYConfirmacion($motivo, $confirmado);
        if ((bool) $contacto->activo !== $activo) {
            $contacto->update(['activo' => $activo]);
            Movimiento::registrar(tipo: 'actualizacion', modulo: 'credential-flow', descripcion: $activo ? 'Se activó un contacto de control (QA)' : 'Se desactivó un contacto de control (QA)', extra: [
                'contacto_id' => $contacto->id, 'motivo' => Texto::limpiar($motivo),
            ]);
        }

        return $contacto->fresh();
    }

    /**
     * Bases cuyos participantes son TODOS de control (QA): candidatas a «base QA oficial».
     *
     * @return list<array{id:int,nombre:string,evento:?string,contactos:int}>
     */
    public function basesQaCandidatas(): array
    {
        if (! ServicioQa::disponible()) {
            return [];
        }

        return Lote::query()
            ->withCount(['participantes as total_count', 'participantes as qa_count' => fn ($q) => $q->where('es_control_qa', true)])
            ->orderBy('id')->get()
            ->filter(fn (Lote $l) => $l->total_count > 0 && $l->total_count === $l->qa_count)
            ->map(fn (Lote $l) => ['id' => $l->id, 'nombre' => $l->nombre, 'evento' => $l->datos_comunes['evento'] ?? null, 'contactos' => (int) $l->total_count])
            ->values()->all();
    }

    /**
     * Comprueba que las bases elegidas contienen los MISMOS contactos (nombre, documento y correo) y el número esperado. No escribe.
     *
     * @param  list<int>  $loteIds
     * @return array{ok:bool,motivos:list<string>,contactos:list<array{nombre:string,documento:string,correo:string}>}
     */
    public function verificarBases(array $loteIds): array
    {
        $loteIds = array_values(array_unique(array_map('intval', $loteIds)));
        $motivos = [];
        $conjuntos = [];
        $detalle = [];
        if ($loteIds === []) {
            return ['ok' => false, 'motivos' => ['Elige al menos una base QA oficial.'], 'contactos' => []];
        }
        foreach ($loteIds as $id) {
            $lote = Lote::query()->find($id);
            if ($lote === null) {
                $motivos[] = "La base #{$id} no existe.";

                continue;
            }
            $filas = Participante::query()->where('lote_id', $id)->get();
            if ($filas->isEmpty() || $filas->contains(fn (Participante $p) => ! $p->es_control_qa)) {
                $motivos[] = "La base #{$id} no es una base QA: tiene participantes reales o está vacía.";

                continue;
            }
            $set = [];
            foreach ($filas as $p) {
                if (Correo::estadoDe((string) $p->correo_normalizado) !== Correo::ESTADO_VALIDO) {
                    $motivos[] = "En la base #{$id} hay un contacto sin correo válido.";
                }
                $set[$p->documento_clave] = $p->documento_clave.'|'.Texto::mayusculas((string) $p->nombre_completo).'|'.$p->correo_normalizado;
                $detalle[$p->documento_clave] = ['nombre' => (string) $p->nombre_completo, 'documento' => (string) $p->documento, 'correo' => (string) $p->correo];
            }
            ksort($set);
            if (count($set) !== $filas->count()) {
                $motivos[] = "En la base #{$id} hay documentos repetidos.";
            }
            if (count($set) !== self::esperados()) {
                $motivos[] = "La base #{$id} tiene ".count($set).' contactos y se esperan '.self::esperados().'.';
            }
            $conjuntos[$id] = array_values($set);
        }
        if (count($conjuntos) > 1 && count(array_unique(array_map('json_encode', $conjuntos))) > 1) {
            $motivos[] = 'Las bases elegidas no contienen exactamente los mismos contactos (nombre, documento y correo).';
        }
        $ok = $motivos === [] && $conjuntos !== [];

        return ['ok' => $ok, 'motivos' => array_values(array_unique($motivos)), 'contactos' => $ok ? array_values($detalle) : []];
    }

    /**
     * Inicializa el catálogo desde las bases QA oficiales, SOLO si `verificarBases` lo permite y solo agrega los que falten (nunca modifica ni reemplaza).
     *
     * @param  list<int>  $loteIds
     * @return array{creados:int,existentes:int}
     *
     * @throws InvalidArgumentException
     */
    public function inicializar(array $loteIds, string $motivo, bool $confirmado): array
    {
        $this->exigirMotivoYConfirmacion($motivo, $confirmado);
        $v = $this->verificarBases($loteIds);
        if (! $v['ok']) {
            throw new InvalidArgumentException('No se puede inicializar: '.implode(' ', $v['motivos']));
        }

        $r = ['creados' => 0, 'existentes' => 0];
        DB::transaction(function () use ($v, &$r) {
            foreach ($v['contactos'] as $c) {
                $res = ValidadorParticipante::validar(CeldaCruda::texto($c['nombre']), CeldaCruda::texto($c['documento']));
                if ($res->errores !== []) {
                    throw new InvalidArgumentException('Un contacto de la base no tiene nombre o documento válidos.');
                }
                if (ContactoQa::query()->where('documento_clave', $res->clave)->exists()) {
                    $r['existentes']++;

                    continue;
                }
                ContactoQa::create([
                    'nombre_completo' => $res->nombre, 'documento' => $res->documento, 'documento_clave' => $res->clave,
                    'correo' => $c['correo'], 'correo_normalizado' => Correo::normalizar($c['correo']), 'activo' => true,
                ]);
                $r['creados']++;
            }
        });
        Movimiento::registrar(tipo: 'registro', modulo: 'credential-flow', descripcion: 'Se inicializó el catálogo de contactos de control (QA) desde las bases oficiales', extra: [
            'lotes' => array_values(array_unique(array_map('intval', $loteIds))), 'creados' => $r['creados'], 'existentes' => $r['existentes'], 'motivo' => Texto::limpiar($motivo),
        ]);

        return $r;
    }

    /** @throws InvalidArgumentException */
    private function exigirMotivoYConfirmacion(string $motivo, bool $confirmado): void
    {
        if (! $confirmado) {
            throw new InvalidArgumentException('Confirma el cambio para continuar.');
        }
        if (mb_strlen(trim($motivo)) < self::MOTIVO_MIN) {
            throw new InvalidArgumentException('El motivo debe explicar el cambio (mínimo '.self::MOTIVO_MIN.' caracteres).');
        }
    }

    private function mismoNombre(string $a, string $b): bool
    {
        return Texto::mayusculas(Texto::limpiar($a)) === Texto::mayusculas(Texto::limpiar($b));
    }

    /** Solo los últimos tres caracteres del documento: suficiente para reconocerlo en la bitácora. */
    private static function mascaraDocumento(string $documento): string
    {
        $limpio = preg_replace('/\s+/u', '', $documento) ?? '';

        return '***'.mb_substr($limpio, -3);
    }

    /** @return Collection<int,ContactoQa> */
    public function activos(): Collection
    {
        return ContactoQa::query()->where('activo', true)->orderBy('id')->get();
    }
}
