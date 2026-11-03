<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Conflicto de estado de dominio diseñado para el usuario.
 * Se renderiza como 409 con `message` (ver bootstrap/app.php).
 */
class DomainConflictException extends RuntimeException {}
