<?php

namespace App\Traits;

use Core\Models\Auditoria;
use App\Models\User;


trait RegistraAuditoria
{
    public function registrarAuditoria($accion, $modelo, $modelo_id, $comentario, $cambios = [])
    {
        $userAgent = request()->header('User-Agent');
        $plataforma = null;

        if (stripos($userAgent, 'Windows') !== false) {
            $plataforma = 'Windows';
        } elseif (stripos($userAgent, 'Mac') !== false) {
            $plataforma = 'Mac';
        } elseif (stripos($userAgent, 'Linux') !== false) {
            $plataforma = 'Linux';
        } elseif (stripos($userAgent, 'Android') !== false) {
            $plataforma = 'Android';
        } elseif (stripos($userAgent, 'iPhone') !== false) {
            $plataforma = 'iOS';
        }


        $user = User::where('numero_documento_ct', auth()->id())->first();

        $user = auth()->user();


        Auditoria::create([
            'user_id' => $user->id,
            'modelo' => $modelo,
            'modelo_id' => $modelo_id,
            'accion' => $accion,
            'comentario' => $comentario,
            'ip' => request()->ip(),
            'url' => request()->fullUrl(),
            'navegador' => request()->header('User-Agent'),
            'plataforma' => $plataforma,
            'cambios' => !empty($cambios) ? json_encode($cambios) : null,
            'created_at' => now(),
        ]);
    }
}
