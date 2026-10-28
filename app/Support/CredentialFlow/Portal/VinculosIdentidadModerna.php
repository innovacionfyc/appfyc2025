<?php

namespace App\Support\CredentialFlow\Portal;

use App\Models\Movimiento;
use App\Support\CredentialFlow\Participantes\Texto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Vínculo EXPLÍCITO «grupo moderno → grupo histórico» de una misma persona (mismo documento) cuya grafía de nombre difiere (p. ej. un nombre histórico
 * mal escrito). Es la ÚNICA forma de que un certificado moderno se muestre dentro del grupo histórico de otra grafía: nunca hay fusión por parecido, ni
 * por documento, ni por correo solos.
 *
 * Qué exige crearlo (todo verificable en el sistema, sin datos personales en el registro):
 *  - los dos grupos existen para ese documento y son distintos;
 *  - el correo autorizado está registrado en AMBOS grupos (en un certificado histórico y en un participante moderno con emisión vigente);
 *  - justificación humana (motivo) y actor.
 * Efecto: SOLO sobre las filas modernas (su grupo se lee como el histórico); las filas históricas no cambian jamás. Una sesión ya abierta antes de crear el
 * vínculo no lo hereda (se compara con el momento en que se autenticó). Revocarlo devuelve cada grupo a su sitio. Sin la tabla, el efecto es nulo.
 */
final class VinculosIdentidadModerna
{
    public const TABLA = 'cf_vinculos_identidad';

    public const MOTIVO_MIN = 20;

    public static function disponible(): bool
    {
        return Schema::hasTable(self::TABLA);
    }

    /** Por la RAÍZ del número (FamiliaDocumento): el vínculo vale para el número con o sin prefijo de tipo. */
    public static function hashDocumento(string $clave): string
    {
        return Hmac::de('vinculo_documento', FamiliaDocumento::raiz($clave));
    }

    public static function hashCorreo(string $correoNormalizado): string
    {
        return Hmac::de('vinculo_correo', $correoNormalizado);
    }

    /**
     * Mapa grupo_moderno_hash → grupo_historico_hash de los vínculos VIGENTES del documento. Con `$desde` (instante en que se autenticó la sesión) solo cuentan
     * los vínculos creados antes de ese instante: una sesión anterior no hereda permisos nuevos.
     *
     * @return array<string,string>
     */
    public static function mapa(string $clave, ?int $desde = null): array
    {
        if (! self::disponible()) {
            return [];
        }
        $q = DB::table(self::TABLA)->where('documento_hash', self::hashDocumento($clave))->where('estado', 'vigente');
        if ($desde !== null) {
            $q->where('created_at', '<=', Carbon::createFromTimestamp($desde, config('app.timezone'))->toDateTimeString());
        }

        return $q->pluck('grupo_historico_hash', 'grupo_moderno_hash')->all();
    }

    /**
     * Valida la evidencia SIN escribir. @return array{ok:bool,errores:list<string>,datos:array<string,string>}
     */
    public function evaluar(string $documento, string $correo, string $nombreHistorico, string $nombreModerno, string $motivo): array
    {
        $errores = [];
        $clave = AccesoPortal::claveDocumento($documento);
        $mail = AccesoPortal::correoNormalizado($correo);
        $gH = NombreConservador::grupoId($nombreHistorico);
        $gM = NombreConservador::grupoId($nombreModerno);
        if ($clave === null) {
            $errores[] = 'Documento no válido.';
        }
        if ($mail === null) {
            $errores[] = 'Correo no válido.';
        }
        if ($gH === $gM) {
            $errores[] = 'Los dos nombres son el mismo grupo: no hace falta vínculo.';
        }
        if (mb_strlen(trim($motivo)) < self::MOTIVO_MIN) {
            $errores[] = 'El motivo debe explicar el caso (mínimo '.self::MOTIVO_MIN.' caracteres).';
        }
        if ($errores === []) {
            $acceso = new AccesoPortal;
            $d = $acceso->cargar($clave);
            $historicas = $d['filas']->filter(fn ($f) => $d['grupos'][$f->id] === $gH);
            if ($historicas->isEmpty()) {
                $errores[] = 'No hay certificados históricos de ese documento con el nombre histórico indicado.';
            } elseif (! $historicas->contains(fn ($f) => isset($d['correos'][$f->id][$mail]))) {
                $errores[] = 'El correo no está registrado en el grupo histórico.';
            }
            $modernas = EmisionesModernas::filas($clave)->filter(fn ($f) => $f->grupo === $gM);
            if ($modernas->isEmpty()) {
                $errores[] = 'No hay certificados modernos de ese documento con el nombre moderno indicado (o ya está vinculado).';
            } elseif (! $modernas->contains(fn ($f) => isset($f->correos[$mail]) && EmisionesModernas::habilita($f))) {
                $errores[] = 'El correo no está registrado en el grupo moderno con una emisión vigente.';
            }
            if (self::disponible() && DB::table(self::TABLA)->where('documento_hash', self::hashDocumento($clave))->where('grupo_moderno_hash', $gM)->where('estado', 'vigente')->exists()) {
                $errores[] = 'Ya existe un vínculo vigente para ese grupo moderno.';
            }
        }

        return ['ok' => $errores === [], 'errores' => $errores, 'datos' => ['clave' => (string) $clave, 'correo' => (string) $mail, 'gH' => $gH, 'gM' => $gM]];
    }

    /** @throws InvalidArgumentException si la evidencia no se verifica */
    public function crear(string $documento, string $correo, string $nombreHistorico, string $nombreModerno, string $motivo, int $usuarioId): int
    {
        if (! self::disponible()) {
            throw new InvalidArgumentException('La tabla de vínculos de identidad no existe todavía (falta ejecutar su migración).');
        }
        $e = $this->evaluar($documento, $correo, $nombreHistorico, $nombreModerno, $motivo);
        if (! $e['ok']) {
            throw new InvalidArgumentException(implode(' ', $e['errores']));
        }
        $d = $e['datos'];
        $dh = self::hashDocumento($d['clave']);

        $id = (int) DB::table(self::TABLA)->insertGetId([
            'documento_hash' => $dh, 'grupo_moderno_hash' => $d['gM'], 'grupo_historico_hash' => $d['gH'], 'correo_hash' => self::hashCorreo($d['correo']),
            'estado' => 'vigente', 'motivo' => Texto::limpiar($motivo), 'creado_por' => $usuarioId, 'vigente_clave' => hash('sha256', $dh.'|'.$d['gM']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Movimiento::registrar(tipo: 'registro', modulo: 'credential-flow', descripcion: 'Se vinculó un grupo de nombre moderno con su grupo histórico (misma persona)', extra: ['vinculo_id' => $id]);

        return $id;
    }

    public function revocar(int $id, string $motivo, int $usuarioId): void
    {
        $f = DB::table(self::TABLA)->where('id', $id)->where('estado', 'vigente')->first();
        if ($f === null) {
            throw new InvalidArgumentException('No existe un vínculo vigente con ese id.');
        }
        if (mb_strlen(trim($motivo)) < self::MOTIVO_MIN) {
            throw new InvalidArgumentException('El motivo debe explicar el caso (mínimo '.self::MOTIVO_MIN.' caracteres).');
        }
        DB::table(self::TABLA)->where('id', $id)->update(['estado' => 'revocado', 'vigente_clave' => null, 'revocado_por' => $usuarioId, 'revocado_at' => now(), 'motivo_revocacion' => Texto::limpiar($motivo), 'updated_at' => now()]);
        Movimiento::registrar(tipo: 'actualizacion', modulo: 'credential-flow', descripcion: 'Se revocó un vínculo de identidad moderna', extra: ['vinculo_id' => $id]);
    }
}
