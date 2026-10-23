<?php

namespace Tests\Feature\CredentialFlow\Excel;

use App\Support\CredentialFlow\Participantes\ErrorArchivoException;
use App\Support\CredentialFlow\Participantes\Excel\Analisis\AnalisisExcel;
use App\Support\CredentialFlow\Participantes\Excel\Analisis\AnalizadorExcel;
use App\Support\CredentialFlow\Participantes\Excel\Analisis\ParticipanteDetectado;
use App\Support\CredentialFlow\Participantes\Excel\Analisis\ValorDetectado;
use App\Support\CredentialFlow\Participantes\Excel\LectorValoresCalculados;
use Illuminate\Http\UploadedFile;
use Tests\Feature\CredentialFlow\Support\XlsxSintetico as X;
use Tests\TestCase;

/**
 * F1 de la importación inteligente: analizador de la estructura de un Excel (sin base de datos). Todos los archivos son sintéticos y ficticios; la réplica de
 * «F-SC-DC-08» imita la forma del formato real (bloque del evento arriba, tabla desde la fila 14, 17 personas, filas numeradas vacías al final).
 */
class AnalizadorExcelTest extends TestCase
{
    /** @param  list<array{nombre: string, estado?: string, celdas: array<string,mixed>}>  $hojas */
    private function analizar(array $hojas, array $qa = []): AnalisisExcel
    {
        $libro = (new LectorValoresCalculados)->leer(UploadedFile::fake()->createWithContent('solicitud.xlsx', X::libro($hojas)));

        return (new AnalizadorExcel)->analizar($libro, $qa);
    }

    /** @param  array<string,mixed>  $celdas */
    private function una(array $celdas, array $qa = []): AnalisisExcel
    {
        return $this->analizar([['nombre' => 'Solicitud', 'celdas' => $celdas]], $qa);
    }

    private function reserva(string $literal, string $original = 'IMPORTRANGE(D10,"Hoja!A1")'): string
    {
        return 'IFERROR(__xludf.DUMMYFUNCTION("'.str_replace('"', '""', $original).'"),'.$literal.')';
    }

    /** Fórmula exportada de Google Sheets SIN resultado guardado (solo el valor de reserva). */
    private function solaReserva(string $texto): array
    {
        return ['f' => $this->reserva('"'.str_replace('"', '""', $texto).'"')];
    }

    /** Fórmula con resultado guardado. */
    private function guardada(string $texto): array
    {
        return ['f' => $this->reserva('"'.str_replace('"', '""', $texto).'"'), 't' => 'str', 'v' => $texto];
    }

    /**
     * Bloque del evento (filas 7–10) y tabla desde `$fila` con las mismas columnas del formato real.
     *
     * @return array<string,mixed>
     */
    private function replica(int $personas = 17, int $filaEncabezado = 14, bool $eventoArriba = true): array
    {
        $c = [];
        if ($eventoArriba) {
            $c += [
                'B7' => 'Nombre del Evento', 'D7' => $this->guardada("Módulo de actualización en violencias para funcionarios públicos\nLey 0000 de 2026"),
                'B8' => 'Gestora Comercial Encargada', 'D8' => $this->guardada('Gestora Ficticia'),
                'F8' => 'Modalidad', 'G8' => $this->guardada('Virtual-Sincrónico y Remoto'),
                'B9' => 'Fecha del Evento', 'D9' => ['f' => $this->reserva('46311'), 's' => 1, 'v' => '46311'],
                'F9' => 'Intensidad de horas', 'G9' => $this->guardada('Módulo 5 Horas'),
                'B10' => 'URL hoja de Calculo Formato Base Inscritos', 'D10' => 'abc123',
            ];
        }
        $h = $filaEncabezado;
        foreach (['B' => 'Item', 'C' => 'Identificaciòn', 'D' => 'Nombres y Apellidos', 'E' => 'Correo Electronico', 'F' => 'Diploma', 'G' => 'Sesión', 'H' => 'Certificado con intensidad completa', 'I' => 'Selección de Sesiones o Cupo a Certificar ', 'J' => 'Observaciones o comentarios adicionales'] as $col => $t) {
            $c[$col.$h] = $t;
        }
        for ($i = 1; $i <= $personas; $i++) {
            $f = $h + $i;
            $cupo = $i === $personas;
            $c['B'.$f] = $i;
            $c['C'.$f] = ['f' => $this->reserva((string) (1000000 + $i)), 't' => 'n', 'v' => (string) (1000000 + $i)];
            $c['D'.$f] = $this->solaReserva(sprintf('PERSONA %02d PRUEBA', $i));
            $c['E'.$f] = $this->guardada(sprintf('persona%02d@example.test', $i));
            $c['F'.$f] = $i % 2 === 0;
            $c['G'.$f] = $this->guardada($cupo ? 'Cupos' : 'Completo');
            if (! $cupo) {
                $c['H'.$f] = $this->guardada('X');
            }
        }
        // Filas numeradas de relleno (sin ninguna persona), como las deja la plantilla.
        for ($i = $personas + 1; $i <= $personas + 4; $i++) {
            $c['B'.($h + $i)] = $i;
            $c['D'.($h + $i)] = ['f' => $this->reserva('""'), 't' => 'str', 'v' => ''];
        }

        return $c;
    }

    private function p(AnalisisExcel $a, int $i): ParticipanteDetectado
    {
        return $a->participantes()[$i];
    }

    // ── Caso real (réplica sintética) ─────────────────────────────────────────

    public function test_replica_del_formato_real(): void
    {
        $a = $this->una($this->replica());

        $this->assertNotNull($a->tabla);
        $this->assertSame(14, $a->tabla->filaEncabezado);
        $this->assertCount(17, $a->participantes());
        $this->assertSame([15, 31], [$a->participantes()[0]->fila, $a->participantes()[16]->fila]);
        $this->assertSame(ValorDetectado::CONFIABLE, $a->tabla->confianza);

        // 16 con intensidad completa, 1 relacionado con cupos.
        $porSenal = array_count_values(array_map(fn ($p) => $p->senal, $a->participantes()));
        $this->assertSame([ParticipanteDetectado::SENAL_COMPLETO => 16, ParticipanteDetectado::SENAL_CUPO => 1], $porSenal);

        // Tipos de documento: 17 pendientes (no hay columna y no se inventan).
        foreach ($a->participantes() as $p) {
            $this->assertSame(ValorDetectado::FALTANTE, $p->campo('tipo_documento')->confianza);
            $this->assertNull($p->campo('tipo_documento')->valor);
        }
        $this->assertSame(1, $a->advertencia('sin_columna_de_tipo_de_documento'));
        $this->assertSame(17, $a->advertencia('tipo_de_documento_sin_confirmar'));

        // Datos comunes.
        $e = $a->evento;
        $this->assertSame('Módulo de actualización en violencias para funcionarios públicos — Ley 0000 de 2026', $e['evento']->valor);
        $this->assertSame(ValorDetectado::CONFIABLE, $e['evento']->confianza);
        $this->assertSame('16 de octubre de 2026', $e['fecha']->valor);
        $this->assertSame('2026-10-16', $e['fecha']->extra['iso']);
        $this->assertSame('5 HORAS', $e['intensidad_horaria']->valor);
        $this->assertSame('Virtual-Sincrónico y Remoto', $e['modalidad']->valor);   // literal
        $this->assertSame(ValorDetectado::FALTANTE, $e['ciudad']->confianza);        // nunca inventada
        $this->assertNull($e['ciudad']->valor);

        // Procedencia de cada valor.
        $nombre = $this->p($a, 0)->campo('nombre_completo');
        $this->assertSame('PERSONA 01 PRUEBA', $nombre->valor);
        $this->assertSame(['Solicitud', 15, 'D15', 'formula_reserva'], [$nombre->hoja, $nombre->fila, $nombre->referencia, $nombre->origenCelda]);
        $this->assertTrue($nombre->desactualizable);        // fórmula con valor de reserva
        $this->assertSame('PERSONA 01 PRUEBA', $nombre->original);
    }

    public function test_la_columna_diploma_no_se_lee_como_autorizacion(): void
    {
        $a = $this->una($this->replica());

        $this->assertFalse($this->p($a, 0)->diploma);           // fila 15: FALSE
        $this->assertTrue($this->p($a, 1)->diploma);            // fila 16: TRUE
        // La señal de asistencia no cambia con el diploma.
        $this->assertSame(ParticipanteDetectado::SENAL_COMPLETO, $this->p($a, 0)->senal);
        $this->assertSame(ParticipanteDetectado::SENAL_COMPLETO, $this->p($a, 1)->senal);
    }

    public function test_las_filas_numeradas_vacias_no_son_participantes(): void
    {
        $a = $this->una($this->replica());

        $this->assertCount(17, $a->participantes());
        $this->assertSame(4, $a->tabla->filasOmitidas);
        $this->assertSame(31, $a->tabla->filaFin);
    }

    public function test_los_contactos_qa_no_se_confunden_con_las_personas_del_excel(): void
    {
        $qa = [['correo' => 'qa.interno@example.test', 'documento' => '900000001']];
        $a = $this->una($this->replica(), $qa);

        $this->assertCount(17, $a->participantes());
        $this->assertSame(0, $a->advertencia('posibles_contactos_qa'));
        foreach ($a->participantes() as $p) {
            $this->assertNull($p->posibleQa);
        }

        // Si una persona del Excel coincide con un contacto QA, se marca (informativo) pero no se descarta ni se mezcla.
        $b = $this->una($this->replica(), [['correo' => 'PERSONA03@example.test'], ['documento' => '1.000.005']]);
        $this->assertCount(17, $b->participantes());
        $this->assertSame('correo', $this->p($b, 2)->posibleQa);
        $this->assertSame('documento', $this->p($b, 4)->posibleQa);
        $this->assertSame(2, $b->advertencia('posibles_contactos_qa'));
    }

    // ── Posición de la tabla ──────────────────────────────────────────────────

    public function test_encabezado_en_filas_distintas_da_el_mismo_resultado(): void
    {
        foreach ([1, 6, 14, 40, 120] as $fila) {
            $a = $this->una($this->replica(5, $fila, false));
            $this->assertSame($fila, $a->tabla->filaEncabezado, "fila $fila");
            $this->assertCount(5, $a->participantes(), "fila $fila");
            $this->assertSame($fila + 1, $this->p($a, 0)->fila);
        }
    }

    public function test_datos_del_evento_despues_de_la_tabla(): void
    {
        $c = $this->replica(5, 3, false);
        // Bloque administrativo debajo de la tabla, tras dos filas vacías.
        $c += ['B20' => 'Fecha del evento', 'D20' => '16/10/2026', 'B21' => 'Modalidad', 'D21' => 'Presencial', 'B22' => 'Intensidad horaria', 'D22' => 10];
        $a = $this->una($c);

        $this->assertCount(5, $a->participantes());
        $this->assertSame('16 de octubre de 2026', $a->evento['fecha']->valor);
        $this->assertSame('Presencial', $a->evento['modalidad']->valor);
        $this->assertSame('10 HORAS', $a->evento['intensidad_horaria']->valor);
    }

    public function test_un_pie_de_pagina_cierra_la_tabla(): void
    {
        $c = $this->replica(4, 3, false);
        $c['D8'] = 'Total participantes';      // fila 8 (debajo de la persona 4, fila 7) sin documento ni correo
        $c['E8'] = null;
        unset($c['E8']);
        $c['D9'] = 'OTRA PERSONA';
        $c['C9'] = 123456;   // no debe entrar: está después del pie
        $a = $this->una($c);

        $this->assertCount(4, $a->participantes());
    }

    public function test_hoja_sin_tabla(): void
    {
        $a = $this->una(['A1' => 'Solo una nota', 'A3' => 'Fecha del evento', 'B3' => '2026-10-16']);

        $this->assertNull($a->tabla);
        $this->assertSame([], $a->participantes());
        $this->assertSame(1, $a->advertencia('tabla_de_participantes_no_encontrada'));
        $this->assertSame('2026-10-16', $a->evento['fecha']->extra['iso']);
    }

    public function test_varias_hojas_elige_la_tabla_con_mas_participantes(): void
    {
        $chica = $this->replica(2, 2, false);
        $grande = $this->replica(9, 5, false);
        $a = $this->analizar([['nombre' => 'Chica', 'celdas' => $chica], ['nombre' => 'Grande', 'celdas' => $grande]]);

        $this->assertSame('Grande', $a->tabla->hoja);
        $this->assertCount(9, $a->participantes());
        $this->assertCount(1, $a->tablasAlternativas);
        $this->assertSame(2, $a->tablasAlternativas[0]['participantes']);
        $this->assertSame(1, $a->advertencia('tablas_competidoras'));
        $this->assertSame(['tabla_participantes', 'tabla_alternativa'], [$a->hojas[1]['rol'], $a->hojas[0]['rol']]);
    }

    public function test_dos_tablas_parecidas_en_la_misma_hoja_vuelven_ambigua_la_eleccion(): void
    {
        $c = $this->replica(6, 2, false);
        foreach ($this->replica(5, 20, false) as $ref => $v) {
            $c[$ref] = $v;
        }
        $a = $this->una($c);

        $this->assertSame(ValorDetectado::AMBIGUO, $a->tabla->confianza);
        $this->assertCount(1, $a->tablasAlternativas);
        $this->assertSame(2, $a->tabla->filaEncabezado);
    }

    public function test_una_hoja_oculta_no_gana_a_una_visible_del_mismo_tamano(): void
    {
        $a = $this->analizar([
            ['nombre' => 'Oculta', 'estado' => 'hidden', 'celdas' => $this->replica(5, 2, false)],
            ['nombre' => 'Visible', 'celdas' => $this->replica(5, 2, false)],
        ]);

        $this->assertSame('Visible', $a->tabla->hoja);
    }

    // ── Encabezados ───────────────────────────────────────────────────────────

    public function test_nombres_distintos_para_columnas_equivalentes(): void
    {
        $c = [
            'A1' => 'Apellidos y Nombres', 'B1' => 'Número de identificación', 'C1' => 'Tipo de documento', 'D1' => 'Correo electrónico', 'E1' => 'Asistencia', 'F1' => 'Color favorito',
            'A2' => 'ANA PRUEBA', 'B2' => '0012345678', 'C2' => 'Cédula de ciudadanía', 'D2' => 'ana@example.test', 'E2' => 'Completo', 'F2' => 'azul',
            'A3' => 'BEN PRUEBA', 'B3' => 'AB123456', 'C3' => 'Pasaporte', 'D3' => 'ben@example.test', 'F3' => 'rojo',
        ];
        $a = $this->una($c);

        $this->assertCount(2, $a->participantes());
        $this->assertSame('ANA PRUEBA', $this->p($a, 0)->campo('nombre_completo')->valor);
        $this->assertSame('C.C.', $this->p($a, 0)->campo('tipo_documento')->valor);
        $this->assertSame(ValorDetectado::CONFIABLE, $this->p($a, 0)->campo('tipo_documento')->confianza);
        $this->assertSame('PASAPORTE', $this->p($a, 1)->campo('tipo_documento')->valor);
        $this->assertArrayNotHasKey('f', $a->tabla->columnas);         // la columna irrelevante no se asigna a ningún campo
        $this->assertCount(5, $a->tabla->columnas);
        $this->assertSame(['F'], $a->tabla->encabezadosNoReconocidos);
    }

    public function test_encabezado_aproximado_es_solo_una_propuesta(): void
    {
        $c = [
            'A1' => 'Nombre y apellidos completos', 'B1' => 'Documento',
            'A2' => 'ANA PRUEBA', 'B2' => '123456',
        ];
        $a = $this->una($c);

        $col = $a->tabla->columnas['nombre_completo'];
        $this->assertSame('aproximado', $col->metodo);
        $this->assertSame(ValorDetectado::AMBIGUO, $col->confianza);
        $this->assertSame(ValorDetectado::AMBIGUO, $this->p($a, 0)->campo('nombre_completo')->confianza);
        $this->assertSame('ANA PRUEBA', $this->p($a, 0)->campo('nombre_completo')->valor);   // se propone, no se descarta
        $this->assertSame(ValorDetectado::AMBIGUO, $a->tabla->confianza);
        $this->assertSame(1, $a->advertencia('columnas_ambiguas'));
    }

    public function test_encabezados_duplicados_son_ambiguos_con_alternativas(): void
    {
        $c = [
            'A1' => 'Nombre completo', 'B1' => 'Documento', 'C1' => 'Correo', 'D1' => 'Correo',
            'A2' => 'ANA PRUEBA', 'B2' => '123456', 'C2' => 'ana@example.test', 'D2' => 'ana.otra@example.test',
        ];
        $a = $this->una($c);

        $col = $a->tabla->columnas['correo'];
        $this->assertSame('C', $col->letra);
        $this->assertSame(ValorDetectado::AMBIGUO, $col->confianza);
        $this->assertSame([['letra' => 'D', 'encabezado' => 'Correo']], $col->alternativas);
        $this->assertSame(ValorDetectado::AMBIGUO, $this->p($a, 0)->campo('correo')->confianza);
        $this->assertSame('ana@example.test', $this->p($a, 0)->campo('correo')->valor);
    }

    public function test_una_fila_de_datos_no_se_confunde_con_el_encabezado(): void
    {
        // Una persona llamada «Nombre» con documento «Cedula»… no forma una tabla por sí sola.
        $a = $this->una(['A1' => 'Hola', 'A2' => 'Nombre', 'B2' => 'Cedula']);

        $this->assertNotNull($a->tabla);                     // dos encabezados exactos sí forman una tabla…
        $this->assertSame([], $a->participantes());          // …pero sin filas no hay participantes
        $this->assertSame(ValorDetectado::AMBIGUO, $a->tabla->confianza);
    }

    // ── Valores ───────────────────────────────────────────────────────────────

    public function test_documentos_conservan_ceros_a_la_izquierda_y_avisan_si_llegaron_como_numero(): void
    {
        $c = [
            'A1' => 'Nombre', 'B1' => 'Documento',
            'A2' => 'ANA', 'B2' => '0012345678',          // texto: se conserva
            'A3' => 'BEN', 'B3' => 12345678,              // número: el cero inicial, si lo hubo, ya se perdió
            'A4' => 'CLA', 'B4' => 'C.C. 1.023.456.789',  // con prefijo y separadores
        ];
        $a = $this->una($c);

        $this->assertSame('0012345678', $this->p($a, 0)->campo('documento')->valor);
        $this->assertArrayNotHasKey('llego_como_numero', $this->p($a, 0)->campo('documento')->extra);
        $this->assertSame('12345678', $this->p($a, 1)->campo('documento')->valor);
        $this->assertTrue($this->p($a, 1)->campo('documento')->extra['llego_como_numero']);
        $this->assertSame(1, $a->advertencia('documentos_llegaron_como_numero'));

        $doc = $this->p($a, 2)->campo('documento');
        $this->assertSame('1023456789', $doc->valor);
        $this->assertSame('C.C. 1.023.456.789', $doc->original);
        $tipo = $this->p($a, 2)->campo('tipo_documento');
        $this->assertSame('C.C.', $tipo->valor);
        $this->assertSame(ValorDetectado::AMBIGUO, $tipo->confianza);        // tomado de un prefijo: lo confirma una persona
    }

    public function test_correos_invalidos_multiples_y_vacios(): void
    {
        $c = [
            'A1' => 'Nombre', 'B1' => 'Documento', 'C1' => 'Correo',
            'A2' => 'ANA', 'B2' => '111111', 'C2' => 'ana@example.test',
            'A3' => 'BEN', 'B3' => '222222', 'C3' => 'ben-sin-arroba',
            'A4' => 'CLA', 'B4' => '333333', 'C4' => 'cla@example.test; cla.otro@example.test',
            'A5' => 'DAN', 'B5' => '444444',
        ];
        $a = $this->una($c);

        $this->assertSame(ValorDetectado::CONFIABLE, $this->p($a, 0)->campo('correo')->confianza);
        $this->assertSame(ValorDetectado::AMBIGUO, $this->p($a, 1)->campo('correo')->confianza);
        $this->assertContains('formato_de_correo_invalido', $this->p($a, 1)->campo('correo')->motivos);
        $this->assertSame('cla@example.test', $this->p($a, 2)->campo('correo')->valor);
        $this->assertContains('varios_correos_en_la_celda', $this->p($a, 2)->campo('correo')->motivos);
        $this->assertSame(ValorDetectado::FALTANTE, $this->p($a, 3)->campo('correo')->confianza);
        $this->assertSame(1, $a->advertencia('participantes_sin_correo'));
    }

    public function test_asistencia_conflicto_estado_desconocido_y_sin_senal(): void
    {
        $c = [
            'A1' => 'Nombre', 'B1' => 'Documento', 'C1' => 'Sesión', 'D1' => 'Certificado con intensidad completa', 'E1' => 'Selección de Sesiones o Cupo a Certificar',
            'A2' => 'ANA', 'B2' => '111111', 'C2' => 'Completo', 'D2' => 'x',
            'A3' => 'BEN', 'B3' => '222222', 'C3' => 'Completo', 'E3' => 'X',             // dice completo y además cupo
            'A4' => 'CLA', 'B4' => '333333', 'C4' => 'Parcial',                            // estado que no conocemos: no se equipara
            'A5' => 'DAN', 'B5' => '444444',
            'A6' => 'EVA', 'B6' => '555555', 'C6' => 'cupos',
        ];
        $a = $this->una($c);

        $this->assertSame(
            ['completo', 'conflicto', 'desconocida', 'sin_senal', 'cupo'],
            array_map(fn ($p) => $p->senal, $a->participantes()),
        );
        $this->assertSame(ValorDetectado::AMBIGUO, $this->p($a, 2)->campo('sesion')->confianza);
        $this->assertSame(1, $a->advertencia('asistencia_en_conflicto'));
        $this->assertSame(1, $a->advertencia('asistencia_desconocida'));
    }

    public function test_formulas_sin_resultado_y_vacias_son_faltantes_con_motivo(): void
    {
        $c = [
            'A1' => 'Nombre', 'B1' => 'Documento', 'C1' => 'Correo',
            'A2' => 'ANA', 'B2' => ['f' => $this->reserva('4.5')],        // solo reserva numérica
            'C2' => ['f' => 'SUM(1,1)'],                                    // fórmula sin resultado ni reserva
            'A3' => 'BEN', 'B3' => '222222', 'C3' => ['f' => $this->reserva('""'), 't' => 'str', 'v' => ''],
            'E5' => 'Fecha del evento', 'F5' => ['f' => 'SUM(1,1)'],
        ];
        $a = $this->una($c);

        $this->assertSame(ValorDetectado::FALTANTE, $this->p($a, 0)->campo('correo')->confianza);
        $this->assertSame(['formula_sin_resultado_recuperable'], $this->p($a, 0)->campo('correo')->motivos);
        $this->assertSame(['formula_con_resultado_vacio'], $this->p($a, 1)->campo('correo')->motivos);
        $this->assertSame(ValorDetectado::FALTANTE, $a->evento['fecha']->confianza);
        $this->assertSame(['formula_sin_resultado_recuperable'], $a->evento['fecha']->motivos);
    }

    // ── Datos del evento ──────────────────────────────────────────────────────

    public function test_fechas_en_distintos_formatos(): void
    {
        $casos = [
            ['16 de octubre de 2026', '2026-10-16', ValorDetectado::CONFIABLE],
            ['16-oct-2026', '2026-10-16', ValorDetectado::CONFIABLE],
            ['2026-10-16', '2026-10-16', ValorDetectado::CONFIABLE],
            ['16/10/2026', '2026-10-16', ValorDetectado::CONFIABLE],          // 16 no puede ser mes
            ['03/04/2026', '2026-04-03', ValorDetectado::AMBIGUO],            // día y mes intercambiables
            ['16 y 17 de octubre de 2026', '2026-10-16', ValorDetectado::AMBIGUO],
            ['sin fecha todavía', null, ValorDetectado::AMBIGUO],
            ['31/02/2026', null, ValorDetectado::AMBIGUO],                    // fecha inexistente
        ];
        foreach ($casos as [$texto, $iso, $confianza]) {
            $a = $this->una(['A1' => 'Fecha del evento', 'B1' => $texto]);
            $f = $a->evento['fecha'];
            $this->assertSame($confianza, $f->confianza, $texto);
            $this->assertSame($iso, $f->extra['iso'] ?? null, $texto);
            $this->assertSame($texto, $f->original, $texto);
        }
        $dos = $this->una(['A1' => 'Fecha del evento', 'B1' => '16 y 17 de octubre de 2026']);
        $this->assertSame('16 y 17 de octubre de 2026', $dos->evento['fecha']->valor);
        $this->assertSame(['2026-10-16', '2026-10-17'], $dos->evento['fecha']->extra['isos']);
    }

    public function test_fecha_como_valor_de_excel_y_como_numero_de_serie(): void
    {
        $fecha = $this->una(['A1' => 'Fecha del evento', 'B1' => ['v' => '46311', 's' => 1]]);
        $this->assertSame(ValorDetectado::CONFIABLE, $fecha->evento['fecha']->confianza);
        $this->assertSame('2026-10-16', $fecha->evento['fecha']->extra['iso']);

        $serie = $this->una(['A1' => 'Fecha del evento', 'B1' => 46311]);       // sin formato de fecha
        $this->assertSame(ValorDetectado::AMBIGUO, $serie->evento['fecha']->confianza);
        $this->assertSame('2026-10-16', $serie->evento['fecha']->extra['iso']);
    }

    public function test_intensidad_en_horas(): void
    {
        $casos = [
            ['Módulo 5 Horas', '5 HORAS', ValorDetectado::CONFIABLE],
            ['40 horas', '40 HORAS', ValorDetectado::CONFIABLE],
            ['1,5 h', '1,5 HORAS', ValorDetectado::CONFIABLE],
            ['8', '8 HORAS', ValorDetectado::AMBIGUO],                  // sin unidad
            ['Módulo 2: 5 horas', null, ValorDetectado::AMBIGUO],       // dos cifras
            ['Intensidad a definir', null, ValorDetectado::AMBIGUO],
        ];
        foreach ($casos as [$texto, $esperado, $confianza]) {
            $a = $this->una(['A1' => 'Intensidad', 'B1' => $texto]);
            $this->assertSame($confianza, $a->evento['intensidad_horaria']->confianza, $texto);
            $this->assertSame($esperado, $a->evento['intensidad_horaria']->valor, $texto);
        }
        $num = $this->una(['A1' => 'Intensidad de horas', 'B1' => 5]);
        $this->assertSame('5 HORAS', $num->evento['intensidad_horaria']->valor);
        $this->assertSame(ValorDetectado::CONFIABLE, $num->evento['intensidad_horaria']->confianza);
    }

    public function test_la_modalidad_es_siempre_literal(): void
    {
        $a = $this->una(['A1' => 'Modalidad', 'B1' => "Virtual - Sincrónico   y Remoto\n"]);
        $this->assertSame('Virtual - Sincrónico y Remoto', $a->evento['modalidad']->valor);
        $this->assertSame('espacios_normalizados', $a->evento['modalidad']->transformacion);

        $b = $this->una(['A1' => 'Modalidad', 'B1' => 'Presencial']);
        $this->assertSame('Presencial', $b->evento['modalidad']->valor);
    }

    public function test_rotulo_con_valor_en_la_misma_celda_o_debajo(): void
    {
        $a = $this->una(['A1' => 'Ciudad: Bogotá', 'A3' => 'Lugar', 'A4' => 'Auditorio principal']);

        $this->assertSame('Bogotá', $a->evento['ciudad']->valor);
        $this->assertSame(ValorDetectado::CONFIABLE, $a->evento['ciudad']->confianza);
        $this->assertSame('Auditorio principal', $a->evento['lugar']->valor);
        $this->assertSame(ValorDetectado::AMBIGUO, $a->evento['lugar']->confianza);      // valor debajo: se avisa
        $this->assertContains('valor_debajo_del_rotulo', $a->evento['lugar']->motivos);
    }

    public function test_rotulo_con_dos_valores_distintos_es_ambiguo(): void
    {
        $a = $this->una(['A1' => 'Modalidad', 'B1' => 'Virtual', 'A5' => 'Modalidad', 'B5' => 'Presencial']);

        $m = $a->evento['modalidad'];
        $this->assertSame(ValorDetectado::AMBIGUO, $m->confianza);
        $this->assertSame('Virtual', $m->valor);
        $this->assertSame([['valor' => 'Presencial', 'referencia' => 'Solicitud!B5']], $m->alternativas);
    }

    public function test_un_rotulo_no_se_toma_como_valor_de_otro(): void
    {
        $a = $this->una(['A1' => 'Modalidad', 'B1' => 'Intensidad de horas', 'C1' => 'Módulo 4 Horas']);

        $this->assertSame(ValorDetectado::FALTANTE, $a->evento['modalidad']->confianza);
        $this->assertSame('4 HORAS', $a->evento['intensidad_horaria']->valor);
    }

    public function test_datos_del_evento_en_otra_hoja_son_ambiguos(): void
    {
        $a = $this->analizar([
            ['nombre' => 'Lista', 'celdas' => $this->replica(3, 2, false)],
            ['nombre' => 'Datos', 'celdas' => ['A1' => 'Fecha del evento', 'B1' => '16/10/2026']],
        ]);

        $this->assertSame(ValorDetectado::AMBIGUO, $a->evento['fecha']->confianza);
        $this->assertContains('dato_de_otra_hoja', $a->evento['fecha']->motivos);
        $this->assertSame('Datos', $a->evento['fecha']->hoja);
        $this->assertSame('datos_evento', $a->hojas[1]['rol']);
    }

    public function test_los_valores_que_vienen_de_importrange_se_marcan_como_posiblemente_desactualizados(): void
    {
        $a = $this->una($this->replica(3));

        $this->assertTrue($a->evento['evento']->desactualizable);
        $this->assertTrue($a->evento['modalidad']->desactualizable);
        $literal = $this->una(['A1' => 'Modalidad', 'B1' => 'Virtual']);
        $this->assertFalse($literal->evento['modalidad']->desactualizable);
        $this->assertGreaterThan(0, $a->advertencia('valores_del_evento_desactualizables'));
        $this->assertGreaterThan(0, $a->advertencia('valores_de_participantes_desactualizables'));
    }

    // ── Límites y archivos defectuosos ────────────────────────────────────────

    public function test_tope_de_participantes(): void
    {
        $c = ['A1' => 'Nombre', 'B1' => 'Documento'];
        for ($i = 2; $i <= 1100; $i++) {
            $c['A'.$i] = 'PERSONA '.$i;
            $c['B'.$i] = (string) (5000000 + $i);
        }
        $a = $this->una($c);

        $this->assertCount(AnalizadorExcel::PARTICIPANTES_MAX, $a->participantes());
        $this->assertTrue($a->tabla->truncada);
        $this->assertSame(1, $a->advertencia('participantes_truncados'));
        $this->assertLessThan(48, $a->metricas['memoria_analisis_mb']);
    }

    public function test_libro_vacio_o_sin_datos_relevantes_no_falla(): void
    {
        $a = $this->una(['A1' => 'x']);
        $this->assertNull($a->tabla);
        $this->assertSame(ValorDetectado::FALTANTE, $a->evento['evento']->confianza);
        $this->assertSame(['rotulo_no_encontrado'], $a->evento['evento']->motivos);
        $this->assertSame('sin_datos_relevantes', $a->hojas[0]['rol']);
    }

    public function test_archivo_dañado_lo_rechaza_el_lector_antes_del_analisis(): void
    {
        $this->expectException(ErrorArchivoException::class);
        (new LectorValoresCalculados)->leer(UploadedFile::fake()->createWithContent('roto.xlsx', 'esto no es un zip'));
    }

    public function test_el_tiempo_y_la_memoria_del_analisis_son_pequenos(): void
    {
        $a = $this->una($this->replica());

        $this->assertLessThan(2.0, $a->metricas['segundos']);
        $this->assertLessThan(128, $a->metricas['memoria_analisis_mb']);
    }

    // ── Privacidad ────────────────────────────────────────────────────────────

    public function test_el_resumen_seguro_no_contiene_datos_personales_ni_del_archivo(): void
    {
        $a = $this->una($this->replica(), [['correo' => 'persona03@example.test']]);
        $json = json_encode($a->resumenSeguro(), JSON_UNESCAPED_UNICODE);

        foreach (['PERSONA', 'persona0', 'example.test', '10000', 'Solicitud', 'Módulo', 'Gestora', 'Virtual', 'OCTUBRE', 'Ley 0000'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $json, $prohibido);
        }
        $r = $a->resumenSeguro();
        $this->assertSame(17, $r['participantes']);
        $this->assertSame(['completo' => 16, 'cupo' => 1], $r['senales']);
        $this->assertSame(1, $r['posibles_qa']);
        $this->assertSame(ValorDetectado::CONFIABLE, $r['evento_por_confianza']['fecha']);
    }

    public function test_to_array_es_serializable_y_conserva_la_trazabilidad(): void
    {
        $a = $this->una($this->replica(3));
        $arr = $a->toArray();

        $this->assertNotFalse(json_encode($arr, JSON_UNESCAPED_UNICODE));
        $p = $arr['tabla']['participantes'][0];
        $this->assertSame(15, $p['fila']);
        $this->assertSame('D15', $p['campos']['nombre_completo']['referencia']);
        $this->assertSame('Solicitud', $p['campos']['nombre_completo']['hoja']);
        $this->assertSame('D7', $arr['evento']['evento']['referencia']);
        $this->assertSame('Nombre del Evento', $arr['evento']['evento']['etiqueta']);
    }
}
