<?php

namespace App\Support\CredentialFlow\Plantillas;

use RuntimeException;

/** La imagen subida no se puede usar como fondo de plantilla. El mensaje está pensado para mostrarse tal cual al usuario. */
final class ImagenInvalidaException extends RuntimeException {}
