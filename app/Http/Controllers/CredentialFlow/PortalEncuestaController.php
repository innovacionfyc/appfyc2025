<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CabecerasVerificacionPublica;
use App\Support\CredentialFlow\Encuestas\EncuestaPortal;
use App\Support\CredentialFlow\Encuestas\RespuestaInvalida;
use App\Support\CredentialFlow\Encuestas\ServicioEncuestas;
use App\Support\CredentialFlow\Portal\AccesoPortal;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Encuesta OPCIONAL del portal público: formulario, guardado y «Más tarde». Nunca bloquea una descarga ni redirige a la fuerza.
 *
 * La pertenencia sale SOLO de la sesión (documento + grupos) y de las tarjetas que `AccesoPortal` ya autoriza: un certificado ajeno, no
 * descargable o inexistente es un 404 genérico. La definición, la validación y el guardado son del motor `ServicioEncuestas`.
 */
class PortalEncuestaController extends Controller
{
    public const MENSAJE_GRACIAS = 'Gracias por compartir la experiencia. La información recibida contribuye a mejorar nuestros eventos y servicios.';

    public const MENSAJE_RESPONDIDA = 'La encuesta de este evento ya fue respondida.';

    public const MENSAJE_NO_DISPONIBLE = 'La encuesta no está disponible en este momento.';

    public function __construct(private readonly AccesoPortal $acceso, private readonly EncuestaPortal $encuesta, private readonly ServicioEncuestas $motor) {}

    public function mostrar(Request $request, int $certificado): Response|RedirectResponse
    {
        $contexto = $this->contexto($request, $certificado);
        $version = $this->encuesta->version();
        if ($version === null) {
            return redirect()->route('portal.panel')->with('aviso', self::MENSAJE_NO_DISPONIBLE);
        }
        if ($this->encuesta->respondida($certificado)) {
            return redirect()->route('portal.panel')->with('aviso', self::MENSAJE_RESPONDIDA);
        }

        return $this->vista($request, [
            'definicion' => $this->motor->definicion($version), 'tarjeta' => $contexto['tarjeta'], 'respuestas' => $this->respuestasPrevias($request),
            'errores' => (array) $request->session()->get('errores_encuesta', []),
        ]);
    }

    public function guardar(Request $request, int $certificado): RedirectResponse
    {
        $contexto = $this->contexto($request, $certificado);
        $version = $this->encuesta->version();
        if ($version === null) {
            return redirect()->route('portal.panel')->with('aviso', self::MENSAJE_NO_DISPONIBLE);
        }
        $volver = fn (array $errores) => redirect()->route('portal.encuesta', $certificado)->withInput($this->respuestasPrevias($request, true))->with('errores_encuesta', $errores);

        // Sin campos desconocidos: solo el token y el arreglo `r` (clave de pregunta → valor).
        $enviados = $request->except(['_token']);
        if (array_diff(array_keys($enviados), ['r']) !== [] || ! is_array($enviados['r'] ?? [])) {
            return $volver(['_' => 'Los datos enviados no son válidos.']);
        }
        $respuestas = (array) ($enviados['r'] ?? []);

        $bloqueo = Cache::lock('cf-portal-encuesta:'.$this->encuesta->claveLogica($certificado), 10);
        try {
            $bloqueo->block(3);
            try {
                // Continuidad de la encuesta histórica: cualquier respuesta ya vinculada al certificado (de cualquier versión) lo da por respondido.
                if ($this->encuesta->respondida($certificado)) {
                    return redirect()->route('portal.panel')->with('aviso', self::MENSAJE_RESPONDIDA);
                }
                $this->motor->guardar($version, $respuestas, [
                    'evento_id' => $contexto['evento_id'], 'certificado_legado_id' => $certificado, 'documento' => (string) $request->attributes->get('cf_portal_documento'),
                ]);
            } finally {
                $bloqueo->release();
            }
        } catch (LockTimeoutException) {
            return $volver(['_' => 'La solicitud está en proceso. Intentar nuevamente en unos segundos.']);
        } catch (RespuestaInvalida $e) {
            return match ($e->codigo) {
                RespuestaInvalida::DUPLICADA => redirect()->route('portal.panel')->with('aviso', self::MENSAJE_RESPONDIDA),
                RespuestaInvalida::VERSION_NO_VIGENTE => redirect()->route('portal.panel')->with('aviso', self::MENSAJE_NO_DISPONIBLE),
                default => $volver($e->errores),
            };
        }

        return redirect()->route('portal.panel')->with('exito', self::MENSAJE_GRACIAS);
    }

    /** «Más tarde»: solo en la sesión actual; no escribe nada en la base de datos. */
    public function masTarde(Request $request, int $certificado): RedirectResponse
    {
        $this->contexto($request, $certificado);
        $this->encuesta->posponer($request->session(), $certificado);

        return redirect()->to($this->destino($request));
    }

    /**
     * Certificado elegible dentro del alcance de la sesión o 404 genérico (ajeno, no descargable, sin evento o inexistente).
     *
     * @return array{tarjeta:array<string,mixed>,evento_id:int}
     */
    private function contexto(Request $request, int $certificado): array
    {
        $tarjetas = $this->acceso->tarjetas((string) $request->attributes->get('cf_portal_documento'), $request->attributes->get('cf_portal_grupos'));

        return $this->encuesta->contexto($certificado, $tarjetas) ?? abort(404);
    }

    /** Valores previos (old input) del formulario: solo escalares/listas de escalares bajo `r`, para repintar tras un error. */
    private function respuestasPrevias(Request $request, bool $envio = false): array
    {
        $r = $envio ? $request->input('r') : old('r');
        if (! is_array($r)) {
            return [];
        }

        return $envio ? ['r' => $r] : $r;
    }

    /** Vuelve al listado (conservando búsqueda/página) solo si el origen es el panel propio; si no, al panel. */
    private function destino(Request $request): string
    {
        $panel = route('portal.panel');
        $ref = (string) $request->headers->get('referer', '');
        $p = parse_url($ref);
        $propio = parse_url($panel);
        if (is_array($p) && ($p['host'] ?? null) === ($propio['host'] ?? null) && ($p['path'] ?? null) === ($propio['path'] ?? null)) {
            return $panel.(isset($p['query']) ? '?'.$p['query'] : '');
        }

        return $panel;
    }

    private function vista(Request $request, array $datos): Response
    {
        return response()->view('credential-flow.portal.encuesta', $datos + [
            'nonce' => CabecerasVerificacionPublica::nonce($request),
            'entidad' => config('credential_flow.verificacion.entidad'),
            'contacto' => config('credential_flow.portal.contacto_url'),
        ]);
    }
}
