<?php

declare(strict_types=1);

namespace Dynoxide\PHPUnit;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;

/** @internal */
final class StopSubscriber implements ExecutionFinishedSubscriber
{
    public function __construct(private readonly Session $session)
    {
    }

    public function notify(ExecutionFinished $event): void
    {
        $this->session->stop();
    }
}
