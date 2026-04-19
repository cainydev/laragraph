<?php

namespace Cainy\Laragraph\Engine\Concerns;

use Cainy\Laragraph\Models\WorkflowRun;

/**
 * Engine-only routing metadata lives on WorkflowRun::$routing and is never
 * exposed via $state to user nodes. Keys:
 *   - expected_spawns[node_name]  int
 *   - completed_spawns[node_name] int
 *   - interrupt                   ?string  (node that paused before/after)
 *   - child_runs[node_name]       int      (child WorkflowRun id per parent node)
 *   - gate_reason                 ?string  (last gate reason)
 *   - error                       ?array   (last node error summary)
 */
trait ManagesRouting
{
    protected function routing(WorkflowRun $run): array
    {
        return $run->routing ?? [];
    }

    protected function expectedSpawns(WorkflowRun $run, string $nodeName): int
    {
        return (int) ($run->routing['expected_spawns'][$nodeName] ?? 0);
    }

    protected function completedSpawns(WorkflowRun $run, string $nodeName): int
    {
        return (int) ($run->routing['completed_spawns'][$nodeName] ?? 0);
    }

    protected function incrementExpectedSpawns(WorkflowRun $run, string $nodeName, int $by = 1): void
    {
        $routing = $run->routing ?? [];
        $routing['expected_spawns'][$nodeName] = ($routing['expected_spawns'][$nodeName] ?? 0) + $by;
        $run->routing = $routing;
    }

    protected function incrementCompletedSpawns(WorkflowRun $run, string $nodeName, int $by = 1): void
    {
        $routing = $run->routing ?? [];
        $routing['completed_spawns'][$nodeName] = ($routing['completed_spawns'][$nodeName] ?? 0) + $by;
        $run->routing = $routing;
    }

    protected function interruptMarker(WorkflowRun $run): ?string
    {
        return $run->routing['interrupt'] ?? null;
    }

    protected function setInterruptMarker(WorkflowRun $run, ?string $nodeName): void
    {
        $routing = $run->routing ?? [];
        if ($nodeName === null) {
            unset($routing['interrupt']);
        } else {
            $routing['interrupt'] = $nodeName;
        }
        $run->routing = $routing;
    }

    protected function childRunId(WorkflowRun $run, string $nodeName): ?int
    {
        $id = $run->routing['child_runs'][$nodeName] ?? null;

        return $id === null ? null : (int) $id;
    }

    protected function setChildRunId(WorkflowRun $run, string $nodeName, ?int $childRunId): void
    {
        $routing = $run->routing ?? [];
        if ($childRunId === null) {
            unset($routing['child_runs'][$nodeName]);
            if (empty($routing['child_runs'])) {
                unset($routing['child_runs']);
            }
        } else {
            $routing['child_runs'][$nodeName] = $childRunId;
        }
        $run->routing = $routing;
    }

    protected function setGateReason(WorkflowRun $run, ?string $reason): void
    {
        $routing = $run->routing ?? [];
        if ($reason === null) {
            unset($routing['gate_reason']);
        } else {
            $routing['gate_reason'] = $reason;
        }
        $run->routing = $routing;
    }

    protected function gateReason(WorkflowRun $run): ?string
    {
        return $run->routing['gate_reason'] ?? null;
    }

    protected function setErrorSummary(WorkflowRun $run, ?array $summary): void
    {
        $routing = $run->routing ?? [];
        if ($summary === null) {
            unset($routing['error']);
        } else {
            $routing['error'] = $summary;
        }
        $run->routing = $routing;
    }
}
