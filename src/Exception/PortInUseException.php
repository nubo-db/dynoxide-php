<?php

declare(strict_types=1);

namespace Dynoxide\Exception;

/**
 * The fixed port a server was asked to listen on is already taken. Dynoxide
 * 1.8.0 or later reports this; an older binary gives a plain StartException,
 * whose stderr lines name the port.
 */
final class PortInUseException extends StartException
{
    public function __construct(public readonly int $port, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
