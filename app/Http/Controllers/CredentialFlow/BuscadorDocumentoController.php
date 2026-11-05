<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Busqueda\ConsultaPorDocumento;
use App\Support\CredentialFlow\Busqueda\DescargaNoPermitida;
use App\Support\CredentialFlow\Busqueda\DescargasBuscador;
use App\Support\CredentialFlow\Busqueda\SeguimientoDescargas;
use App\Support\CredentialFlow\Historico\PresentadorHistorico;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Portal\FamiliaDocumento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Buscador ADMINISTRATIVO de certificados por número de documento (solo super-admin; interruptor, sesión, rol y límites en las rutas). La pantalla se abre por GET
 * SIN datos; la búsqueda es un POST JSON (con CSRF) cuyo documento viaja SOLO en el cuerpo: nunca en la URL, en el historial de Inertia ni en el registro de
 * acceso. Cada búsqueda autorizada deja un Movimiento con el HMAC del número (nunca la cédula), el tipo y los conteos.
 *
 * E3: cada certificado descargable recibe un token opaco y temporal (DescargasBuscador, solo en la sesión de quien buscó); la descarga revalida el estado,
 * comprueba el SHA-256 y deja un Movimiento «descarga» (nunca escribe en cf_descargas). Nunca genera ni congela un PDF histórico.
 */
class BuscadorDocumentoController extends Controller
{
    /** Tipos que el administrador puede elegir (clave del prefijo → etiqueta). */
    public const TIPOS = ['CC' => 'C.C.', 'TI' => 'T.I.', 'CE' => 'C.E.', 'PASAPORTE' => 'Pasaporte', 'NIT' => 'NIT', 'PEP' => 'PEP', 'PPT' => 'PPT'];

    public const MENSAJE_DOCUMENTO = 'Escribe el número de documento completo (mínimo :minimo caracteres, sin buscar por partes).';

    public const MENSAJE_NO_DISPONIBLE = 'Este certificado ya no está disponible para descargar desde el buscador. Vuelve a buscar el documento.';

    public const MENSAJE_INTEGRIDAD = 'El archivo de este certificado no pasó la verificación de integridad y no se entregó. Avisa al equipo técnico.';

    public function __construct(
        private readonly ConsultaPorDocumento $consulta,
        private readonly DescargasBuscador $descargas,
        private readonly SeguimientoDescargas $seguimiento,
    ) {}

    public function index(): Response
    {
        return Inertia::render('CredentialFlow/Buscador/Documento', [
            'tipos' => collect(self::TIPOS)->map(fn ($etiqueta, $valor) => ['valor' => $valor, 'etiqueta' => $etiqueta])->values(),
            'minimo' => self::minimo(),
            'limites' => ['minuto' => (int) config('credential_flow.buscador_documento.limite_por_minuto'), 'dia' => (int) config('credential_flow.buscador_documento.limite_por_dia')],
            'limitesDescarga' => ['minuto' => (int) config('credential_flow.buscador_documento.descarga_limite_por_minuto'), 'dia' => (int) config('credential_flow.buscador_documento.descarga_limite_por_dia'), 'minutosToken' => DescargasBuscador::minutos()],
        ]);
    }

    public function buscar(Request $request): JsonResponse
    {
        // Cualquier búsqueda nueva invalida los tokens de descarga de la anterior.
        $this->descargas->olvidar($request->session());
        // Solo el CUERPO del POST: un documento en la cadena de consulta se ignora (nunca se acepta desde la URL).
        $v = Validator::make($request->post(), [
            'documento' => ['required', 'string', 'max:40'],
            'tipo' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::TIPOS))],
        ], [
            'documento.*' => str_replace(':minimo', (string) self::minimo(), self::MENSAJE_DOCUMENTO),
            'tipo.*' => 'Elige un tipo de documento de la lista o «Cualquiera».',
        ]);
        if ($v->fails()) {
            return response()->json(['message' => $v->errors()->first(), 'errors' => $v->errors()], 422);
        }
        $documento = (string) $v->validated()['documento'];
        $tipo = $v->validated()['tipo'] ?? null;

        // Mínimo sobre el NÚMERO (sin prefijo de tipo ni separadores): «C.C. 12.345» cuenta 5. Búsqueda exacta: nunca por partes.
        if (mb_strlen(FamiliaDocumento::raiz(Texto::claveDocumento($documento))) < self::minimo()) {
            $this->auditar($request, ConsultaPorDocumento::huella($documento), $tipo, null, 'DOCUMENTO_CORTO');

            return response()->json(['message' => str_replace(':minimo', (string) self::minimo(), self::MENSAJE_DOCUMENTO)], 422);
        }

        $r = $this->consulta->consultar($documento, $tipo);
        $this->auditar($request, $r['documento_huella'] ?? ConsultaPorDocumento::huella($documento), $tipo, $r['valida'] ? $r['resumen'] : null, $r['motivo']);
        if (! $r['valida']) {
            return response()->json(['message' => str_replace(':minimo', (string) self::minimo(), self::MENSAJE_DOCUMENTO)], 422);
        }

        $r = $this->descargas->emitirTokens($request->session(), $request->user()->getAuthIdentifier(), (string) $r['documento_huella'], $this->seguimiento->agregar($r));

        return response()->json(['resultado' => self::presentar($r)]);
    }

    /** E3: entrega el PDF de un certificado de la última búsqueda, por su token. Revalida, verifica el SHA-256 y audita SIEMPRE (también lo que no se entrega). */
    public function descargar(Request $request, string $token): HttpResponse
    {
        // Laravel acepta HEAD en una ruta GET: HEAD no entrega el archivo, así que ni se sirve ni cuenta (ni se audita) como descarga.
        if ($request->isMethod('HEAD')) {
            return response('', 405, ['Allow' => 'GET', 'Cache-Control' => 'private, no-store']);
        }
        $t = $this->descargas->resolver($request->session(), $token, $request->user()->getAuthIdentifier());
        if ($t === null) {
            $this->auditarDescarga(null, DescargasBuscador::TOKEN_INVALIDO);

            return self::texto(self::MENSAJE_NO_DISPONIBLE, 404);
        }
        try {
            $a = $this->descargas->preparar($t);
        } catch (DescargaNoPermitida $e) {
            $this->auditarDescarga($t, $e->motivo);

            return self::texto($e->motivo === DescargasBuscador::INTEGRIDAD ? self::MENSAJE_INTEGRIDAD : self::MENSAJE_NO_DISPONIBLE, 409);
        }
        $this->auditarDescarga($t, 'ok', $a['sha256'], strlen($a['bytes']));
        $nombre = 'certificado-'.preg_replace('/[^A-Za-z0-9-]/', '', (string) ($a['codigo'] ?: $t['origen'].'-'.$t['id'])).'.pdf';

        return response($a['bytes'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$nombre.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Lo que ve la pantalla: sin la huella y con el NÚMERO de cada registro enmascarado (el administrador ya conoce el que buscó). Se enmascara solo el número, sin
     * prefijo ni separadores: el tipo va aparte (`tipo`), así «C.C. 1.234.567» y «1234567» se ven igual (`***4567`).
     */
    private static function presentar(array $r): array
    {
        $registro = fn (array $c) => ['documento' => PresentadorHistorico::documentoEnmascarado(FamiliaDocumento::raiz(Texto::claveDocumento((string) ($c['documento'] ?? ''))))] + $c;
        unset($r['documento_huella']);
        $r['identidades'] = array_map(fn ($i) => ['certificados' => array_map($registro, $i['certificados'])] + $i, $r['identidades']);
        $r['excluidos'] = array_map($registro, $r['excluidos']);

        return $r;
    }

    /** Un Movimiento por búsqueda autorizada: quién (user_id), cuándo (created_at), HMAC del número, tipo y conteos. Nunca la cédula ni nombres. */
    private function auditar(Request $request, ?string $huella, ?string $tipo, ?array $resumen, ?string $motivo): void
    {
        Movimiento::registrar(tipo: 'consulta', modulo: 'credential-flow', descripcion: 'Búsqueda administrativa de certificados por documento', extra: [
            'documento_huella' => $huella,
            'tipo_documento' => $tipo,
            'resultado' => $motivo ?? 'ok',
            'conteos' => $resumen === null ? null : array_intersect_key($resumen, array_flip(['certificados', 'historicos', 'modernos', 'vigentes', 'qa', 'eventos', 'identidades', 'excluidos', 'otros_tipos'])),
            'usuario_id' => $request->user()?->getAuthIdentifier(),
        ]);
    }

    /** Un Movimiento por intento de descarga: registro (origen:id), HMAC del número, SHA-256 y bytes entregados, resultado. Nunca la cédula ni nombres. */
    private function auditarDescarga(?array $t, string $resultado, ?string $sha256 = null, ?int $bytes = null): void
    {
        Movimiento::registrar(tipo: 'descarga', modulo: 'credential-flow', descripcion: SeguimientoDescargas::DESCRIPCION_PANEL, extra: [
            'registro' => $t === null ? null : $t['origen'].':'.$t['id'],
            'origen' => $t['origen'] ?? null,
            'emision_id' => ($t['origen'] ?? null) === 'moderno' ? $t['id'] : null,
            'certificado_legado_id' => ($t['origen'] ?? null) === 'historico' ? $t['id'] : null,
            'documento_huella' => $t['huella'] ?? null,
            'resultado' => $resultado,
            'sha256' => $sha256,
            'bytes' => $bytes,
        ]);
    }

    private static function texto(string $mensaje, int $estado): HttpResponse
    {
        return response($mensaje, $estado, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private static function minimo(): int
    {
        return max(4, (int) config('credential_flow.buscador_documento.minimo_caracteres', 6));
    }
}
