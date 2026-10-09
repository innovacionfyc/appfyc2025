<?php

namespace Tests\Feature\CredentialFlow\Envios;

use Closure;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/**
 * Transporte de correo SIMULADO (sin red, sin SMTP): consume una secuencia de resultados —una excepción falla ese intento; cualquier otra
 * cosa lo acepta— y guarda los mensajes aceptados para inspeccionarlos.
 */
final class TransportePrueba implements TransportInterface
{
    /** @var list<RawMessage> */
    public array $enviados = [];

    public int $llamadas = 0;

    /** @var list<Throwable|string|null> */
    public array $secuencia = [];

    /** Se ejecuta al empezar cada llamada (número de llamada) para observar el estado de la base DURANTE el envío. */
    public ?Closure $alEnviar = null;

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $this->llamadas++;
        if ($this->alEnviar !== null) {
            ($this->alEnviar)($this->llamadas);
        }
        $siguiente = array_shift($this->secuencia);
        if ($siguiente instanceof Throwable) {
            throw $siguiente;
        }
        $this->enviados[] = $message;
        $enviado = new SentMessage($message, $envelope ?? Envelope::create($message));
        $enviado->appendDebug('250 2.0.0 Ok: queued as '.(is_string($siguiente) ? $siguiente : 'QID12345')."\r\n");

        return $enviado;
    }

    public function __toString(): string
    {
        return 'prueba';
    }
}
