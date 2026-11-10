<?php

declare(strict_types=1);

namespace Dynoxide\PHPUnit;

use PHPUnit\Event\TestRunner\ExecutionStarted;
use PHPUnit\Event\TestRunner\ExecutionStartedSubscriber;

/** @internal */
final class StartSubscriber implements ExecutionStartedSubscriber
{
    public function __construct(private readonly Session $session)
    {
    }

    public function notify(ExecutionStarted $event): void
    {
        $this->session->start();
    }
}
