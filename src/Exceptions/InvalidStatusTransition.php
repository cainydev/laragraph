<?php

namespace Cainy\Laragraph\Exceptions;

use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Models\WorkflowRun;

class InvalidStatusTransition extends \RuntimeException
{
    public static function notPaused(WorkflowRun $run): self
    {
        $status = RunStatus::Paused->value;

        return new self("WorkflowRun [{$run->id}] cannot be resumed because its current status is not {$status}.");
    }

    public static function notRunning(WorkflowRun $run): self
    {
        $status = RunStatus::Running->value;

        return new self("WorkflowRun [{$run->id}] cannot be paused because its current status is not {$status}.");
    }
}
