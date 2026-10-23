<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CabecerasPortal;
use App\Http\Middleware\CabecerasVerificacionPublica;
use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Descarga;
use App\Models\CredentialFlow\Emision;
use App\Support\CredentialFlow\Emisiones\AlmacenEmisiones;
use App\Support\CredentialFlow\Emisiones\EmisionException;
use App\Support\CredentialFlow\Encuestas\EncuestaPortal;
use App\Support\CredentialFlow\Legado\CodigoHistoricoException;
use App\Support\CredentialFlow\Legado\CongeladoNoPermitido;
use App\Support\CredentialFlow\Legado\CongeladorLegado;
use App\Support\CredentialFlow\Legado\ElegibilidadLegado;
use App\Support\CredentialFlow\Legado\PdfHistoricoInconsistente;
use App\Support\CredentialFlow\Legado\RenderNoPermitido;
use App\Support\CredentialFlow\Portal\AccesoPortal;
use App\Support\CredentialFlow\Portal\EmisionesModernas;
use App\Support\CredentialFlow\Portal\Hmac;
use App\Support\CredentialFlow\Portal\ServicioOtp;
use App\Support\CredentialFlow\Portal\SesionPortal;
use App\Support\CredentialFlow\Reemplazo\CertificadoLogico;
use App\Support\CredentialFlow\Reemplazo\EmisionVigente;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal PÚBLICO de certificados históricos: documento + correo → código de un solo uso (OTP) → panel con los certificados del
 * documento verificado → descarga del PDF histórico (lazy freeze). Respuestas uniformes: nada distingue un documento inexistente, un
 * correo incorrecto, un documento en revisión o un caso válido. Sin usuarios de Laravel ni permisos administrativos.
 */
class PortalPublicoController extends Controller
{
    public const MENSAJE_UNIFORME = 'Si los datos coinciden con los registros, se envió un código de acceso al correo asociado. Revisar la bandeja de entrada y, si no aparece, verificar también Spam o Correo no deseado.';

    public const MENSAJE_CODIGO = 'El código no es válido o ya expiró.';

    private const MAX_FALLOS_SESION = 10;

    public function __construct(private readonly ServicioOtp $otp, private readonly AccesoPortal $acceso, private readonly EncuestaPortal $encuesta) {}

    public function inicio(Request $request): Response
    {
        if (SesionPortal::documento($request->session()) !== null) {
            return redirect()->route('portal.panel');
        }

        return $this->vista($request, 'inicio');
    }

    /** Guía paso a paso para descargar el certificado. No lee ni crea sesión: todo lo que muestra es ficticio y vale para cualquier persona. */
    public function guia(Request $request): Response
    {
        return $this->vista($request, 'guia', ['sinSesion' => true, 'direccion' => preg_replace('#^https?://#i', '', route('portal.inicio'))]);
    }

    public function solicitar(Request $request): RedirectResponse
    {
        $d = $request->validate(['documento' => ['required', 'string', 'max:60'], 'correo' => ['required', 'string', 'max:254']], [
            'documento.required' => 'El número de documento es obligatorio.', 'correo.required' => 'El correo electrónico es obligatorio.', '*.max' => 'El dato es demasiado largo.',
        ]);

        $this->otp->solicitar($d['documento'], $d['correo'], $request->ip(), $request->userAgent());
        // Siempre se guarda lo digitado (normalizado) y se responde igual: la sesión no revela si hubo coincidencia.
        $request->session()->put(SesionPortal::PENDIENTE, ['documento' => AccesoPortal::claveDocumento($d['documento']) ?? '', 'correo' => AccesoPortal::correoNormalizado($d['correo']) ?? '', 'fallos' => 0]);

        return redirect()->route('portal.codigo')->with('aviso', self::MENSAJE_UNIFORME);
    }

    public function codigo(Request $request): Response
    {
        if (! $request->session()->has(SesionPortal::PENDIENTE)) {
            return redirect()->route('portal.inicio');
        }

        return $this->vista($request, 'codigo');
    }

    public function reenviar(Request $request): RedirectResponse
    {
        $p = $request->session()->get(SesionPortal::PENDIENTE);
        if (! is_array($p)) {
            return redirect()->route('portal.inicio');
        }
        // Mismas reglas (60 s, 3/hora, 8/día, bloqueo) que la primera solicitud, y la misma respuesta.
        $this->otp->solicitar((string) $p['documento'], (string) $p['correo'], $request->ip(), $request->userAgent());

        return redirect()->route('portal.codigo')->with('aviso', self::MENSAJE_UNIFORME);
    }

    public function validar(Request $request): RedirectResponse
    {
        $p = $request->session()->get(SesionPortal::PENDIENTE);
        if (! is_array($p)) {
            return redirect()->route('portal.inicio');
        }
        $codigo = preg_replace('/\s+/', '', (string) $request->input('codigo', ''));

        $alcance = $p['documento'] !== '' && $p['correo'] !== '' ? $this->otp->validar((string) $p['documento'], (string) $p['correo'], (string) $codigo) : null;
        // Un OTP con scope APROBADO (10B-3B-2) solo abre una sesión multi-grupo si el scope ya revalidado es aplicable (no masivo ⇒ 3C), los dos interruptores están
        // encendidos y las decisiones siguen vigentes. Cualquier otra cosa falla exactamente como un código inválido, sin revelar nada.
        if ($alcance !== null && ($alcance['scope'] ?? null) !== null) {
            if (SesionPortal::iniciarAprobado($request->session(), (string) $p['documento'], $alcance['scope'])) {
                return redirect()->route('portal.panel');
            }
            Log::warning('Credential Flow: OTP con scope aprobado que no puede abrir sesión.', ['motivo' => $alcance['scope']->masivo ? 'masivo_sin_aprobacion_o_interruptor' : 'interruptores_o_decisiones']);
            $alcance = null;
        }
        if ($alcance !== null) {
            SesionPortal::iniciar($request->session(), (string) $p['documento'], $alcance['grupo']);

            return redirect()->route('portal.panel');
        }

        $p['fallos'] = (int) ($p['fallos'] ?? 0) + 1;
        if ($p['fallos'] >= self::MAX_FALLOS_SESION) {
            $request->session()->forget(SesionPortal::PENDIENTE);

            return redirect()->route('portal.inicio')->with('aviso', self::MENSAJE_CODIGO);
        }
        $request->session()->put(SesionPortal::PENDIENTE, $p);

        return redirect()->route('portal.codigo')->with('error', self::MENSAJE_CODIGO);
    }

    public function panel(Request $request): Response
    {
        // Los parámetros de listado (q, anio, orden, page) solo filtran dentro del alcance ya autorizado; cualquier valor raro se ignora sin error.
        $listado = $this->acceso->tarjetasPaginadas(
            (string) $request->attributes->get('cf_portal_documento'),
            $request->attributes->get('cf_portal_grupos'),
            [
                'q' => is_string($request->query('q')) ? $request->query('q') : '',
                'anio' => is_scalar($request->query('anio')) ? (string) $request->query('anio') : null,
                'orden' => is_string($request->query('orden')) ? $request->query('orden') : null,
                'page' => is_scalar($request->query('page')) ? (int) $request->query('page') : 1,
            ],
            $request->attributes->get('cf_portal_desde')
        );

        // Estado de la encuesta opcional por certificado de la página (solo lectura; no condiciona ninguna descarga).
        return $this->vista($request, 'panel', ['tarjetas' => $listado['items'], 'listado' => $listado, 'encuestas' => $this->encuesta->estados(array_values(array_filter($listado['items'], fn ($t) => empty($t['moderno']))), $request->session())]);
    }

    public function descargar(Request $request, int $certificado, CongeladorLegado $congelador): Response
    {
        $clave = (string) $request->attributes->get('cf_portal_documento');
        $grupo = $request->attributes->get('cf_portal_grupos');   // null = documento completo (histórico) · lista = scope; nunca una lista vacía
        $c = CertificadoLegado::query()->find($certificado);
        // Nunca por id solo: debe pertenecer al documento Y al grupo de la sesión. Cualquier otro caso es un 404 igual al de «no existe».
        if ($c === null || ! AccesoPortal::perteneceA($c, $clave, $grupo)) {
            abort(404);
        }
        $canonico = ElegibilidadLegado::canonico($c);
        // Si resolver al canónico sacara del alcance, no se hace en silencio: se trata como no disponible.
        if (! AccesoPortal::enAlcance($canonico, $clave, $grupo)) {
            return redirect()->route('portal.panel')->with('error', 'Este certificado no está disponible para descargar. Contactar a soporte para recibir ayuda.');
        }
        // Certificado lógico (10B-2B-2C.1): si OTRA fila de su grupo de duplicados lleva el reemplazo, ese es el certificado que se entrega (una sola emisión
        // vigente por certificado lógico, sea cual sea el id histórico autorizado con el que se llegue). Debe estar dentro del alcance.
        if ($canonico->estado !== CertificadoLegado::ESTADO_REEMPLAZADO && $canonico->reemplazado_por_emision_id === null) {
            $logica = CertificadoLogico::reemplazada((int) $canonico->id);
            if ($logica !== null) {
                $canonico = CertificadoLegado::query()->find($logica->id);
                if (! AccesoPortal::enAlcance($canonico, $clave, $grupo)) {
                    return redirect()->route('portal.panel')->with('error', 'Este certificado no está disponible para descargar. Contactar a soporte para recibir ayuda.');
                }
            }
        }
        // Histórico REEMPLAZADO (10B-2B-2C): el histórico sigue siendo la raíz de la autorización (ya validada arriba); lo que se entrega es la emisión
        // moderna VIGENTE de su cadena. Jamás el PDF histórico obsoleto ni un fallback silencioso.
        if ($canonico->estado === CertificadoLegado::ESTADO_REEMPLAZADO || $canonico->reemplazado_por_emision_id !== null) {
            return $this->descargarReemplazo($request, $canonico);
        }
        if (ElegibilidadLegado::motivo($canonico) !== null) {
            return redirect()->route('portal.panel')->with('error', 'Este certificado no está disponible para descargar. Contactar a soporte para recibir ayuda.');
        }

        try {
            $archivo = $congelador->servir($canonico);
        } catch (CongeladoNoPermitido) {
            return redirect()->route('portal.panel')->with('error', 'Este certificado no está disponible para descargar. Contactar a soporte para recibir ayuda.');
        } catch (PdfHistoricoInconsistente|RenderNoPermitido|CodigoHistoricoException) {
            return redirect()->route('portal.panel')->with('error', 'No fue posible entregar el certificado en este momento. Contactar a soporte para recibir ayuda.');
        }

        // Una descarga NUEVA (origen credential_flow): no se confunde con las importadas del sistema viejo.
        Descarga::create([
            'certificado_legado_id' => $canonico->id, 'via' => Descarga::VIA_PORTAL, 'origen' => Descarga::ORIGEN_CREDENTIAL_FLOW,
            'descargado_at' => now(), 'ip_hash' => Hmac::ip($request->ip()),
        ]);

        $respuesta = new BinaryFileResponse($congelador->rutaFisica($archivo), 200, ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff'], false);
        $respuesta->setContentDisposition('attachment', 'certificado.pdf');

        return $respuesta;
    }

    /**
     * Descarga de un certificado MODERNO (emisión oficial de un participante nativo). Se autoriza SOLO por documento + grupo de la sesión: la emisión debe
     * pertenecer a un participante de ese documento dentro del alcance; conocer su id (o su código) no basta. Se entrega el PDF ya emitido y verificado
     * (existencia, bytes y SHA-256); nunca se genera ni se reemite nada. Una emisión revocada no se entrega.
     */
    public function descargarModerno(Request $request, int $emision): Response|RedirectResponse
    {
        $fila = $this->acceso->emisionModerna((string) $request->attributes->get('cf_portal_documento'), $request->attributes->get('cf_portal_grupos'), $emision, $request->attributes->get('cf_portal_desde'));
        if ($fila === null) {
            abort(404);   // ajeno, inexistente o fuera del alcance: igual que «no existe»
        }
        $panel = fn (string $mensaje) => redirect()->route('portal.panel')->with('error', $mensaje);
        if (! EmisionesModernas::habilita($fila)) {
            return $panel('Este certificado fue revocado y no está disponible para descargar.');
        }

        $modelo = Emision::query()->find($emision);
        try {
            $ruta = $modelo === null ? throw new EmisionException(EmisionException::ARCHIVO_EMISION_NO_EXISTE, '', 409) : AlmacenEmisiones::rutaFisicaVerificada($modelo);
        } catch (EmisionException $e) {
            Log::error('Credential Flow: el PDF de una emisión moderna no está disponible o no es íntegro.', ['emision' => $emision, 'codigo' => $e->codigo]);

            return $panel('No fue posible entregar el certificado en este momento. Contactar a soporte para recibir ayuda.');
        }

        Descarga::create([
            'emision_id' => $modelo->id, 'via' => Descarga::VIA_PORTAL, 'origen' => Descarga::ORIGEN_CREDENTIAL_FLOW,
            'descargado_at' => now(), 'ip_hash' => Hmac::ip($request->ip()),
        ]);

        $respuesta = new BinaryFileResponse($ruta, 200, ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff'], false);
        $respuesta->setContentDisposition('attachment', 'certificado.pdf');

        return $respuesta;
    }

    /**
     * Sirve el PDF de la emisión moderna VIGENTE que reemplazó al histórico autorizado. El portal NO genera nada: el PDF ya existe y se verifica
     * (existencia, bytes y SHA-256) antes de entregarlo. La emisión se obtiene SOLO desde el histórico de la sesión (nunca por id de emisión, código
     * ni documento corregido). Ante revocación, anomalía de la cadena o PDF dañado: mensaje genérico, log técnico sin datos personales y NINGUNA descarga.
     */
    private function descargarReemplazo(Request $request, CertificadoLegado $historico): Response|RedirectResponse
    {
        $panel = fn (string $mensaje) => redirect()->route('portal.panel')->with('error', $mensaje);
        $revision = 'Este certificado necesita revisión.';
        $cadena = EmisionVigente::paraCertificado($historico);

        if ($cadena === null || $cadena['estado'] === EmisionVigente::ESTADO_ANOMALIA) {
            Log::warning('Credential Flow: cadena de reemplazo inutilizable en el portal.', ['certificado' => $historico->id, 'anomalia' => $cadena['anomalia'] ?? 'sin_emision']);

            return $panel($revision);
        }
        if ($cadena['estado'] === EmisionVigente::ESTADO_REVOCADA) {
            return $panel('Este certificado fue revocado y no está disponible para descargar.');
        }

        $emision = Emision::query()->find($cadena['vigente']->id);
        try {
            $ruta = $emision === null ? throw new EmisionException(EmisionException::ARCHIVO_EMISION_NO_EXISTE, '', 409) : AlmacenEmisiones::rutaFisicaVerificada($emision);
        } catch (EmisionException $e) {
            Log::error('Credential Flow: el PDF de una emisión de reemplazo no está disponible o no es íntegro.', ['certificado' => $historico->id, 'emision' => $cadena['vigente']->id, 'codigo' => $e->codigo]);

            return $panel($revision);
        }

        // Solo tras verificar el PDF: una descarga NUEVA contra la emisión moderna (nunca contra el histórico).
        Descarga::create([
            'emision_id' => $emision->id, 'via' => Descarga::VIA_PORTAL, 'origen' => Descarga::ORIGEN_CREDENTIAL_FLOW,
            'descargado_at' => now(), 'ip_hash' => Hmac::ip($request->ip()),
        ]);

        $respuesta = new BinaryFileResponse($ruta, 200, ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff'], false);
        $respuesta->setContentDisposition('attachment', 'certificado.pdf');

        return $respuesta;
    }

    public function salir(Request $request): RedirectResponse
    {
        SesionPortal::cerrar($request->session());

        return redirect()->route('portal.inicio')->with('aviso', 'Sesión cerrada.');
    }

    /** 429 amable (límites por IP). Misma estructura que el resto del portal; no revela nada sobre los datos. */
    public static function limitada(Request $request, int $segundos, array $cabeceras = []): Response
    {
        $nonce = CabecerasVerificacionPublica::nonce($request);

        return CabecerasPortal::aplicar(response()->view('credential-flow.portal.limitada', ['nonce' => $nonce, 'entidad' => config('credential_flow.verificacion.entidad'), 'contacto' => config('credential_flow.portal.contacto_url')], 429, ['Retry-After' => (string) max(1, $segundos)] + $cabeceras), $nonce);
    }

    private function vista(Request $request, string $nombre, array $datos = []): Response
    {
        return response()->view('credential-flow.portal.'.$nombre, $datos + [
            'nonce' => CabecerasVerificacionPublica::nonce($request),
            'entidad' => config('credential_flow.verificacion.entidad'),
            'contacto' => config('credential_flow.portal.contacto_url'),
        ]);
    }
}
