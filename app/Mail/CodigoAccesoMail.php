<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Correo con el código de un solo uso del portal de certificados. Sin adjuntos, sin lista de certificados y sin documento. Lleva versión
 * HTML y versión en texto plano; remitente y Reply-To salen de `config/credential_flow.php` (sección `correo`).
 */
class CodigoAccesoMail extends Mailable
{
    public function __construct(public readonly string $codigo) {}

    public function envelope(): Envelope
    {
        $cfg = config('credential_flow.correo');
        $desde = ($cfg['remitente']['direccion'] ?? null) ? new Address($cfg['remitente']['direccion'], (string) $cfg['remitente']['nombre']) : null;
        $respuesta = ($cfg['reply_to']['direccion'] ?? null) ? [new Address($cfg['reply_to']['direccion'], (string) $cfg['reply_to']['nombre'])] : [];

        return new Envelope(from: $desde, replyTo: $respuesta, subject: 'Código para consultar tus certificados');
    }

    public function content(): Content
    {
        $plantilla = config('credential_flow.correo.plantillas.otp_acceso');

        return new Content(view: $plantilla['html'], text: $plantilla['texto'], with: [
            'codigo' => $this->codigo,
            'vigencia' => (int) config('credential_flow.portal.otp_vigencia_minutos'),
            'entidad' => config('credential_flow.verificacion.entidad'),
        ]);
    }
}
