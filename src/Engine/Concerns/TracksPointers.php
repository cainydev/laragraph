<?php

namespace Cainy\Laragraph\Engine\Concerns;

use Cainy\Laragraph\Models\WorkflowRun;

trait TracksPointers
{
    protected function pushPointers(WorkflowRun $run, string ...$nodeNames): void
    {
        $pointers = $run->active_pointers ?? [];
        foreach ($nodeNames as $name) {
            $pointers[] = $name;
        }
        $run->active_pointers = $pointers;
    }

    protected function removePointer(WorkflowRun $run, string $nodeName): void
    {
        $pointers = $run->active_pointers ?? [];
        $index    = array_search($nodeName, $pointers, true);
        if ($index !== false) {
            array_splice($pointers, $index, 1);
        }
        $run->active_pointers = array_values($pointers);
    }

    protected function hasActivePointers(WorkflowRun $run): bool
    {
        return ! empty($run->active_pointers);
    }
}
