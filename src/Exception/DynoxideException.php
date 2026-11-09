<?php

declare(strict_types=1);

namespace Dynoxide\Exception;

/**
 * Anything this package throws extends this, so callers can catch the lot.
 */
class DynoxideException extends \RuntimeException
{
}
