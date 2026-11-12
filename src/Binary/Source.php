<?php

declare(strict_types=1);

namespace Dynoxide\Binary;

/**
 * Which step found the binary. The case names are stable; the values are the
 * words the start log line uses, and may change.
 */
enum Source: string
{
    case Option = 'the binary option';
    case Path = 'PATH';
    case Environment = 'DYNOXIDE_BINARY';
    case Cache = 'the cache';
    case Download = 'a download';

    /** Whether the binary came from the user, so its version is unknown until it's run. */
    public function isUserSupplied(): bool
    {
        return $this === self::Option || $this === self::Path || $this === self::Environment;
    }
}
