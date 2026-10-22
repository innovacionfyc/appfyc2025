<?php

namespace App\Support\CredentialFlow\Qa;

use App\Models\CredentialFlow\ContactoQa;
use App\Models\CredentialFlow\Correo;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Contactos de control (QA) de Credential Flow: incorporación AUTOMÁTICA e idempotente a cada base y plantilla de pruebas internas.
 *
 *  - Los contactos salen de `cf_contactos_qa` (sembrada desde las pruebas internas que ya existen; nunca se inventan).
 *  - Cada uno entra a la base como participante con `es_control_qa = true` DESDE LA CREACIÓN: no cuenta como asistente real ni como pendiente oficial.
 *  - Sus certificados se emiten APARTE y SOLO con la plantilla de pruebas internas («PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA»); nunca con la plantilla
 *    académica de la base. Siguen sirviendo para probar el acceso por OTP y la descarga.
 *  - Nada de esto toca emisiones, snapshots ni PDF ya generados.
 * Sin la tabla (código desplegado antes de su migración) todo es un no-op, como el resto de módulos opcionales.
 */
final class ServicioQa
{
    public const ADVERTENCIA = 'PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA';

    public static function disponible(): bool
    {
        return Schema::hasTable('cf_contactos_qa') && Schema::hasColumn('cf_participantes', 'es_control_qa');
    }

    /** @return Collection<int,ContactoQa> */
    public function contactos(): Collection
    {
        return self::disponible() ? ContactoQa::query()->where('activo', true)->orderBy('id')->get() : collect();
    }

    /**
     * Incorpora a la base los contactos QA que le falten. Idempotente: se reconoce por documento (clave) dentro de la base, así que repetirlo no duplica y
     * no toca a nadie que ya esté. Si el documento de un contacto ya está en la base como participante real, NO se reclasifica (eso es decisión del administrador).
     *
     * Si los participantes reales traen modalidad PROPIA (un Excel con presenciales y virtuales), TODOS los contactos entran una vez POR CADA modalidad presente
     * (con esa modalidad), para probar cada certificado: los ocho en cada modalidad, nunca repartidos. Sin modalidades propias, entran una sola vez, como siempre.
     *
     * @return array{creados:int,existentes:int,omitidos:int,total:int}
     */
    public function incorporar(Lote $lote, ?int $usuarioId = null): array
    {
        $contactos = $this->contactos();
        $r = ['creados' => 0, 'existentes' => 0, 'omitidos' => 0, 'total' => $contactos->count()];
        if ($contactos->isEmpty()) {
            return $r;
        }

        DB::transaction(function () use ($lote, $contactos, &$r) {
            Lote::query()->whereKey($lote->id)->lockForUpdate()->first(); // dos incorporaciones simultáneas de la misma base se serializan
            $conModalidad = Schema::hasColumn('cf_participantes', 'modalidad');
            $presentes = Participante::query()->where('lote_id', $lote->id)->get(['documento_clave', 'es_control_qa', ...($conModalidad ? ['modalidad'] : [])]);
            $reales = $presentes->where('es_control_qa', false)->pluck('documento_clave')->flip();
            $qa = $presentes->where('es_control_qa', true)->mapWithKeys(fn (Participante $p) => [self::claveQa($p->documento_clave, $conModalidad ? $p->modalidad : null) => true]);
            $variantes = $conModalidad ? (self::modalidadesDe($lote) ?: [null]) : [null];
            $r['total'] = $contactos->count() * count($variantes);
            $hayCorreos = Schema::hasTable('cf_correos');

            foreach ($contactos as $c) {
                foreach ($variantes as $modalidad) {
                    if ($reales->has($c->documento_clave)) {
                        $r['omitidos']++;   // un participante real con el mismo documento: se omite y se informa

                        continue;
                    }
                    if ($qa->has(self::claveQa($c->documento_clave, $modalidad))) {
                        $r['existentes']++;

                        continue;
                    }
                    $p = Participante::create([
                        'lote_id' => $lote->id, 'nombre_completo' => $c->nombre_completo, 'documento' => $c->documento, 'documento_clave' => $c->documento_clave,
                        'correo' => $c->correo, 'correo_normalizado' => $c->correo_normalizado, 'correo_estado' => Participante::CORREO_VALIDO, 'es_control_qa' => true,
                        ...($modalidad !== null ? ['modalidad' => $modalidad] : []),
                    ]);
                    if ($hayCorreos) {
                        Correo::create(['participante_id' => $p->id, 'correo' => $c->correo, 'correo_normalizado' => $c->correo_normalizado, 'estado' => Correo::ESTADO_VALIDO,
                            'orden' => 1, 'es_principal' => true, 'origen' => Correo::ORIGEN_CREDENTIAL_FLOW]);
                    }
                    $r['creados']++;
                }
            }
        });

        if ($r['creados'] > 0) {
            Movimiento::registrar(tipo: 'registro', modulo: 'credential-flow', descripcion: 'Se incorporaron los contactos de control (QA) a una base', extra: ['lote_id' => $lote->id, 'cantidad' => $r['creados']]);
        }

        return $r;
    }

    /**
     * Modalidades PROPIAS de los participantes reales de la base, en el orden en que aparecen (sin repetir mayúsculas/espacios). Vacío si nadie trae la suya.
     *
     * @return list<string>
     */
    public static function modalidadesDe(Lote $lote): array
    {
        if (! Schema::hasColumn('cf_participantes', 'modalidad')) {
            return [];
        }
        $vistas = [];
        $valores = Participante::query()->where('lote_id', $lote->id)->where('es_control_qa', false)->whereNotNull('modalidad')->orderBy('id')->pluck('modalidad');
        foreach ($valores as $m) {
            $t = trim((string) $m);
            if ($t !== '') {
                $vistas[mb_strtolower($t, 'UTF-8')] ??= $t;
            }
        }

        return array_values($vistas);
    }

    private static function claveQa(string $documentoClave, ?string $modalidad): string
    {
        return $documentoClave.'|'.mb_strtolower(trim((string) $modalidad), 'UTF-8');
    }

    /**
     * Siembra `cf_contactos_qa` desde los participantes de una base de pruebas internas YA existente (reutiliza los contactos reales, no inventa ninguno).
     * Solo toma participantes con documento y un correo válido. Idempotente por documento.
     *
     * @return array{nuevos:int,existentes:int,sin_correo:int}
     */
    public function sembrarDesdeLote(Lote $lote, bool $ejecutar): array
    {
        $r = ['nuevos' => 0, 'existentes' => 0, 'sin_correo' => 0];
        foreach (Participante::query()->where('lote_id', $lote->id)->orderBy('id')->get() as $p) {
            $correo = Correo::normalizar((string) $p->correo);
            if ($correo === '' || Correo::estadoDe($correo) !== Correo::ESTADO_VALIDO) {
                $r['sin_correo']++;

                continue;
            }
            if (ContactoQa::query()->where('documento_clave', $p->documento_clave)->exists()) {
                $r['existentes']++;

                continue;
            }
            $r['nuevos']++;
            if ($ejecutar) {
                ContactoQa::create(['nombre_completo' => $p->nombre_completo, 'documento' => $p->documento, 'documento_clave' => $p->documento_clave, 'correo' => (string) $p->correo, 'correo_normalizado' => $correo, 'activo' => true]);
            }
        }

        return $r;
    }

    /** Plantilla de pruebas internas DESIGNADA por omisión (la última designada y válida), o null si no hay. Para una base concreta usa `plantillaPara`. */
    public function plantilla(): ?Plantilla
    {
        if (! Schema::hasColumn('cf_plantillas', 'es_plantilla_qa')) {
            return null;
        }

        return Plantilla::query()->where('es_plantilla_qa', true)->orderByDesc('id')->get()->first(fn (Plantilla $p) => self::tieneAdvertenciaVisible($p));
    }

    /**
     * Plantilla con la que se emiten los contactos QA de ESTA base: la variante de prueba del diseño de la base (si la tiene) o, si no, la designada por
     * omisión. Solo vale una plantilla DESIGNADA explícitamente y cuyo diseño lleve la advertencia visible; si no, null (no se emite nada).
     */
    public function plantillaPara(Lote $lote): ?Plantilla
    {
        if (! Schema::hasColumn('cf_plantillas', 'es_plantilla_qa')) {
            return null;
        }
        $varianteId = $lote->plantilla?->variante_qa_id;
        if ($varianteId !== null) {
            $variante = Plantilla::query()->whereKey($varianteId)->where('es_plantilla_qa', true)->first();

            return $variante !== null && self::tieneAdvertenciaVisible($variante) ? $variante : null;
        }

        return $this->plantilla();
    }

    /**
     * La advertencia «PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA» debe ir DENTRO DEL DISEÑO como texto visible (no basta con el nombre de la plantilla): así sale
     * impresa en cada PDF generado. Se compara sin mayúsculas ni tildes.
     */
    public static function tieneAdvertenciaVisible(Plantilla $plantilla): bool
    {
        $esperado = self::normalizar(self::ADVERTENCIA);
        foreach ((array) ($plantilla->diseno['elements'] ?? []) as $el) {
            if (($el['type'] ?? null) === 'text' && is_string($el['text'] ?? null) && str_contains(self::normalizar($el['text']), $esperado)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizar(string $t): string
    {
        return preg_replace('/[^A-Z0-9]+/', ' ', strtoupper(Str::ascii($t))) ?? '';
    }

    /** @throws InvalidArgumentException si el diseño no lleva la advertencia visible */
    public function designarPlantilla(Plantilla $plantilla, bool $esQa): void
    {
        if ($esQa && ! self::tieneAdvertenciaVisible($plantilla)) {
            throw new InvalidArgumentException('El diseño de la plantilla debe incluir, como texto visible, «'.self::ADVERTENCIA.'». Agrégalo en el editor y vuelve a designarla.');
        }
        if ($plantilla->es_plantilla_qa !== $esQa) {
            $plantilla->update(['es_plantilla_qa' => $esQa]);
            Movimiento::registrar(tipo: 'actualizacion', modulo: 'credential-flow', descripcion: $esQa ? 'Se designó una plantilla de pruebas internas (QA)' : 'Se quitó la designación de plantilla de pruebas internas (QA)', extra: ['plantilla_id' => $plantilla->id]);
        }
    }

    /**
     * Asocia (o quita, con null) la variante de pruebas del diseño de una plantilla institucional. La variante debe estar designada y llevar la advertencia visible.
     *
     * @throws InvalidArgumentException
     */
    public function asignarVariante(Plantilla $plantilla, ?Plantilla $variante): void
    {
        if ($variante !== null) {
            if ($variante->id === $plantilla->id || ! $variante->es_plantilla_qa || ! self::tieneAdvertenciaVisible($variante)) {
                throw new InvalidArgumentException('La variante debe ser otra plantilla designada para pruebas internas y con la advertencia visible en su diseño.');
            }
        }
        if ($plantilla->variante_qa_id !== $variante?->id) {
            $plantilla->update(['variante_qa_id' => $variante?->id]);
            Movimiento::registrar(tipo: 'actualizacion', modulo: 'credential-flow', descripcion: 'Se cambió la variante de pruebas internas de una plantilla', extra: ['plantilla_id' => $plantilla->id, 'variante_id' => $variante?->id]);
        }
    }
}
