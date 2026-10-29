<?php

namespace App\Support\CredentialFlow\Importacion;

use App\Models\CredentialFlow\Correo;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Importacion;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Support\CredentialFlow\DisenoSchema;
use App\Support\CredentialFlow\Emisiones\DatosRequeridos;
use App\Support\CredentialFlow\Emisiones\ServicioVistaPrevia;
use App\Support\CredentialFlow\Eventos\AsociacionAmbiguaException;
use App\Support\CredentialFlow\Eventos\ServicioEventos;
use App\Support\CredentialFlow\Generacion\RedaccionFecha;
use App\Support\CredentialFlow\LogSeguro;
use App\Support\CredentialFlow\Participantes\DobleModalidad;
use App\Support\CredentialFlow\Participantes\ErrorArchivoException;
use App\Support\CredentialFlow\Participantes\ErrorFila;
use App\Support\CredentialFlow\Participantes\Excel\Analisis\AnalizadorExcel;
use App\Support\CredentialFlow\Participantes\Excel\LectorValoresCalculados;
use App\Support\CredentialFlow\Participantes\ImportacionInvalidaException;
use App\Support\CredentialFlow\Participantes\ImportadorParticipantes;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Participantes\ValidadorDatosComunes;
use App\Support\CredentialFlow\Participantes\ValidadorParticipante;
use App\Support\CredentialFlow\Qa\AdministradorContactosQa;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Importación inteligente (F3): conecta el analizador del Excel (F0/F1) con borradores revisables y, solo si el administrador lo confirma de forma explícita,
 * con el importador de siempre.
 *
 * Garantías:
 *  - Analizar y guardar/corregir un borrador NUNCA crea bases, participantes, eventos ni emisiones.
 *  - La confirmación es una acción aparte: bloquea el borrador, vuelve a evaluarlo todo y hace TODO o NADA en una sola transacción
 *    (evento si el administrador decidió crearlo, base, participantes y los contactos QA). Nunca emite, nunca envía correos, nunca arma ZIP.
 *  - Reutiliza el importador tradicional (se le entrega un CSV revisado generado desde el borrador), así que duplicados, tipos de documento, elegibilidad,
 *    correos, caracteres imprimibles y límites son los mismos de siempre.
 *  - Los datos personales del borrador están cifrados, caducan y se borran al terminar; el archivo original no se conserva.
 */
final class ServicioImportaciones
{
    public function __construct(
        private readonly LectorValoresCalculados $lector,
        private readonly AnalizadorExcel $analizador,
        private readonly ImportadorParticipantes $importador,
        private readonly ServicioQa $qa,
        private readonly ServicioEventos $eventos,
        private readonly ProponedorEvento $proponedor,
    ) {}

    public static function activa(): bool
    {
        return (bool) config('credential_flow.importacion_inteligente.activa', false);
    }

    // ================================================================= analizar / recuperar

    /**
     * Analiza el Excel y guarda (o recupera) su borrador. El mismo administrador con el mismo archivo recupera el mismo borrador mientras siga en revisión.
     *
     * @return array{0: Importacion, 1: bool} borrador y si ya existía
     *
     * @throws ImportacionException
     */
    public function analizar(UploadedFile $archivo, int $usuarioId): array
    {
        $this->limpiar();

        if (! $archivo->isValid() || $archivo->getRealPath() === false) {
            throw new ImportacionException(ImportacionException::ARCHIVO_INVALIDO, 'El archivo no se pudo subir completo. Inténtalo de nuevo.', 422);
        }
        $hash = hash_file('sha256', $archivo->getRealPath());
        $clave = Importacion::claveActiva($usuarioId, $hash);

        $existente = Importacion::query()->where('clave_activa', $clave)->first();
        if ($existente !== null) {
            if (! $existente->vencido()) {
                return [$existente, true];
            }
            $this->vencer($existente);
        }

        try {
            $libro = $this->lector->leer($archivo);
        } catch (ErrorArchivoException $e) {
            throw new ImportacionException(ImportacionException::ARCHIVO_INVALIDO, $e->getMessage(), 422);
        }

        $analisis = $this->analizador->analizar($libro, $this->contactosQa());
        if ($analisis->tabla === null || $analisis->participantes() === []) {
            throw new ImportacionException(ImportacionException::SIN_TABLA, 'No se encontró una tabla de participantes en el Excel. Revisa que tenga una fila de encabezados (nombre, documento…) con personas debajo.', 422);
        }

        $payload = $analisis->toArray();
        $payload['propuesta_evento'] = $this->proponedor->proponer($analisis->evento['evento']->valor === null ? null : (string) $analisis->evento['evento']->valor, $analisis->evento['fecha']->valor === null ? null : (string) $analisis->evento['fecha']->valor);
        $resumen = $analisis->resumenSeguro();
        $resumen['propuesta_evento'] = ['accion' => $payload['propuesta_evento']['accion'], 'candidatos' => count($payload['propuesta_evento']['candidatos'])];
        unset($resumen['metricas']);

        try {
            $importacion = Importacion::create([
                'usuario_id' => $usuarioId, 'estado' => Importacion::EN_REVISION, 'version' => 1, 'clave_activa' => $clave,
                'archivo_nombre' => mb_substr(preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', basename((string) $archivo->getClientOriginalName())) ?? 'archivo.xlsx', 0, 150),
                'archivo_hash' => $hash, 'archivo_bytes' => (int) $archivo->getSize(),
                'analisis' => $payload, 'correcciones' => Borrador::vacias(), 'resumen' => $resumen, 'advertencias' => $analisis->advertencias,
                'expira_at' => now()->addDays(max(1, (int) config('credential_flow.importacion_inteligente.retencion_dias', 7))),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Dos análisis simultáneos del mismo archivo: gana el primero, el segundo recupera ese borrador.
            return [Importacion::query()->where('clave_activa', $clave)->firstOrFail(), true];
        }

        Movimiento::registrar(tipo: 'registro', modulo: 'credential-flow', descripcion: 'Se analizó un Excel para la importación inteligente (borrador, sin crear nada)', extra: ['importacion_id' => $importacion->id]);

        return [$importacion, false];
    }

    /** @return list<array{correo: ?string, documento: ?string}> */
    private function contactosQa(): array
    {
        return $this->qa->contactos()->map(fn ($c) => ['correo' => $c->correo, 'documento' => $c->documento])->all();
    }

    // ================================================================= corregir

    /**
     * Registra correcciones y confirmaciones del administrador. Exige la versión que el administrador está viendo: si el borrador cambió mientras tanto,
     * se rechaza (nunca se pisa en silencio).
     *
     * @param  array<string,mixed>  $entrada
     *
     * @throws ImportacionException
     */
    public function corregir(Importacion $importacion, int $version, array $entrada): Importacion
    {
        $this->vigente($importacion);

        return DB::transaction(function () use ($importacion, $version, $entrada) {
            $actual = Importacion::query()->whereKey($importacion->id)->lockForUpdate()->firstOrFail();
            $this->exigirEnRevision($actual);
            if ($actual->version !== $version) {
                throw new ImportacionException(ImportacionException::VERSION_DESACTUALIZADA, 'El borrador cambió mientras lo revisabas. Vuelve a cargarlo antes de seguir.', 409);
            }
            $nuevas = Borrador::fusionar($actual->correcciones ?? Borrador::vacias(), $entrada, $actual->analisis ?? []);
            if (($nuevas['plantilla_id'] ?? null) !== null && ! Plantilla::query()->whereKey($nuevas['plantilla_id'])->exists()) {
                throw new ImportacionException(ImportacionException::CORRECCION_INVALIDA, 'La plantilla elegida no existe.', 422, [['campo' => 'plantilla_id', 'mensaje' => 'La plantilla elegida no existe.']]);
            }
            $actual->update(['correcciones' => $nuevas, 'version' => $actual->version + 1]);

            return $actual->fresh();
        });
    }

    // ================================================================= acciones masivas (F5)

    /**
     * Qué pasaría si se aplicara una acción masiva. Solo LEE: no guarda nada, no sube la versión y no crea nada. La respuesta lleva una `huella` de lo mostrado:
     * `aplicarMasiva` solo actúa si lo que va a hacer es exactamente lo que se mostró.
     *
     * @param  array<string,mixed>  $entrada
     * @return array<string,mixed>
     *
     * @throws ImportacionException
     */
    public function previsualizarMasiva(Importacion $importacion, int $version, array $entrada): array
    {
        $this->vigente($importacion);
        $actual = Importacion::query()->findOrFail($importacion->id);
        $this->exigirEnRevision($actual);
        $this->exigirVersion($actual, $version);

        $analisis = $actual->analisis ?? [];
        $accion = AccionesMasivas::normalizar($entrada, $analisis);

        return AccionesMasivas::planear($analisis, array_replace_recursive(Borrador::vacias(), $actual->correcciones ?? []), $accion, $actual->version);
    }

    /**
     * Aplica una acción masiva al BORRADOR (nunca crea la base ni emite). Todo o nada, bajo bloqueo y con la versión que el administrador está viendo.
     * Un reintento con el mismo `operacion_id` (doble clic, red lenta) no vuelve a aplicar nada: devuelve el borrador tal como quedó.
     *
     * @param  array<string,mixed>  $entrada  campo, valor, filas, verificado, confirmar_sobrescritura
     * @return array{0: Importacion, 1: bool, 2: array<string,mixed>|null} borrador, si fue un reintento ya aplicado y el plan aplicado
     *
     * @throws ImportacionException
     */
    public function aplicarMasiva(Importacion $importacion, int $version, string $operacionId, string $huella, array $entrada): array
    {
        $this->vigente($importacion);

        return DB::transaction(function () use ($importacion, $version, $operacionId, $huella, $entrada) {
            $actual = Importacion::query()->whereKey($importacion->id)->lockForUpdate()->firstOrFail();
            $this->exigirEnRevision($actual);

            $corr = array_replace_recursive(Borrador::vacias(), $actual->correcciones ?? []);
            if (collect($corr['historial'])->contains(fn ($h) => ($h['id'] ?? null) === $operacionId)) {
                return [$actual, true, null];   // ya se aplicó: es un reintento
            }
            $this->exigirVersion($actual, $version);

            $analisis = $actual->analisis ?? [];
            $accion = AccionesMasivas::normalizar($entrada, $analisis);
            $plan = AccionesMasivas::planear($analisis, $corr, $accion, $actual->version);

            if (! hash_equals($plan['huella'], $huella)) {
                throw new ImportacionException(ImportacionException::VISTA_PREVIA_OBSOLETA, 'Lo que vas a aplicar ya no es lo que revisaste (el borrador o la selección cambiaron). Vuelve a revisar los cambios.', 409);
            }
            if ($plan['se_modifican'] === 0) {
                throw new ImportacionException(ImportacionException::ACCION_SIN_CAMBIOS, 'Ninguna de las personas seleccionadas cambiaría: no hay nada que aplicar.', 422);
            }
            if ($plan['requiere_verificacion'] && $accion['verificado'] !== true) {
                throw new ImportacionException(ImportacionException::VERIFICACION_REQUERIDA, 'Para marcar como elegibles confirma que ya verificaste la asistencia de esas personas.', 422, [['campo' => 'verificado', 'mensaje' => 'Falta confirmar que verificaste la asistencia.']]);
            }
            if ($plan['requiere_confirmar_sobrescritura'] && $accion['confirmar_sobrescritura'] !== true) {
                throw new ImportacionException(ImportacionException::SOBRESCRITURA_SIN_CONFIRMAR, "Este cambio reemplaza una decisión que ya habías tomado en {$plan['sobrescriben']} ".($plan['sobrescriben'] === 1 ? 'persona' : 'personas').'. Confirma que quieres reemplazarla.', 422, [['campo' => 'confirmar_sobrescritura', 'mensaje' => 'Falta confirmar el reemplazo.']]);
            }

            $actual->update(['correcciones' => AccionesMasivas::aplicar($corr, $plan, $operacionId, $accion['verificado']), 'version' => $actual->version + 1]);
            // Solo cantidades: ningún nombre, documento ni correo llega al registro.
            Movimiento::registrar(tipo: 'actualizacion', modulo: 'credential-flow', descripcion: 'Se aplicó un cambio masivo a un borrador de importación inteligente (sin crear la base ni emitir)', extra: [
                'importacion_id' => $actual->id, 'campo' => $plan['campo'], 'valor' => $plan['valor'], 'aplicados' => $plan['se_modifican'], 'omitidos' => $plan['omitidos_total'], 'sobrescritos' => $plan['sobrescriben'],
            ]);

            return [$actual->fresh(), false, $plan];
        });
    }

    /**
     * Deshace la última acción masiva (solo si nadie volvió a tocar a esas personas).
     *
     * @throws ImportacionException
     */
    public function deshacerMasiva(Importacion $importacion, int $version, string $operacionId): Importacion
    {
        $this->vigente($importacion);

        return DB::transaction(function () use ($importacion, $version, $operacionId) {
            $actual = Importacion::query()->whereKey($importacion->id)->lockForUpdate()->firstOrFail();
            $this->exigirEnRevision($actual);
            $this->exigirVersion($actual, $version);

            $corr = AccionesMasivas::deshacer(array_replace_recursive(Borrador::vacias(), $actual->correcciones ?? []), $operacionId);
            $actual->update(['correcciones' => $corr, 'version' => $actual->version + 1]);
            Movimiento::registrar(tipo: 'actualizacion', modulo: 'credential-flow', descripcion: 'Se deshizo un cambio masivo de un borrador de importación inteligente', extra: ['importacion_id' => $actual->id]);

            return $actual->fresh();
        });
    }

    /** @throws ImportacionException */
    private function exigirVersion(Importacion $importacion, int $version): void
    {
        if ($importacion->version !== $version) {
            throw new ImportacionException(ImportacionException::VERSION_DESACTUALIZADA, 'El borrador cambió mientras lo revisabas. Vuelve a cargarlo antes de seguir.', 409);
        }
    }

    // ================================================================= evaluar

    /**
     * ¿Está listo el borrador para importar? Solo LEE: valida con el importador de siempre sobre un CSV revisado temporal (que se borra) y no escribe en ninguna tabla.
     *
     * @return array{listo: bool, bloqueos: list<array<string,mixed>>, avisos: list<array<string,mixed>>, resumen: array<string,mixed>, plan: array<string,mixed>}
     */
    public function evaluar(Importacion $importacion): array
    {
        $analisis = $importacion->analisis ?? [];
        $corr = array_replace_recursive(Borrador::vacias(), $importacion->correcciones ?? []);
        $comunes = Borrador::datosComunes($analisis, $corr);
        $participantes = Borrador::participantes($analisis, $corr);
        $bloqueos = [];
        $avisos = [];
        $bloquear = function (string $codigo, string $mensaje, array $extra = []) use (&$bloqueos) {
            $bloqueos[] = ['codigo' => $codigo, 'mensaje' => $mensaje] + $extra;
        };

        // ── Plantilla ────────────────────────────────────────────────────────
        $plantilla = $corr['plantilla_id'] === null ? null : Plantilla::query()->find($corr['plantilla_id']);
        if ($plantilla === null) {
            $bloquear('plantilla_faltante', 'Elige la plantilla que usará la base.');
        } elseif (($plantilla->es_plantilla_qa ?? false) || ! ServicioVistaPrevia::plantillaValida($plantilla)) {
            $bloquear('plantilla_invalida', 'La plantilla elegida no sirve para certificados oficiales (sin diseño guardado o de pruebas internas).');
            $plantilla = null;
        }

        // ── Evento ───────────────────────────────────────────────────────────
        $propuesta = $this->proponedor->proponer($comunes['evento']['valor'], $comunes['fecha']['valor']);
        $evento = null;
        $nombreEvento = null;
        $decision = $corr['evento'];
        if (! is_array($decision) || ($decision['decision'] ?? null) === null) {
            $bloquear('evento_sin_decision', 'Decide a qué evento pertenece la base: asociarla a uno existente o confirmar que es un evento nuevo. Hasta entonces no se crea ni se modifica ningún evento.');
        } elseif ($decision['decision'] === 'asociar') {
            $evento = ($decision['evento_id'] ?? null) === null ? null : EventoCertificacion::query()->find($decision['evento_id']);
            if ($evento === null || $evento->origen !== EventoCertificacion::ORIGEN_CREDENTIAL_FLOW || $evento->estado !== EventoCertificacion::ESTADO_ACTIVO) {
                $bloquear('evento_invalido', 'El evento elegido no existe o no admite bases nuevas (solo eventos activos creados en Credential Flow).');
                $evento = null;
            } else {
                $nombreEvento = $evento->nombre;
                if ($this->eventos->archivoRepetido($evento->id, $importacion->archivo_hash)) {
                    $bloquear('archivo_repetido_en_evento', 'Este mismo Excel ya se importó en una base de ese evento. Si es una corrección, edita al participante en la base.');
                }
            }
        } else {
            $nombreEvento = $decision['nombre'] ?? $comunes['evento']['valor'];
            if ($nombreEvento === null || trim((string) $nombreEvento) === '') {
                $bloquear('evento_sin_nombre', 'Escribe el nombre del evento nuevo.');
            } else {
                // Se compara el nombre que el administrador quiere CREAR (no necesariamente el del Excel).
                $paraCrear = $this->proponedor->proponer((string) $nombreEvento, $comunes['fecha']['valor']);
                $exacto = collect($paraCrear['candidatos'])->contains(fn ($c) => $c['coincidencia'] === 'exacta');
                if ($exacto) {
                    $bloquear('evento_ya_existe', 'Ya existe un evento con ese nombre: asocia la base a él en lugar de crear otro.');
                } elseif ($paraCrear['candidatos'] !== [] && ($decision['confirmar_distinto'] ?? false) !== true) {
                    $bloquear('evento_similar_sin_confirmar', 'Hay eventos parecidos: confirma que este es un evento distinto o asócialo a uno existente.');
                } elseif ($paraCrear['homonimo_historico'] && ($decision['confirmar_distinto'] ?? false) !== true) {
                    $bloquear('evento_similar_sin_confirmar', 'Existe un evento histórico con el mismo nombre y año: confirma que este es un evento nuevo y distinto.');
                }
            }
        }
        if ($propuesta['candidatos'] !== [] && ($decision['decision'] ?? null) === 'asociar' && $evento !== null && ($propuesta['candidatos'][0]['diferencia']['iguales'] ?? true) === false && (int) $propuesta['candidatos'][0]['id'] === (int) $evento->id) {
            $avisos[] = ['codigo' => 'nombre_del_excel_difiere_del_evento', 'mensaje' => 'El nombre del Excel no es idéntico al del evento registrado; la base usará el nombre del evento.'];
        }

        // ── Datos comunes ────────────────────────────────────────────────────
        $entrada = [];
        foreach (Borrador::COMUNES as $campo) {
            if ($campo !== 'evento' && $comunes[$campo]['valor'] !== null) {
                $entrada[$campo] = $comunes[$campo]['valor'];
            }
        }
        $entrada['evento'] = $nombreEvento ?? $comunes['evento']['valor'];
        foreach (Borrador::COMUNES_REQUERIDOS as $campo) {
            if ($campo !== 'evento' && $comunes[$campo]['valor'] === null) {
                $bloquear('dato_comun_faltante', "Falta el dato «{$campo}» de la base: escríbelo.", ['campo' => $campo]);
            }
        }
        foreach ($comunes as $campo => $info) {
            if ($info['requiere_confirmacion']) {
                $bloquear('dato_comun_sin_confirmar', "Confirma o corrige «{$campo}»: ".($info['desactualizable'] ? 'viene de una fórmula que puede estar desactualizada.' : 'el análisis no lo detectó con certeza.'), ['campo' => $campo]);
            }
        }
        $validacion = ValidadorDatosComunes::validar($entrada);
        foreach ($validacion['errores'] as $campo => $mensaje) {
            if (! ($campo === 'evento' && $nombreEvento === null && $comunes['evento']['valor'] === null) && ! collect($bloqueos)->contains(fn ($b) => ($b['campo'] ?? null) === $campo && $b['codigo'] === 'dato_comun_faltante')) {
                $bloquear('dato_comun_invalido', $mensaje, ['campo' => $campo]);
            }
        }
        $finales = $validacion['datos'];
        // La modalidad puede venir POR PERSONA (columna «Modalidad» del Excel): la general de la base solo hace falta para quien no tenga la suya. Nunca se inventa.
        $aImportar = array_values(array_filter($participantes, fn ($p) => ! $p['excluir']));
        $sinModalidad = array_values(array_filter($aImportar, fn ($p) => trim((string) ($p['modalidad'] ?? '')) === '' && ! ($p['modalidad_por_revisar'] ?? false)));   // las «por revisar» tienen su propio bloqueo
        $conPropia = $aImportar !== [] && count($sinModalidad) < count($aImportar);
        if ($plantilla !== null && ($faltan = DatosRequeridos::faltantes($plantilla->diseno, $finales, $conPropia)) !== []) {
            $bloquear('datos_del_diseno_incompletos', 'La plantilla imprime '.implode(', ', array_map(fn ($e) => "«{$e}»", $faltan)).' y el borrador no tiene ese dato.', ['campos' => $faltan]);
        }
        if ($plantilla !== null && RedaccionFecha::requiereRevision((array) $plantilla->diseno, (string) ($finales['fecha'] ?? ''))) {
            $bloquear('fecha_sin_dias_claros', 'No podemos saber si la fecha es de un día o de varios, y la plantilla escribe «el día» o «los días» antes de la fecha. Escríbela como «16 de octubre de 2026» o «22 y 23 de octubre de 2026».', ['campo' => 'fecha']);
        }
        if ($plantilla !== null && $conPropia && $sinModalidad !== [] && trim((string) ($finales['modalidad'] ?? '')) === '' && in_array('modalidad', DisenoSchema::camposUsados($plantilla->diseno), true)) {
            $n = count($sinModalidad);
            $bloquear('modalidad_faltante', ($n === 1 ? 'Una persona no tiene' : "{$n} personas no tienen").' modalidad y la base no tiene una general: escríbela en cada una o como modalidad general de la base. Nunca se completa sola.',
                ['filas' => array_map(fn ($p) => $p['fila'], array_slice($sinModalidad, 0, 200))]);
        }

        // ── Participantes ────────────────────────────────────────────────────
        $incluidos = array_values(array_filter($participantes, fn ($p) => ! $p['excluir']));
        if ($incluidos === []) {
            $bloquear('sin_participantes', 'No queda ningún participante por importar.');
        }
        if (collect($incluidos)->contains(fn ($p) => $p['valores_desactualizados'] !== []) && ($corr['confirmados']['valores_desactualizados'] ?? false) !== true) {
            $bloquear('valores_desactualizados_sin_revisar', 'Algunos datos de participantes vienen de fórmulas que pueden estar desactualizadas. Revísalos y confirma que están al día.');
        }
        if (collect($incluidos)->contains(fn ($p) => $p['valores_ambiguos'] !== []) && ($corr['confirmados']['valores_ambiguos'] ?? false) !== true) {
            $bloquear('valores_ambiguos_sin_revisar', 'Algunos datos de participantes no se detectaron con certeza. Revísalos y confirma que están bien.');
        }
        // Lo que impide certificar a una persona se resuelve ANTES de crear la base (nadie queda fuera sin que se vea): su modalidad sin aclarar, una
        // elegibilidad que el Excel declara distinta de «Elegible» (se decide a propósito) y su tipo de documento por confirmar.
        $filasDe = fn (callable $f) => array_map(fn ($p) => $p['fila'], array_values(array_filter($incluidos, $f)));
        $cuantas = fn (int $n, string $una, string $varias) => $n === 1 ? $una : str_replace('{n}', (string) $n, $varias);
        if (($filas = $filasDe(fn ($p) => ($p['modalidad_por_revisar'] ?? false) && trim((string) ($p['modalidad'] ?? '')) === '')) !== []) {
            $bloquear('modalidad_por_revisar', $cuantas(count($filas), 'Una persona no tiene una modalidad clara en el Excel', '{n} personas no tienen una modalidad clara en el Excel')
                .' (vacía, confusa o contradictoria): escríbela en cada una. No se usa la modalidad general en su lugar.', ['filas' => array_slice($filas, 0, 200)]);
        }
        if (($filas = $filasDe(fn ($p) => $p['elegibilidad'] === 'pendiente')) !== []) {
            $bloquear('elegibilidad_sin_decidir', $cuantas(count($filas), 'El Excel declara que una persona no es «Elegible»', 'El Excel declara que {n} personas no son «Elegible»')
                .' («No elegible», «Pendiente» o un estado que no se entiende): decide en cada una si se certifica o no, o exclúyela.', ['filas' => array_slice($filas, 0, 200)]);
        }
        if (($filas = $filasDe(fn ($p) => $p['tipo_documento'] === null)) !== []) {
            $bloquear('tipo_documento_sin_confirmar', $cuantas(count($filas), 'Falta confirmar el tipo de documento de una persona', 'Falta confirmar el tipo de documento de {n} personas')
                .': sin él no se puede emitir su certificado.', ['filas' => array_slice($filas, 0, 200)]);
        }
        foreach ($incluidos as $p) {
            $t = $p['intensidad_horaria'];
            if ($t !== null && (Texto::tieneControl($t) || mb_strlen($t) > 255 || ValidadorParticipante::noSoportados($t) !== [])) {
                $bloquear('intensidad_individual_invalida', 'La intensidad horaria individual no se puede imprimir.', ['fila' => $p['fila']]);
            }
            $m = $p['modalidad'] ?? null;
            if ($m !== null && (Texto::tieneControl($m) || mb_strlen($m) > 120 || ValidadorParticipante::noSoportados($m) !== [])) {
                $bloquear('modalidad_individual_invalida', 'La modalidad de esta persona no se puede imprimir.', ['fila' => $p['fila']]);
            }
        }

        // ── Contactos QA ─────────────────────────────────────────────────────
        $contactosQa = $this->qa->contactos();
        if (config('credential_flow.qa.automatico', true) && config('credential_flow.qa.exigir_catalogo', true)) {
            $catalogo = app(AdministradorContactosQa::class)->catalogoListo();
            if (! $catalogo['listo']) {
                $bloquear('catalogo_qa_incompleto', 'El catálogo de contactos QA no está listo: '.implode(' ', $catalogo['motivos']));
            }
        }

        // ── Validación con el importador de siempre ──────────────────────────
        $resultado = null;
        $dobleModalidad = $this->dobleModalidad($evento, $incluidos, $finales);
        if ($incluidos !== []) {
            $archivo = ArchivoRevisado::crear($incluidos);
            try {
                $resultado = $this->importador->validar($archivo, $plantilla, $finales, $dobleModalidad);
            } finally {
                @unlink($archivo->getRealPath());
            }
            foreach (array_slice($resultado->errores, 0, 200) as $e) {
                $filaExcel = $e->fila !== null && $e->fila >= 2 ? ($incluidos[$e->fila - 2]['fila'] ?? null) : null;
                // El importador cuenta las líneas del CSV revisado (encabezado = 1): en el mensaje se muestran como filas del EXCEL, que es lo que ve el administrador.
                $mensaje = preg_replace_callback('/\bfila (\d+)\b/u', fn ($m) => 'fila '.($incluidos[(int) $m[1] - 2]['fila'] ?? $m[1]), $e->mensaje) ?? $e->mensaje;
                $bloquear('fila_invalida', $mensaje, ['fila' => $filaExcel, 'campo' => $e->columna, 'error' => $e->codigo]);
            }
            if (count($resultado->errores) > 200) {
                $bloquear('fila_invalida', 'Hay más de 200 errores: corrige los primeros y vuelve a validar.');
            }
            $claves = $contactosQa->pluck('documento_clave')->filter()->flip();
            $digitos = $contactosQa->map(fn ($c) => preg_replace('/\D/', '', (string) $c->documento_clave))->filter()->flip();
            $correosQa = $contactosQa->pluck('correo_normalizado')->filter()->flip();
            foreach ($resultado->participantes as $i => $valida) {
                $filaExcel = $incluidos[$valida['fila'] - 2]['fila'] ?? null;
                if ($claves->has($valida['documento_clave'])) {
                    $bloquear('documento_de_contacto_qa', 'Este documento es el de un contacto QA oficial: no puede ser un participante real. Corrígelo o exclúyelo.', ['fila' => $filaExcel]);
                } elseif (($d = preg_replace('/\D/', '', (string) $valida['documento_clave'])) !== '' && $digitos->has($d)) {
                    $avisos[] = ['codigo' => 'documento_parecido_a_contacto_qa', 'mensaje' => 'El número de este documento coincide con el de un contacto QA (con otro tipo): revísalo.', 'fila' => $filaExcel];
                }
                foreach ($valida['correos'] ?? [] as $correo) {
                    if ($correosQa->has(Correo::normalizar($correo))) {
                        $avisos[] = ['codigo' => 'correo_de_contacto_qa', 'mensaje' => 'El correo de esta persona es el de un contacto QA oficial.', 'fila' => $filaExcel];
                    }
                }
            }
            foreach (collect($resultado->avisos)->groupBy('codigo') as $codigo => $lista) {
                $avisos[] = ['codigo' => 'importador_'.strtolower((string) $codigo), 'mensaje' => $lista->first()->mensaje, 'cantidad' => $lista->count()];
            }
            if ($dobleModalidad !== null && ($n = self::documentosConDobleModalidad($resultado->participantes)) > 0) {
                $avisos[] = ['codigo' => 'doble_modalidad_autorizada', 'mensaje' => ($n === 1 ? 'Una persona tendrá' : "{$n} personas tendrán").' dos participaciones (una por modalidad): el evento está autorizado para la doble certificación por modalidad.', 'cantidad' => $n];
            }
        }

        $porElegibilidad = array_count_values(array_map(fn ($p) => $p['elegibilidad'], $incluidos));
        $resumen = [
            'participantes_detectados' => count($participantes), 'incluidos' => count($incluidos), 'excluidos' => count($participantes) - count($incluidos),
            'elegibles' => $porElegibilidad['elegible'] ?? 0, 'pendientes' => $porElegibilidad['pendiente'] ?? 0, 'no_elegibles' => $porElegibilidad['no_elegible'] ?? 0,
            'tipo_documento_por_confirmar' => count(array_filter($incluidos, fn ($p) => $p['tipo_documento'] === null)),
            'con_valores_desactualizados' => count(array_filter($incluidos, fn ($p) => $p['valores_desactualizados'] !== [])),
            'con_valores_ambiguos' => count(array_filter($incluidos, fn ($p) => $p['valores_ambiguos'] !== [])),
            'asistencia_cupo' => count(array_filter($incluidos, fn ($p) => $p['senal'] === 'cupo')),
            // Cuántas personas hay de cada modalidad propia (vacío si el Excel no trae modalidad por persona).
            'por_modalidad' => array_count_values(array_values(array_filter(array_map(fn ($p) => $p['modalidad'] ?? null, $incluidos), fn ($m) => is_string($m) && $m !== ''))),
        ];

        return [
            // `bloqueos` son SOLO los pendientes que impiden CREAR la base. `pendientes_para_emitir` son los que NO la impiden (la base se crea igual), pero la persona no
            // podrá recibir su certificado oficial hasta que se resuelvan.
            'listo' => $bloqueos === [], 'bloqueos' => $bloqueos, 'avisos' => $avisos, 'resumen' => $resumen,
            'pendientes_para_emitir' => ['tipo_documento' => $resumen['tipo_documento_por_confirmar'], 'elegibilidad' => $resumen['pendientes'], 'no_elegibles' => $resumen['no_elegibles'], 'asistencia_cupo' => $resumen['asistencia_cupo']],
            // Con qué plantilla saldrán las pruebas internas: SOLO la variante de la plantilla elegida. Sin ella la base se puede crear, pero la emisión
            // conjunta no empezará hasta asociarla (se avisa aquí, antes de crearla). null = todavía no hay plantilla elegida.
            'variante_qa' => $plantilla === null ? null : ['nombre' => $this->qa->varianteDe($plantilla)?->nombre],
            'plan' => ['plantilla' => $plantilla, 'evento' => $evento, 'decision' => $decision, 'nombre_evento' => $nombreEvento, 'comunes' => $finales, 'incluidos' => $incluidos, 'propuesta_evento' => $propuesta],
        ];
    }

    /**
     * Doble certificación por modalidad: solo si la base se ASOCIA a un evento autorizado (DobleModalidad). Devuelve la modalidad efectiva de cada persona
     * indexada por su línea en el CSV revisado (posición + 2, la misma correspondencia que comprueba FilasDeOrigen), o null = la regla de siempre. Un evento
     * que se crea al confirmar nunca está autorizado todavía.
     *
     * @param  list<array<string,mixed>>  $incluidos
     * @param  array<string,mixed>  $comunes
     * @return array<int,string>|null
     */
    private function dobleModalidad(?EventoCertificacion $evento, array $incluidos, array $comunes): ?array
    {
        if (! DobleModalidad::autorizado($evento?->id)) {
            return null;
        }
        $porLinea = [];
        foreach (array_values($incluidos) as $i => $p) {
            $porLinea[$i + FilasDeOrigen::PRIMERA_LINEA] = DobleModalidad::efectiva($p['modalidad'] ?? null, $comunes['modalidad'] ?? null);
        }

        return $porLinea;
    }

    /** @param  iterable<array<string,mixed>>  $participantes  cada uno con `documento_clave` */
    private static function documentosConDobleModalidad(iterable $participantes): int
    {
        $porDocumento = [];
        foreach ($participantes as $p) {
            $porDocumento[(string) $p['documento_clave']] = ($porDocumento[(string) $p['documento_clave']] ?? 0) + 1;
        }

        return count(array_filter($porDocumento, fn (int $n) => $n > 1));
    }

    /** Vista completa del borrador para el administrador (con sus correcciones aplicadas y la evaluación actual). @return array<string,mixed> */
    public function vista(Importacion $importacion): array
    {
        $analisis = $importacion->analisis ?? [];
        $corr = array_replace_recursive(Borrador::vacias(), $importacion->correcciones ?? []);
        $evaluacion = $importacion->enRevision() ? $this->evaluar($importacion) : null;
        unset($evaluacion['plan']);

        return [
            'id' => $importacion->id, 'estado' => $importacion->estado, 'version' => $importacion->version,
            'expira_at' => $importacion->expira_at?->toIso8601String(), 'creado_at' => $importacion->created_at?->toIso8601String(),
            'archivo' => ['nombre' => $importacion->archivo_nombre, 'bytes' => $importacion->archivo_bytes, 'hash' => substr($importacion->archivo_hash, 0, 12)],
            'lote_id' => $importacion->lote_id,
            'resumen' => $importacion->resumen,
            'advertencias' => $importacion->advertencias,
            'hoja' => $analisis['tabla']['hoja'] ?? null, 'fila_encabezado' => $analisis['tabla']['fila_encabezado'] ?? null,
            'evento' => $analisis === [] ? null : [
                'propuesta' => $this->proponedor->proponer(Borrador::datosComunes($analisis, $corr)['evento']['valor'], Borrador::datosComunes($analisis, $corr)['fecha']['valor']),
                'decision' => $corr['evento'],
            ],
            'datos_comunes' => $analisis === [] ? [] : Borrador::datosComunes($analisis, $corr),
            'plantilla_id' => $corr['plantilla_id'], 'nombre_base' => $corr['nombre_base'], 'confirmados' => $corr['confirmados'],
            'participantes' => $analisis === [] ? [] : Borrador::participantes($analisis, $corr),
            'historial' => AccionesMasivas::historialPublico($corr),
            'avisos_generales' => $analisis === [] ? null : $this->avisosGenerales($analisis),
            'validacion' => $evaluacion,
        ];
    }

    /**
     * Avisos que la pantalla agrupa en una sola tarjeta (en vez de uno por dato): cuántos valores vienen de fórmulas que pueden estar desactualizadas
     * (por ejemplo `IMPORTRANGE` de Google Sheets) y si hay observaciones del Excel que NO se conservarán en la base.
     *
     * @param  array<string,mixed>  $analisis
     * @return array{desactualizables_evento: int, desactualizables_participantes: int, observaciones_no_se_conservan: bool}
     */
    private function avisosGenerales(array $analisis): array
    {
        $evento = count(array_filter($analisis['evento'] ?? [], fn ($v) => is_array($v) && ($v['desactualizable'] ?? false) === true));
        $participantes = 0;
        $observaciones = false;
        foreach ($analisis['tabla']['participantes'] ?? [] as $p) {
            foreach ($p['campos'] ?? [] as $campo => $v) {
                if (is_array($v) && ($v['desactualizable'] ?? false) === true) {
                    $participantes++;
                }
                if ($campo === 'observaciones' && is_array($v) && ($v['valor'] ?? null) !== null) {
                    $observaciones = true;
                }
            }
        }

        return ['desactualizables_evento' => $evento, 'desactualizables_participantes' => $participantes, 'observaciones_no_se_conservan' => $observaciones];
    }

    // ================================================================= confirmar

    /**
     * Crea la base. Es una acción explícita y única: bloquea el borrador, lo evalúa de nuevo y hace todo o nada en una sola transacción. No emite certificados,
     * no genera ZIP y no envía correos.
     *
     * @throws ImportacionException
     */
    public function confirmar(Importacion $importacion, int $version, int $usuarioId): Lote
    {
        $this->vigente($importacion);
        $archivo = null;

        try {
            return DB::transaction(function () use ($importacion, $version, $usuarioId, &$archivo) {
                $actual = Importacion::query()->whereKey($importacion->id)->lockForUpdate()->firstOrFail();
                $this->exigirEnRevision($actual);
                if ($actual->version !== $version) {
                    throw new ImportacionException(ImportacionException::VERSION_DESACTUALIZADA, 'El borrador cambió mientras lo revisabas. Vuelve a cargarlo y revísalo antes de confirmar.', 409);
                }

                $evaluacion = $this->evaluar($actual);
                if (! $evaluacion['listo']) {
                    throw new ImportacionException(ImportacionException::NO_LISTO, 'El borrador todavía no está listo para importar: resuelve los pendientes y vuelve a confirmar.', 422, $evaluacion['bloqueos']);
                }
                $plan = $evaluacion['plan'];
                $corr = array_replace_recursive(Borrador::vacias(), $actual->correcciones ?? []);

                // Evento: solo ahora, y solo lo que el administrador decidió.
                $evento = $plan['evento'];
                if ($plan['decision']['decision'] === 'crear') {
                    try {
                        $evento = $this->eventos->crearEvento((string) $plan['nombre_evento'], (string) ($plan['comunes']['fecha'] ?? ''), true)['evento'];
                    } catch (AsociacionAmbiguaException $e) {
                        throw new ImportacionException(ImportacionException::NO_LISTO, 'No se pudo crear el evento: '.implode(' ', $e->motivos ?? []), 422);
                    }
                }

                $archivo = ArchivoRevisado::crear($plan['incluidos']);
                $nombreBase = $corr['nombre_base'] ?? Texto::limpiar(mb_substr(($plan['comunes']['evento'] ?? 'Base').' — '.($plan['comunes']['fecha'] ?? ''), 0, 200));
                $dobleModalidad = $this->dobleModalidad($plan['evento'], $plan['incluidos'], $plan['comunes']);
                $lote = $this->importador->importar($archivo, $plan['plantilla'], $nombreBase, null, $plan['comunes'], $evento?->id, $actual->archivo_hash, $actual->archivo_nombre, $dobleModalidad);

                // Trazabilidad: `fila_origen` pasa de la línea del CSV intermedio a la fila REAL del Excel (correspondencia comprobada; si no cuadra, se deshace todo).
                // Va ANTES de incorporar los QA, que no tienen fila de origen.
                $idsPorPosicion = FilasDeOrigen::asignar($lote->id, $plan['incluidos']);
                foreach ($plan['incluidos'] as $i => $p) {
                    $propios = array_filter(['intensidad_horaria' => $p['intensidad_horaria'], 'modalidad' => $p['modalidad'] ?? null], fn ($v) => $v !== null && $v !== '');
                    if ($propios !== []) {
                        Participante::query()->whereKey($idsPorPosicion[$i])->update($propios);
                    }
                }

                $this->incorporarQa($lote, $usuarioId);
                $this->trasladarVistaPrevia($lote, $corr, $plan);

                $actual->update([
                    'estado' => Importacion::CONFIRMADO, 'lote_id' => $lote->id, 'clave_activa' => null, 'confirmado_at' => now(), 'confirmado_por' => $usuarioId,
                    'analisis' => null, 'correcciones' => null, 'version' => $actual->version + 1,
                ]);
                // Auditoría de la excepción: cuántos documentos quedaron con dos participaciones (sin datos personales).
                $dobles = $dobleModalidad === null ? null : self::documentosConDobleModalidad(Participante::query()->where('lote_id', $lote->id)->where('es_control_qa', false)->get(['documento_clave'])->toArray());
                Movimiento::registrar(tipo: 'registro', modulo: 'credential-flow', descripcion: 'Se confirmó una importación inteligente y se creó una base (sin emitir certificados)', extra: ['importacion_id' => $actual->id, 'lote_id' => $lote->id]
                    + ($dobles === null ? [] : ['doble_modalidad' => ['evento_id' => $evento?->id, 'documentos' => $dobles]]));

                return $lote;
            });
        } catch (ImportacionInvalidaException $e) {
            throw new ImportacionException(ImportacionException::IMPORTACION_INVALIDA, $e->getMessage(), 422, array_map(fn (ErrorFila $f) => $f->toArray(), array_slice($e->resultado->errores, 0, 50)));
        } finally {
            if ($archivo !== null) {
                @unlink($archivo->getRealPath());
            }
        }
    }

    /**
     * Si el administrador ya VIO el PDF en el asistente con ESTA plantilla (mismo diseño y PDF base) y con ESTOS datos de la base, y después confirmó crearla,
     * la base nace con la vista previa revisada y confirmada: no se le pide la misma revisión dos veces. Si cambió algo (otra plantilla, diseño editado, otros
     * datos del certificado) o nunca abrió el PDF, la base la pide como siempre. Cambios posteriores de la plantilla la vuelven a exigir (ServicioVistaPrevia).
     *
     * @param  array<string,mixed>  $corr
     * @param  array<string,mixed>  $plan
     */
    private function trasladarVistaPrevia(Lote $lote, array $corr, array $plan): void
    {
        $vp = $corr['vista_previa'] ?? null;
        $plantilla = $plan['plantilla'] ?? null;
        if (! is_array($vp) || ! $plantilla instanceof Plantilla || (int) ($vp['plantilla_id'] ?? 0) !== $plantilla->id
            || ! hash_equals((string) ($vp['huella'] ?? ''), ServicioVistaPrevia::huella($plantilla))
            || ! hash_equals((string) ($vp['datos'] ?? ''), VistaPreviaImportacion::huellaDatos($plan['comunes']))) {
            return;
        }
        $lote->forceFill([
            'vista_previa_generada_at' => now(), 'vista_previa_confirmada_at' => now(),
            'vista_previa_plantilla_id' => $plantilla->id, 'vista_previa_huella' => ServicioVistaPrevia::huella($plantilla),
        ])->save();
        Movimiento::registrar(tipo: 'actualizacion', modulo: 'credential-flow', descripcion: 'La vista previa revisada en la importación inteligente quedó confirmada para la base', extra: ['lote_id' => $lote->id, 'plantilla_id' => $plantilla->id]);
    }

    /**
     * Incorpora los contactos QA a la base nueva DENTRO de la transacción: si algo falla o queda incompleto, no se crea la base (nunca se oculta detrás de un éxito).
     *
     * @throws ImportacionException
     */
    private function incorporarQa(Lote $lote, int $usuarioId): void
    {
        if (! config('credential_flow.qa.automatico', true) || ! ServicioQa::disponible()) {
            return;
        }
        try {
            $r = $this->qa->incorporar($lote, $usuarioId);
        } catch (Throwable $e) {
            Log::error('Credential Flow: no se pudieron incorporar los contactos QA en la importación inteligente', ['lote' => $lote->id, 'error' => LogSeguro::resumen($e)]);

            throw new ImportacionException(ImportacionException::QA_INCORPORACION_FALLIDA, 'No se pudieron incorporar los contactos QA a la base. No se creó la base ni ningún participante; inténtalo de nuevo.', 500);
        }
        // No basta con lo que dice el resultado: se cuenta lo que realmente quedó guardado en la base.
        $guardados = Participante::query()->where('lote_id', $lote->id)->where('es_control_qa', true)->count();
        if ($r['omitidos'] > 0 || $r['total'] !== $r['creados'] + $r['existentes'] || $guardados !== $r['total']) {
            throw new ImportacionException(ImportacionException::QA_INCORPORACION_FALLIDA, "Los contactos QA no quedaron completos en la base ({$guardados} de {$r['total']}). No se creó la base ni ningún participante.", 409);
        }
    }

    // ================================================================= rechazar / caducar / limpiar

    /** @throws ImportacionException */
    public function rechazar(Importacion $importacion): Importacion
    {
        $this->vigente($importacion);

        return DB::transaction(function () use ($importacion) {
            $actual = Importacion::query()->whereKey($importacion->id)->lockForUpdate()->firstOrFail();
            $this->exigirEnRevision($actual);
            $actual->update(['estado' => Importacion::RECHAZADO, 'clave_activa' => null, 'rechazado_at' => now(), 'analisis' => null, 'correcciones' => null, 'version' => $actual->version + 1]);
            Movimiento::registrar(tipo: 'actualizacion', modulo: 'credential-flow', descripcion: 'Se descartó un borrador de importación inteligente', extra: ['importacion_id' => $actual->id]);

            return $actual->fresh();
        });
    }

    /** Marca como vencido un borrador cuya vigencia terminó y borra sus datos personales. */
    private function vencer(Importacion $importacion): void
    {
        Importacion::query()->whereKey($importacion->id)->where('estado', Importacion::EN_REVISION)->update([
            'estado' => Importacion::EXPIRADO, 'clave_activa' => null, 'analisis' => null, 'correcciones' => null, 'updated_at' => now(),
        ]);
    }

    /**
     * Borra los datos personales de los borradores vencidos y elimina las filas (ya sin datos personales) de los terminados hace tiempo.
     *
     * @return array{vencidos: int, eliminados: int}
     */
    public function limpiar(): array
    {
        $vencidos = Importacion::query()->where('estado', Importacion::EN_REVISION)->where('expira_at', '<', now())->update([
            'estado' => Importacion::EXPIRADO, 'clave_activa' => null, 'analisis' => null, 'correcciones' => null, 'updated_at' => now(),
        ]);
        // Cualquier terminado que aún conserve datos personales los pierde.
        Importacion::query()->whereIn('estado', [Importacion::EXPIRADO, Importacion::RECHAZADO, Importacion::CONFIRMADO])
            ->where(fn ($q) => $q->whereNotNull('analisis')->orWhereNotNull('correcciones'))
            ->update(['analisis' => null, 'correcciones' => null]);
        $limite = now()->subDays(max(1, (int) config('credential_flow.importacion_inteligente.conservar_metadatos_dias', 30)));
        $eliminados = Importacion::query()->whereIn('estado', [Importacion::EXPIRADO, Importacion::RECHAZADO])->where('updated_at', '<', $limite)->delete();

        return ['vencidos' => $vencidos, 'eliminados' => $eliminados];
    }

    /** Si el borrador está en revisión pero ya venció, lo vence (y borra sus datos) y avisa. @throws ImportacionException */
    private function vigente(Importacion $importacion): void
    {
        $fresco = Importacion::query()->find($importacion->id);
        if ($fresco !== null && $fresco->enRevision() && $fresco->vencido()) {
            $this->vencer($fresco);

            throw new ImportacionException(ImportacionException::EXPIRADO, 'El borrador venció y sus datos se eliminaron. Vuelve a subir el Excel.', 410);
        }
    }

    /** @throws ImportacionException */
    private function exigirEnRevision(Importacion $importacion): void
    {
        if ($importacion->estado === Importacion::CONFIRMADO) {
            throw new ImportacionException(ImportacionException::YA_CONFIRMADO, 'Este borrador ya se confirmó y creó su base. No se puede importar dos veces.', 409);
        }
        if ($importacion->estado === Importacion::EXPIRADO) {
            throw new ImportacionException(ImportacionException::EXPIRADO, 'El borrador venció y sus datos se eliminaron. Vuelve a subir el Excel.', 410);
        }
        if ($importacion->estado !== Importacion::EN_REVISION || $importacion->vencido()) {
            throw new ImportacionException(ImportacionException::NO_EN_REVISION, 'El borrador ya no está en revisión.', 409);
        }
    }
}
