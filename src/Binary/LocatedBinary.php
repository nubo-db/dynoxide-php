<?php

declare(strict_types=1);

namespace Dynoxide\Binary;

/** A binary ready to start: where it is, how it was found, and how to start it. */
final class LocatedBinary
{
    public function __construct(
        public readonly string $path,
        public readonly Source $source,
        public readonly string $version,
        /** False for 1.6.x, which is started without --ready-json and --exit-with-parent. */
        public readonly bool $launcherFlags,
    ) {
    }

    /** For the line logged once at start, so a test log shows which binary ran. */
    public function describe(): string
    {
        return "Dynoxide {$this->version} at {$this->path}, from {$this->source->value}"
            . ($this->source->isUserSupplied() ? ' (not checksum-verified)' : '')
            . ($this->launcherFlags ? '' : ', started without the launcher flags it predates');
    }
}
