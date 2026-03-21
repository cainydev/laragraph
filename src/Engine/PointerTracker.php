<?php

namespace Cainy\Laragraph\Engine;

use Cainy\Laragraph\Models\WorkflowRun;

class PointerTracker
{
    public function push(WorkflowRun $run, string ...$nodeNames): void
    {
        $pointers = $run->active_pointers ?? [];
        foreach ($nodeNames as $name) {
            $pointers[] = $name;
        }
        $run->active_pointers = $pointers;
    }

    public function remove(WorkflowRun $run, string $nodeName): void
    {
        $pointers = $run->active_pointers ?? [];
        $index = array_search($nodeName, $pointers, true);
        if ($index !== false) {
            array_splice($pointers, $index, 1);
        }
        $run->active_pointers = array_values($pointers);
    }

    public function isEmpty(WorkflowRun $run): bool
    {
        return empty($run->active_pointers);
    }
}
