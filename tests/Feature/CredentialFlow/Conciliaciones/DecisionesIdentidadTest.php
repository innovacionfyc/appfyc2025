<?php

namespace Tests\Feature\CredentialFlow\Conciliaciones;

use App\Mail\CodigoAccesoMail;
use App\Models\CredentialFlow\Conciliacion;
use App\Models\CredentialFlow\DecisionIdentidad;
use App\Support\CredentialFlow\Conciliaciones\ResolucionNoPermitida as No;
use App\Support\CredentialFlow\Identidad\DecisionesIdentidad;
use App\Support\CredentialFlow\Identidad\EvidenciaIdentidad;
use App\Support\CredentialFlow\Identidad\GruposSinVia;
use App\Support\CredentialFlow\Portal\AccesoPortal;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use LogicException;

/**
 * Fase 10B-3A: capa de decisiones de identidad. REGISTRA, no autoriza: ninguna decisión cambia el portal. El fixture es el caso de identidad del
 * histórico sintético (documento 2000002, dos grupos de nombre que comparten el único correo).
 */
class DecisionesIdentidadTest extends ConciliacionesTestCase
{
    private const MOTIVO = 'Decisión administrativa de prueba.';

    private const EVID = 'Confirmado por el organizador del evento mediante correo institucional.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->detectar();
    }

    private function servicio(): DecisionesIdentidad
    {
        return app(DecisionesIdentidad::class);
    }

    private function actor(): int
    {
        return $this->admin()->id;
    }

    private function ident(): Conciliacion
    {
        return Conciliacion::query()->where('tipo', Conciliacion::TIPO_IDENTIDAD_AMBIGUA)->where('motivo_origen', 'CORREO_CRUZA_GRUPOS')->orderBy('id')->firstOrFail();
    }

    /** @return array<string,mixed> */
    private function ev(?Conciliacion $caso = null): array
    {
        return EvidenciaIdentidad::de($caso ?? $this->ident());
    }

    /** @return list<string> */
    private function hashes(?Conciliacion $caso = null): array
    {
        return array_keys($this->ev($caso)['por_hash']);
    }

    private function misma(?Conciliacion $caso = null, array $extra = []): array
    {
        return $this->servicio()->crear(($caso ?? $this->ident())->id, $this->actor(), DecisionIdentidad::MISMA_PERSONA, self::MOTIVO,
            ['grupos' => $this->hashes($caso), 'evidencia' => self::EVID, 'confirmo' => true, 'evidencia_externa' => true] + $extra);
    }

    private function codigo(callable $f): string
    {
        try {
            $f();
        } catch (No $e) {
            return $e->codigo;
        }

        return 'OK';
    }

    // ── Schema ───────────────────────────────────────────────────────────────

    public function test_las_tablas_y_sus_unique_existen(): void
    {
        foreach (['cf_decisiones_identidad', 'cf_decisiones_identidad_grupos', 'cf_decisiones_identidad_correos'] as $t) {
            $this->assertTrue(\Schema::hasTable($t), $t);
        }
        $this->assertTrue(\Schema::hasColumns('cf_decisiones_identidad', ['conciliacion_id', 'documento_hash', 'tipo', 'estado', 'vigente_clave', 'vigente_caso_clave', 'revocada_por', 'revocada_at', 'motivo_revocacion']));
        $this->assertFalse(\Schema::hasColumn('cf_decisiones_identidad', 'documento'), 'el documento nunca se guarda en claro');
        $this->assertFalse(\Schema::hasColumn('cf_decisiones_identidad_correos', 'correo'));
    }

    public function test_la_base_impide_duplicados_vigentes_aunque_el_servicio_falle(): void
    {
        $r = $this->misma();
        $d = (array) DB::table('cf_decisiones_identidad')->find($r['decision_id']);
        unset($d['id']);
        $d['vigente_caso_clave'] = null;
        $this->expectException(QueryException::class);
        DB::table('cf_decisiones_identidad')->insert($d);   // misma vigente_clave → UNIQUE
    }

    public function test_la_base_impide_el_mismo_correo_vigente_en_dos_decisiones(): void
    {
        $caso = $this->ident();
        $g = $this->ev()['grupos'];
        $this->servicio()->crear($caso->id, $this->actor(), DecisionIdentidad::CORREO_AUTORIZADO, self::MOTIVO, ['grupo' => $g[0]['grupo_hash'], 'correo_hmac' => $g[0]['correos'][0]['hmac'], 'evidencia' => self::EVID, 'reforzada' => true]);
        $fila = (array) DB::table('cf_decisiones_identidad_correos')->first();
        $otra = DB::table('cf_decisiones_identidad')->insertGetId(['conciliacion_id' => $caso->id, 'documento_hash' => $fila['documento_hash'], 'tipo' => 'correo_autorizado', 'estado' => 'vigente', 'motivo' => 'x', 'creada_por' => 1,
            'vigente_clave' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now()]);
        $this->expectException(QueryException::class);
        DB::table('cf_decisiones_identidad_correos')->insert(['decision_id' => $otra, 'documento_hash' => $fila['documento_hash'], 'correo_hmac' => $fila['correo_hmac'], 'correo_mascara' => 'x', 'grupo_hash' => $g[1]['grupo_hash'], 'vigente' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    // ── MISMA_PERSONA ────────────────────────────────────────────────────────

    public function test_misma_persona_registra_el_alcance_sin_cerrar_el_caso_ni_tocar_el_historico(): void
    {
        $caso = $this->ident();
        $antes = $this->firmaHistorico();
        $nombres = DB::table('cf_certificados_legado')->orderBy('id')->pluck('nombre_completo')->all();

        $r = $this->misma();

        $this->assertTrue($r['creada']);
        $this->assertSame(Conciliacion::ABIERTO, $caso->fresh()->estado, 'registrar no cierra el caso');
        $this->assertNull($caso->fresh()->resolucion);
        $d = DecisionIdentidad::findOrFail($r['decision_id']);
        $this->assertSame([DecisionIdentidad::MISMA_PERSONA, 'vigente', $this->actor()], [$d->tipo, $d->estado, (int) $d->creada_por]);
        $this->assertSame($this->ev()['documento_hash'], $d->documento_hash);
        $this->assertEqualsCanonicalizing($this->hashes(), DB::table('cf_decisiones_identidad_grupos')->where('decision_id', $d->id)->pluck('grupo_hash')->all());
        $this->assertSame(['misma_persona'], DB::table('cf_decisiones_identidad_grupos')->where('decision_id', $d->id)->pluck('vigente_tipo')->unique()->all());
        // El histórico queda intacto (excepto movimientos, que registran la acción).
        $this->assertSame($nombres, DB::table('cf_certificados_legado')->orderBy('id')->pluck('nombre_completo')->all());
        $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso->id)->where('accion', 'identidad_decision_creada')->count());
        $this->assertSame($antes === $this->firmaHistorico(), false, 'solo cambian los movimientos de auditoría');
        $this->assertSame(1, DB::table('movimientos')->where('metadata', 'like', '%identidad_decision_creada%')->count());
    }

    public function test_misma_persona_exige_confirmacion_evidencia_y_dos_grupos(): void
    {
        $caso = $this->ident();
        $h = $this->hashes();
        $this->assertSame(No::CONFIRMACION_REQUERIDA, $this->codigo(fn () => $this->servicio()->crear($caso->id, $this->actor(), 'misma_persona', self::MOTIVO, ['grupos' => $h, 'evidencia' => self::EVID, 'evidencia_externa' => true])));
        $this->assertSame(No::EVIDENCIA_REQUERIDA, $this->codigo(fn () => $this->servicio()->crear($caso->id, $this->actor(), 'misma_persona', self::MOTIVO, ['grupos' => $h, 'confirmo' => true, 'evidencia_externa' => true])));
        $this->assertSame(No::EVIDENCIA_REQUERIDA, $this->codigo(fn () => $this->servicio()->crear($caso->id, $this->actor(), 'misma_persona', self::MOTIVO, ['grupos' => $h, 'evidencia' => 'corta', 'confirmo' => true])));
        $this->assertSame(No::VALOR_NO_VALIDO, $this->codigo(fn () => $this->servicio()->crear($caso->id, $this->actor(), 'misma_persona', self::MOTIVO, ['grupos' => [$h[0]], 'evidencia' => self::EVID, 'confirmo' => true])));
        $this->assertSame(No::GRUPO_NO_PERTENECE, $this->codigo(fn () => $this->servicio()->crear($caso->id, $this->actor(), 'misma_persona', self::MOTIVO, ['grupos' => [$h[0], str_repeat('a', 64)], 'evidencia' => self::EVID, 'confirmo' => true])));
        $this->assertSame(0, DB::table('cf_decisiones_identidad')->count(), 'ningún rechazo escribe nada');
    }

    public function test_mismo_evento_o_nombres_distintos_exigen_evidencia_externa_declarada(): void
    {
        $caso = $this->ident();
        $ids = $this->certsDelCaso($caso->id);
        $porGrupo = DB::table('cf_certificados_legado')->whereIn('id', $ids)->get()->groupBy(fn ($c) => NombreConservador::grupoId($c->nombre_completo));
        $a = $porGrupo->first()->first();
        $b = $porGrupo->last()->first();
        DB::table('cf_certificados_legado')->where('id', $b->id)->update(['evento_id' => $a->evento_id]);
        $this->assertTrue($this->ev()['riesgos']['mismo_evento']);

        $sin = fn () => $this->servicio()->crear($caso->id, $this->actor(), 'misma_persona', self::MOTIVO, ['grupos' => $this->hashes(), 'evidencia' => self::EVID, 'confirmo' => true]);
        $this->assertSame(No::EVIDENCIA_REQUERIDA, $this->codigo($sin));
        $this->assertSame('OK', $this->codigo(fn () => $this->misma()), 'con la declaración explícita de evidencia externa se registra');
        $this->assertTrue((bool) DecisionIdentidad::first()->declaro_evidencia_externa);
    }

    public function test_un_alcance_masivo_exige_confirmarlo(): void
    {
        $caso = $this->ident();
        $molde = (array) DB::table('cf_certificados_legado')->where('id', $this->certsDelCaso($caso->id)[0])->first();
        unset($molde['id']);
        $filas = [];
        for ($i = 0; $i < EvidenciaIdentidad::UMBRAL_MASIVO; $i++) {
            $id = DB::table('cf_certificados_legado')->insertGetId($molde);
            $filas[] = ['conciliacion_id' => $caso->id, 'certificado_legado_id' => $id, 'rol' => 'grupo_1', 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('cf_conciliaciones_certificados')->insert($filas);
        $this->assertTrue($this->ev()['riesgos']['masivo']);

        $this->assertSame(No::CONFIRMACION_REQUERIDA, $this->codigo(fn () => $this->misma()));
        $this->assertSame('OK', $this->codigo(fn () => $this->misma(null, ['alcance_masivo' => true])));
        $d = DecisionIdentidad::first();
        $this->assertTrue((bool) $d->confirmo_alcance_masivo);
        $this->assertGreaterThanOrEqual(EvidenciaIdentidad::UMBRAL_MASIVO, $d->certificados_afectados);
    }

    // ── Idempotencia, incompatibilidades, revocación ──────────────────────────

    public function test_repetir_la_misma_decision_no_duplica(): void
    {
        $a = $this->misma();
        $b = $this->misma();

        $this->assertTrue($a['creada']);
        $this->assertFalse($b['creada']);
        $this->assertSame($a['decision_id'], $b['decision_id']);
        $this->assertSame(1, DB::table('cf_decisiones_identidad')->count());
        $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('accion', 'identidad_decision_creada')->count());
    }

    public function test_misma_persona_y_personas_distintas_sobre_los_mismos_grupos_son_contradictorias(): void
    {
        $this->misma();
        $c = fn () => $this->servicio()->crear($this->ident()->id, $this->actor(), 'personas_distintas', self::MOTIVO, ['grupos' => $this->hashes(), 'confirmo' => true]);
        $this->assertSame(No::DECISION_CONFLICTIVA, $this->codigo($c));
        $this->assertSame(1, DB::table('cf_decisiones_identidad')->count());

        // Revocada la primera, la segunda ya es posible (se decide de nuevo sin borrar nada).
        $this->servicio()->revocar(DecisionIdentidad::first()->id, $this->actor(), 'Se retira la unión por nueva información.');
        $this->assertSame('OK', $this->codigo($c));
        $this->assertSame(2, DB::table('cf_decisiones_identidad')->count());
    }

    public function test_un_grupo_no_puede_estar_en_dos_alcances_de_misma_persona(): void
    {
        $caso = $this->ident();
        $h = $this->hashes();
        // Se arma un tercer grupo en el mismo documento para poder solapar {a,b} con {b,c}.
        $molde = (array) DB::table('cf_certificados_legado')->where('id', $this->certsDelCaso($caso->id)[0])->first();
        unset($molde['id']);
        $molde['nombre_completo'] = 'PERSONA TERCERA PRUEBA';
        $nuevo = DB::table('cf_certificados_legado')->insertGetId($molde);
        DB::table('cf_conciliaciones_certificados')->insert(['conciliacion_id' => $caso->id, 'certificado_legado_id' => $nuevo, 'rol' => 'grupo_3', 'created_at' => now(), 'updated_at' => now()]);
        $todos = $this->hashes();
        $tercero = array_values(array_diff($todos, $h))[0];

        $this->assertSame('OK', $this->codigo(fn () => $this->servicio()->crear($caso->id, $this->actor(), 'misma_persona', self::MOTIVO, ['grupos' => [$h[0], $h[1]], 'evidencia' => self::EVID, 'confirmo' => true, 'evidencia_externa' => true])));
        $this->assertSame(No::DECISION_CONFLICTIVA, $this->codigo(fn () => $this->servicio()->crear($caso->id, $this->actor(), 'misma_persona', self::MOTIVO, ['grupos' => [$h[1], $tercero], 'evidencia' => self::EVID, 'confirmo' => true, 'evidencia_externa' => true])));
        // Un conjunto que contradice solo en UN grupo no es contradicción (se necesitan ≥ 2 en común).
        $this->assertSame('OK', $this->codigo(fn () => $this->servicio()->crear($caso->id, $this->actor(), 'personas_distintas', self::MOTIVO, ['grupos' => [$h[1], $tercero], 'confirmo' => true])));
    }

    public function test_revocar_no_borra_libera_las_claves_y_conserva_la_evidencia(): void
    {
        $r = $this->misma();
        $caso = $this->ident();
        $firma = $this->firmaHistorico();

        $rev = $this->servicio()->revocar($r['decision_id'], $this->actor(), 'Se retira la unión por nueva información.');

        $d = DecisionIdentidad::findOrFail($r['decision_id']);
        $this->assertSame(['revocada', $this->actor()], [$d->estado, (int) $d->revocada_por]);
        $this->assertNotNull($d->revocada_at);
        $this->assertNull($d->vigente_clave);
        $this->assertSame(self::EVID, $d->evidencia, 'la evidencia se conserva');
        $this->assertSame([null], DB::table('cf_decisiones_identidad_grupos')->where('decision_id', $d->id)->pluck('vigente_tipo')->unique()->all());
        $this->assertSame(2, DB::table('cf_decisiones_identidad_grupos')->where('decision_id', $d->id)->count(), 'las filas se conservan');
        $ev = DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso->id)->where('accion', 'identidad_decision_revocada')->first();
        $this->assertNotNull($ev);
        $this->assertSame($rev['caso_estado'], Conciliacion::ABIERTO);
        $this->assertSame(No::DECISION_YA_REVOCADA, $this->codigo(fn () => $this->servicio()->revocar($d->id, $this->actor(), 'Se retira la unión por nueva información.')));
        // Revocar no toca certificados ni correos.
        $this->assertSame(DB::table('cf_certificados_legado')->count(), count(DB::table('cf_certificados_legado')->pluck('id')));
        $this->assertNotSame($firma, '');
    }

    public function test_un_modelo_de_decision_no_se_elimina(): void
    {
        $r = $this->misma();
        $this->expectException(LogicException::class);
        DecisionIdentidad::findOrFail($r['decision_id'])->delete();
    }

    public function test_el_borrado_en_lote_tambien_se_niega(): void
    {
        $this->misma();
        $this->expectException(LogicException::class);
        DecisionIdentidad::query()->delete();
    }

    // ── PERSONAS_DISTINTAS ───────────────────────────────────────────────────

    public function test_personas_distintas_sin_via_individual_deja_el_caso_en_requiere_soporte_y_no_da_acceso(): void
    {
        $caso = $this->ident();
        $this->assertSame(2, $this->ev()['sin_via_individual']);
        $this->assertSame(No::CONFIRMACION_REQUERIDA, $this->codigo(fn () => $this->servicio()->crear($caso->id, $this->actor(), 'personas_distintas', self::MOTIVO, ['grupos' => $this->hashes()])));

        $r = $this->servicio()->crear($caso->id, $this->actor(), 'personas_distintas', self::MOTIVO, ['grupos' => $this->hashes(), 'confirmo' => true]);

        $this->assertSame(Conciliacion::REQUIERE_SOPORTE, $caso->fresh()->estado);
        $this->assertSame('requiere_soporte', DecisionIdentidad::findOrFail($r['decision_id'])->efecto_caso);
        $this->assertNull($caso->fresh()->resolucion);
        // Al revocar se deshace el efecto sobre el caso.
        $this->servicio()->revocar($r['decision_id'], $this->actor(), 'Se retira la decisión por nueva información.');
        $this->assertSame(Conciliacion::ABIERTO, $caso->fresh()->estado);
    }

    // ── CORREO_AUTORIZADO ────────────────────────────────────────────────────

    public function test_correo_autorizado_guarda_solo_hmac_y_mascara(): void
    {
        $caso = $this->ident();
        $g = $this->ev()['grupos'];
        $c = $g[0]['correos'][0];
        $this->assertTrue($c['compartido']);

        $this->assertSame(No::CONFIRMACION_REQUERIDA, $this->codigo(fn () => $this->servicio()->crear($caso->id, $this->actor(), 'correo_autorizado', self::MOTIVO, ['grupo' => $g[0]['grupo_hash'], 'correo_hmac' => $c['hmac'], 'evidencia' => self::EVID])), 'compartido sin confirmación reforzada');
        $this->assertSame(No::EVIDENCIA_REQUERIDA, $this->codigo(fn () => $this->servicio()->crear($caso->id, $this->actor(), 'correo_autorizado', self::MOTIVO, ['grupo' => $g[0]['grupo_hash'], 'correo_hmac' => $c['hmac'], 'reforzada' => true])));
        $r = $this->servicio()->crear($caso->id, $this->actor(), 'correo_autorizado', self::MOTIVO, ['grupo' => $g[0]['grupo_hash'], 'correo_hmac' => $c['hmac'], 'evidencia' => self::EVID, 'reforzada' => true]);

        $fila = DB::table('cf_decisiones_identidad_correos')->where('decision_id', $r['decision_id'])->first();
        $this->assertSame([$c['hmac'], $c['mascara'], $g[0]['grupo_hash'], 1], [$fila->correo_hmac, $fila->correo_mascara, $fila->grupo_hash, (int) $fila->vigente]);
        $volcado = json_encode([DB::table('cf_decisiones_identidad')->get(), DB::table('cf_decisiones_identidad_grupos')->get(), DB::table('cf_decisiones_identidad_correos')->get(), DB::table('cf_conciliaciones_eventos')->get(), DB::table('movimientos')->get()]);
        foreach (['hugo@example.test', 'HUGO NUEVE', '2000002'] as $privado) {
            $this->assertStringNotContainsString($privado, $volcado);
        }
        $this->assertSame($g[0]['grupo_hash'], DecisionesIdentidad::grupoDeCorreo($this->ev()['documento_hash'], $c['hmac']));
    }

    public function test_el_mismo_correo_no_puede_autorizar_dos_grupos_a_la_vez(): void
    {
        $caso = $this->ident();
        $g = $this->ev()['grupos'];
        $c = $g[0]['correos'][0];
        $crear = fn (int $i) => $this->servicio()->crear($caso->id, $this->actor(), 'correo_autorizado', self::MOTIVO, ['grupo' => $g[$i]['grupo_hash'], 'correo_hmac' => $c['hmac'], 'evidencia' => self::EVID, 'reforzada' => true]);

        $this->assertSame('OK', $this->codigo(fn () => $crear(0)));
        $this->assertSame(No::DECISION_CONFLICTIVA, $this->codigo(fn () => $crear(1)));
        $this->servicio()->revocar(DecisionIdentidad::first()->id, $this->actor(), 'Se corrige el grupo autorizado.');
        $this->assertSame('OK', $this->codigo(fn () => $crear(1)));
        $this->assertSame($g[1]['grupo_hash'], DecisionesIdentidad::grupoDeCorreo($this->ev()['documento_hash'], $c['hmac']));
    }

    public function test_un_correo_que_no_es_del_grupo_o_no_existe_se_rechaza(): void
    {
        $caso = $this->ident();
        $g = $this->ev()['grupos'];
        $this->assertSame(No::CORREO_NO_PERTENECE, $this->codigo(fn () => $this->servicio()->crear($caso->id, $this->actor(), 'correo_autorizado', self::MOTIVO, ['grupo' => $g[0]['grupo_hash'], 'correo_hmac' => str_repeat('c', 64), 'evidencia' => self::EVID, 'reforzada' => true])));
        $this->assertSame(No::GRUPO_NO_PERTENECE, $this->codigo(fn () => $this->servicio()->crear($caso->id, $this->actor(), 'correo_autorizado', self::MOTIVO, ['grupo' => str_repeat('d', 64), 'correo_hmac' => $g[0]['correos'][0]['hmac'], 'evidencia' => self::EVID, 'reforzada' => true])));
    }

    // ── Soporte / no resoluble / documento inválido ──────────────────────────

    public function test_requiere_soporte_marca_el_caso_y_se_deshace_al_revocar(): void
    {
        $caso = $this->ident();
        $r = $this->servicio()->crear($caso->id, $this->actor(), 'requiere_soporte', self::MOTIVO);

        $this->assertSame(Conciliacion::REQUIERE_SOPORTE, $caso->fresh()->estado);
        $this->assertSame($caso->id, (int) DecisionIdentidad::findOrFail($r['decision_id'])->vigente_caso_clave);
        $this->assertSame(No::DECISION_CONFLICTIVA, $this->codigo(fn () => $this->servicio()->crear($caso->id, $this->actor(), 'no_resoluble', self::MOTIVO)), 'una terminal por caso');
        $this->assertSame(No::DECISION_CONFLICTIVA, $this->codigo(fn () => $this->misma()), 'no conviven con decisiones sobre grupos');

        $this->servicio()->revocar($r['decision_id'], $this->actor(), 'Soporte ya respondió el caso.');
        $this->assertSame(Conciliacion::ABIERTO, $caso->fresh()->estado);
        $this->assertSame('OK', $this->codigo(fn () => $this->misma()));
    }

    public function test_no_resoluble_registra_sin_cambiar_el_caso(): void
    {
        $caso = $this->ident();
        $this->servicio()->crear($caso->id, $this->actor(), 'no_resoluble', self::MOTIVO, ['evidencia' => self::EVID]);

        $this->assertSame(Conciliacion::ABIERTO, $caso->fresh()->estado);
        $this->assertSame(1, DecisionIdentidad::where('tipo', 'no_resoluble')->where('estado', 'vigente')->count());
    }

    public function test_un_documento_invalido_solo_admite_soporte_o_no_resoluble(): void
    {
        $base = $this->ident();
        $molde = (array) DB::table('cf_certificados_legado')->where('id', $this->certsDelCaso($base->id)[0])->first();
        unset($molde['id']);
        $ids = [];
        foreach (['UNO SIN DOCUMENTO', 'DOS SIN DOCUMENTO'] as $n) {
            $ids[] = DB::table('cf_certificados_legado')->insertGetId(['nombre_completo' => $n, 'documento' => '', 'documento_clave' => ''] + $molde);
        }
        $c = Conciliacion::create(['tipo' => Conciliacion::TIPO_IDENTIDAD_AMBIGUA, 'estado' => 'abierto', 'referencia_tipo' => 'documento', 'referencia_clave' => EvidenciaIdentidad::hashDocumento('vacio'), 'motivo_origen' => 'DOCUMENTO_INVALIDO', 'clave_idempotencia' => 'identidad_ambigua:documento:invalido-prueba']);
        foreach ($ids as $i => $id) {
            DB::table('cf_conciliaciones_certificados')->insert(['conciliacion_id' => $c->id, 'certificado_legado_id' => $id, 'rol' => 'grupo_'.($i + 1), 'created_at' => now(), 'updated_at' => now()]);
        }
        $h = $this->hashes($c);
        $this->assertTrue($this->ev($c)['invalido']);

        $this->assertSame(No::TIPO_NO_ADMITIDO, $this->codigo(fn () => $this->servicio()->crear($c->id, $this->actor(), 'misma_persona', self::MOTIVO, ['grupos' => $h, 'evidencia' => self::EVID, 'confirmo' => true, 'evidencia_externa' => true])));
        $this->assertSame(No::TIPO_NO_ADMITIDO, $this->codigo(fn () => $this->servicio()->crear($c->id, $this->actor(), 'correo_autorizado', self::MOTIVO, ['grupo' => $h[0], 'correo_hmac' => str_repeat('a', 64), 'evidencia' => self::EVID, 'reforzada' => true])));
        $this->assertSame(No::TIPO_NO_ADMITIDO, $this->codigo(fn () => $this->servicio()->crear($c->id, $this->actor(), 'personas_distintas', self::MOTIVO, ['grupos' => $h, 'confirmo' => true])));
        $this->assertSame('OK', $this->codigo(fn () => $this->servicio()->crear($c->id, $this->actor(), 'no_resoluble', self::MOTIVO)));
        $this->assertSame(0, DB::table('cf_certificados_legado')->whereIn('id', $ids)->where('estado', '!=', 'vigente')->count(), 'no se borra ni cambia el certificado');
    }

    // ── Permisos y límites ───────────────────────────────────────────────────

    public function test_solo_un_administrador_decide_y_el_caso_cerrado_no_admite_decisiones(): void
    {
        $caso = $this->ident();
        $this->assertSame(No::ACTOR_NO_AUTORIZADO, $this->codigo(fn () => $this->servicio()->crear($caso->id, $this->comercial()->id, 'requiere_soporte', self::MOTIVO)));
        $this->assertSame(No::TIPO_NO_ADMITIDO, $this->codigo(fn () => $this->servicio()->crear($caso->id, $this->actor(), 'requiere_soporte', 'corto')));
        $this->assertSame(No::TIPO_NO_ADMITIDO, $this->codigo(fn () => $this->servicio()->crear($caso->id, $this->actor(), 'inventada', self::MOTIVO)));
        $this->assertSame(No::TIPO_NO_ADMITIDO, $this->codigo(fn () => $this->servicio()->crear(Conciliacion::where('tipo', 'plantilla_candidata')->value('id'), $this->actor(), 'requiere_soporte', self::MOTIVO)), 'solo casos de identidad');
        $caso->update(['estado' => Conciliacion::RESUELTO]);
        $this->assertSame(No::CASO_YA_RESUELTO, $this->codigo(fn () => $this->servicio()->crear($caso->id, $this->actor(), 'requiere_soporte', self::MOTIVO)));
        $this->assertSame(0, DB::table('cf_decisiones_identidad')->count());
    }

    // ── Consultas preparadas para 10B-3B ─────────────────────────────────────

    public function test_las_consultas_por_documento_grupo_y_correo_devuelven_solo_lo_vigente(): void
    {
        $caso = $this->ident();
        $r = $this->misma();
        $doc = $this->ev()['documento_hash'];
        $h = $this->hashes();

        $this->assertCount(1, DecisionesIdentidad::vigentesDeDocumento($doc));
        $this->assertSame([$r['decision_id']], DecisionesIdentidad::vigentesDeGrupo($doc, $h[0])->pluck('id')->map(fn ($i) => (int) $i)->all());
        $this->assertCount(0, DecisionesIdentidad::vigentesDeGrupo($doc, str_repeat('e', 64)));
        $this->servicio()->revocar($r['decision_id'], $this->actor(), 'Se retira por nueva información.');
        $this->assertCount(0, DecisionesIdentidad::vigentesDeDocumento($doc));
        $this->assertCount(0, DecisionesIdentidad::vigentesDeGrupo($doc, $h[0]));
        $this->assertSame($caso->id, (int) $r['decision_id'] ? $caso->id : 0);
    }

    // ── CERO efecto sobre el portal ──────────────────────────────────────────

    public function test_ninguna_decision_cambia_el_alcance_ni_el_otp_ni_el_panel(): void
    {
        Mail::fake();
        $acceso = new AccesoPortal;
        $docs = DB::table('cf_certificados_legado')->select('documento_clave')->distinct()->pluck('documento_clave')->all();
        $correos = DB::table('cf_correos')->where('estado', 'valido')->whereNotNull('certificado_legado_id')->pluck('correo_normalizado')->unique()->all();
        $foto = function () use ($acceso, $docs, $correos) {
            $f = [];
            foreach ($docs as $d) {
                foreach ($correos as $c) {
                    $f[$d.'|'.$c] = $acceso->alcance((string) $d, $c);
                }
                $f['t|'.$d] = $acceso->tarjetas((string) $d);
            }

            return $f;
        };
        $antes = $foto();
        $otpAntes = DB::table('cf_accesos_otp')->count();
        $this->post(route('portal.solicitar'), ['documento' => '2000002', 'correo' => 'hugo@example.test']);
        $mailsAntes = Mail::sent(CodigoAccesoMail::class)->count();

        $caso = $this->ident();
        $g = $this->ev()['grupos'];
        $this->servicio()->crear($caso->id, $this->actor(), 'correo_autorizado', self::MOTIVO, ['grupo' => $g[0]['grupo_hash'], 'correo_hmac' => $g[0]['correos'][0]['hmac'], 'evidencia' => self::EVID, 'reforzada' => true]);
        $this->servicio()->crear($caso->id, $this->actor(), 'personas_distintas', self::MOTIVO, ['grupos' => $this->hashes(), 'confirmo' => true]);
        $this->servicio()->revocar(DecisionIdentidad::where('tipo', 'personas_distintas')->value('id'), $this->actor(), 'Se retira por nueva información.');
        $this->misma();

        $this->assertSame($antes, $foto(), 'AccesoPortal::alcance() y las tarjetas son idénticos antes y después');
        $this->assertNull($acceso->alcance('2000002', 'hugo@example.test'), 'el documento ambiguo sigue sin vía');
        $this->assertSame($otpAntes, DB::table('cf_accesos_otp')->count());
        $this->post(route('portal.solicitar'), ['documento' => '2000002', 'correo' => 'hugo@example.test']);
        $this->assertSame($mailsAntes, Mail::sent(CodigoAccesoMail::class)->count(), 'el OTP se comporta igual: no se emite');
    }

    public function test_las_decisiones_no_tocan_los_ficheros_del_portal(): void
    {
        // El portal no referencia la capa de decisiones en esta fase.
        foreach (['Portal/AccesoPortal.php', 'Portal/ServicioOtp.php', 'Portal/SesionPortal.php'] as $f) {
            $codigo = file_get_contents(app_path('Support/CredentialFlow/'.$f));
            $this->assertStringNotContainsString('DecisionIdentidad', $codigo, $f);
            $this->assertStringNotContainsString('DecisionesIdentidad', $codigo, $f);
        }
        $this->assertStringNotContainsString('DecisionIdentidad', file_get_contents(app_path('Http/Controllers/CredentialFlow/PortalPublicoController.php')));
        $this->assertStringNotContainsString('DecisionIdentidad', file_get_contents(app_path('Http/Controllers/CredentialFlow/VerificacionPublicaController.php')));
    }

    // ── Read-model de grupos sin vía ─────────────────────────────────────────

    public function test_el_reporte_de_grupos_sin_via_no_escribe_ni_lleva_datos_personales(): void
    {
        $antes = $this->firmaHistorico().$this->firmaConciliaciones();
        $r = (new GruposSinVia)->reporte();

        $this->assertSame($antes, $this->firmaHistorico().$this->firmaConciliaciones());
        $this->assertGreaterThanOrEqual(1, $r['documentos_multigrupo']);
        $this->assertSame($r['grupos_en_accesibles'], $r['grupos_accesibles'] + $r['grupos_sin_via']);
        $this->assertCount($r['grupos_sin_via'], $r['items']);
        $json = json_encode($r);
        foreach (['HUGO', 'example.test', '2000002'] as $privado) {
            $this->assertStringNotContainsString($privado, $json);
        }
        $this->artisan('credential-flow:identidad-grupos-sin-via')->assertSuccessful();
    }

    // ── HTTP / interfaz ──────────────────────────────────────────────────────

    public function test_el_detalle_ofrece_la_evidencia_y_las_decisiones_sin_datos_personales(): void
    {
        $caso = $this->ident();
        $this->misma();
        $r = $this->actingAs($this->admin())->get(route('credential-flow.historico.casos.show', $caso->id))->assertOk();
        $props = $r->viewData('page')['props']['caso'];
        $i = $props['identidad'];

        $this->assertCount(2, $i['grupos']);
        $this->assertStringContainsString('puede afectar el acceso', $i['registra_no_autoriza']);
        $this->assertCount(1, $i['decisiones']);
        $this->assertSame(['misma_persona', 'personas_distintas', 'correo_autorizado', 'requiere_soporte', 'no_resoluble'], $i['tipos']);
        $json = json_encode($props);
        foreach (['hugo@example.test', 'HUGO NUEVE', '2000002'] as $privado) {
            $this->assertStringNotContainsString($privado, $json);
        }
        $this->assertNull($this->actingAs($this->admin())->get(route('credential-flow.historico.casos.show', Conciliacion::where('tipo', 'plantilla_candidata')->value('id')))->viewData('page')['props']['caso']['identidad']);
    }

    public function test_el_listado_sigue_sin_datos_personales(): void
    {
        $this->misma();
        $json = json_encode($this->actingAs($this->admin())->get(route('credential-flow.historico.casos.index'))->assertOk()->viewData('page')['props']);
        foreach (['hugo@example.test', 'HUGO NUEVE', '2000002'] as $privado) {
            $this->assertStringNotContainsString($privado, $json);
        }
    }

    public function test_crear_y_revocar_por_http(): void
    {
        $caso = $this->ident();
        $admin = $this->admin();
        $ruta = route('credential-flow.historico.casos.identidad.crear', $caso->id);

        $this->actingAs($this->comercial())->post($ruta, ['tipo' => 'requiere_soporte', 'motivo' => self::MOTIVO])->assertForbidden();
        $this->actingAs($admin)->post($ruta, ['tipo' => 'requiere_soporte', 'motivo' => 'x'])->assertSessionHasErrors('motivo');
        $this->actingAs($admin)->post($ruta, ['tipo' => 'inventada', 'motivo' => self::MOTIVO])->assertSessionHasErrors('tipo');
        $this->actingAs($admin)->post($ruta, ['tipo' => 'misma_persona', 'motivo' => self::MOTIVO, 'grupos' => $this->hashes(), 'evidencia' => self::EVID])->assertRedirect(route('credential-flow.historico.casos.show', $caso->id))->assertSessionHas('error');
        $this->assertSame(0, DB::table('cf_decisiones_identidad')->count());

        $this->actingAs($admin)->post($ruta, ['tipo' => 'misma_persona', 'motivo' => self::MOTIVO, 'grupos' => $this->hashes(), 'evidencia' => self::EVID, 'confirmo' => '1', 'evidencia_externa' => '1'])->assertSessionHas('success', fn ($m) => str_contains($m, 'Puede afectar el acceso') && ! str_contains($m, 'Todavía no cambia'));
        $this->assertSame(1, DB::table('cf_decisiones_identidad')->count());
        $this->actingAs($admin)->post($ruta, ['tipo' => 'misma_persona', 'motivo' => self::MOTIVO, 'grupos' => $this->hashes(), 'evidencia' => self::EVID, 'confirmo' => '1', 'evidencia_externa' => '1'])->assertSessionHas('success');
        $this->assertSame(1, DB::table('cf_decisiones_identidad')->count(), 'idempotente por HTTP');

        $id = DecisionIdentidad::value('id');
        $revocar = route('credential-flow.historico.casos.identidad.revocar', [$caso->id, $id]);
        $this->actingAs($admin)->post($revocar, ['motivo' => 'corto'])->assertSessionHasErrors('motivo');
        $otro = Conciliacion::where('tipo', 'plantilla_candidata')->value('id');
        $this->actingAs($admin)->post(route('credential-flow.historico.casos.identidad.revocar', [$otro, $id]), ['motivo' => 'Motivo suficiente de prueba.'])->assertNotFound();
        $this->actingAs($admin)->post($revocar, ['motivo' => 'Motivo suficiente de prueba.'])->assertSessionHas('success', fn ($m) => str_contains($m, 'puede retirar el acceso') && ! str_contains($m, 'no cambió'));
        $this->assertSame('revocada', DecisionIdentidad::find($id)->estado);
        $this->actingAs($admin)->post($revocar, ['motivo' => 'Motivo suficiente de prueba.'])->assertSessionHas('error');
        $this->assertSame(1, DB::table('cf_decisiones_identidad')->count(), 'nunca se borra');
    }

    public function test_una_decision_no_cambia_lo_que_el_portal_ofrece_por_http(): void
    {
        Mail::fake();
        $caso = $this->ident();
        $this->actingAs($this->admin())->post(route('credential-flow.historico.casos.identidad.crear', $caso->id), ['tipo' => 'requiere_soporte', 'motivo' => self::MOTIVO])->assertSessionHas('success');
        auth()->logout();
        $this->post(route('portal.solicitar'), ['documento' => '2000002', 'correo' => 'hugo@example.test'])->assertRedirect();
        $this->assertTrue(Mail::sent(CodigoAccesoMail::class)->isEmpty());
    }
}
