<?php

namespace App\Support\CredentialFlow\Busqueda;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Emision;
use App\Support\CredentialFlow\Emisiones\AlmacenEmisiones;
use App\Support\CredentialFlow\Emisiones\EmisionException;
use App\Support\CredentialFlow\Legado\CongeladorLegado;
use App\Support\CredentialFlow\Legado\PdfHistoricoInconsistente;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Descarga ADMINISTRATIVA desde el buscador por documento (E3). Cada búsqueda entrega un token opaco y temporal por cada certificado descargable; el token vive
 * solo en la SESIÓN del super-admin que buscó (nunca en la base de datos ni en la URL junto al documento), y una búsqueda nueva invalida los anteriores.
 *
 * Antes de servir se REVALIDA todo: el registro sigue perteneciendo al mismo número (HMAC de la búsqueda), sigue existiendo y sigue siendo descargable con las
 * reglas de ConsultaPorDocumento (oficial vigente, ni QA, ni revocado, ni reemplazado, ni duplicado, ni excluido, ni sin clasificación; histórico ya congelado).
 * El PDF se entrega solo si coincide con su SHA-256 registrado. NUNCA genera ni congela un PDF histórico (CongeladorLegado::verificar solo comprueba) y nunca
 * escribe en cf_descargas: la auditoría la registra quien llama, en Movimientos.
 */
final class DescargasBuscador
{
    public const SESION = 'credential_flow.buscador_documento.descargas';

    public const TOKEN_INVALIDO = 'TOKEN_INVALIDO';

    public const YA_NO_DISPONIBLE = 'YA_NO_DISPONIBLE';

    public const INTEGRIDAD = 'INTEGRIDAD';

    public function __construct(
        private readonly ConsultaPorDocumento $consulta,
        private readonly CongeladorLegado $congelador,
    ) {}

    public static function minutos(): int
    {
        return max(1, (int) config('credential_flow.buscador_documento.token_minutos', 15));
    }

    /** Olvida los tokens de la búsqueda anterior (cualquier búsqueda nueva, válida o no, los invalida). */
    public function olvidar(Session $sesion): void
    {
        $sesion->forget(self::SESION);
    }

    /**
     * Pone `descarga.token` (o null) en cada registro del resultado y guarda en la sesión SOLO los de esta búsqueda.
     *
     * @param  array<string,mixed>  $r  resultado válido de ConsultaPorDocumento::consultar
     * @return array<string,mixed>
     */
    public function emitirTokens(Session $sesion, int|string $usuario, string $huella, array $r): array
    {
        $tokens = [];
        $expira = now()->addMinutes(self::minutos())->getTimestamp();
        $asignar = function (array $c) use (&$tokens, $usuario, $huella, $expira): array {
            $c['descarga']['token'] = null;
            if (($c['descarga']['permitida'] ?? false) === true) {
                $t = Str::random(40);
                $tokens[$t] = ['origen' => $c['origen'], 'id' => (int) $c['id'], 'huella' => $huella, 'usuario' => $usuario, 'expira' => $expira];
                $c['descarga']['token'] = $t;
            }

            return $c;
        };
        $r['identidades'] = array_map(fn (array $i) => ['certificados' => array_map($asignar, $i['certificados'])] + $i, $r['identidades']);
        $r['excluidos'] = array_map($asignar, $r['excluidos']);
        $sesion->put(self::SESION, $tokens);

        return $r;
    }

    /** @return array{origen:string,id:int,huella:string,usuario:int|string,expira:int}|null el token si es de este usuario y no venció */
    public function resolver(Session $sesion, string $token, int|string $usuario): ?array
    {
        $t = (array) ($sesion->get(self::SESION)[$token] ?? []);
        if ($t === [] || (string) ($t['usuario'] ?? '') !== (string) $usuario || (int) ($t['expira'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        return $t;
    }

    /**
     * Revalida y lee el PDF. Lanza DescargaNoPermitida con el motivo si ya no se puede entregar.
     *
     * @param  array{origen:string,id:int,huella:string}  $t
     * @return array{bytes:string, sha256:string, codigo:?string, registro:string}
     */
    public function preparar(array $t): array
    {
        $registro = $t['origen'].':'.$t['id'];
        $clave = $this->claveDe($t['origen'], (int) $t['id']);
        if ($clave === null || ! hash_equals((string) $t['huella'], (string) ConsultaPorDocumento::huella($clave))) {
            throw new DescargaNoPermitida(self::YA_NO_DISPONIBLE);
        }
        $r = $this->consulta->consultar($clave);
        $c = collect($r['identidades'])->flatMap(fn ($i) => $i['certificados'])->first(fn ($c) => $c['origen'] === $t['origen'] && $c['id'] === (int) $t['id']);
        if ($c === null) {
            throw new DescargaNoPermitida(self::YA_NO_DISPONIBLE);
        }
        if (($c['descarga']['permitida'] ?? false) !== true) {
            throw new DescargaNoPermitida((string) ($c['descarga']['motivo'] ?? self::YA_NO_DISPONIBLE));
        }

        try {
            if ($t['origen'] === 'moderno') {
                $e = Emision::query()->findOrFail((int) $t['id']);
                $bytes = AlmacenEmisiones::leerVerificado($e);

                return ['bytes' => $bytes, 'sha256' => (string) $e->pdf_hash, 'codigo' => (string) $e->codigo, 'registro' => $registro];
            }
            $l = CertificadoLegado::query()->findOrFail((int) $t['id']);
            $archivo = $this->congelador->verificar($l);   // solo comprueba ruta, tamaño y SHA-256; nunca genera
            $bytes = (string) file_get_contents($this->congelador->rutaFisica($archivo));
            if (! hash_equals($archivo->sha256, hash('sha256', $bytes))) {
                throw new DescargaNoPermitida(self::INTEGRIDAD);
            }

            return ['bytes' => $bytes, 'sha256' => $archivo->sha256, 'codigo' => $c['codigo'] ?? null, 'registro' => $registro];
        } catch (EmisionException|PdfHistoricoInconsistente) {
            throw new DescargaNoPermitida(self::INTEGRIDAD);
        }
    }

    /** Clave de documento ACTUAL del registro (null si ya no existe o su participante fue eliminado). */
    private function claveDe(string $origen, int $id): ?string
    {
        $clave = $origen === 'moderno'
            ? DB::table('cf_emisiones as e')->join('cf_participantes as p', 'p.id', '=', 'e.participante_id')->where('e.id', $id)->whereNull('p.deleted_at')->value('p.documento_clave')
            : DB::table('cf_certificados_legado')->where('id', $id)->value('documento_clave');

        return is_string($clave) && $clave !== '' ? $clave : null;
    }
}
