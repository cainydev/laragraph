<?php

use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Models\WorkflowRun;
use Workbench\App\Workflows\ConditionalBranchWorkflow;
use Workbench\App\Workflows\DeepResearcherWorkflow;
use Workbench\App\Workflows\ErrorRecoveryWorkflow;
use Workbench\App\Workflows\FanOutFanInWorkflow;
use Workbench\App\Workflows\LinearChainWorkflow;
use Workbench\App\Workflows\SafePublisherWorkflow;
use Workbench\App\Workflows\SoftwareFactoryWorkflow;
use Workbench\App\Workflows\ToolUseCycleWorkflow;

// ─── 1. Linear Chain ─────────────────────────────────────────────────────────

it('linear-chain completes and appends log entries', function () {
    $run = Laragraph::run(LinearChainWorkflow::class);

    expect($run->fresh())
        ->status->toBe(RunStatus::Completed)
        ->state->toHaveKeys(['log']);
});

// ─── 2. Conditional Branch ───────────────────────────────────────────────────

it('conditional-branch routes to approve or reject based on score', function () {
    $approved = $rejected = false;

    for ($i = 0; $i < 20; $i++) {
        $run = Laragraph::run(ConditionalBranchWorkflow::class);
        $state = $run->fresh()->state;

        if (isset($state['approved'])) {
            $approved = true;
        }
        if (isset($state['rejected'])) {
            $rejected = true;
        }

        if ($approved && $rejected) {
            break;
        }
    }

    expect($approved)->toBeTrue('approve branch never hit')
        ->and($rejected)->toBeTrue('reject branch never hit');
});

// ─── 3. Fan-out / Fan-in ─────────────────────────────────────────────────────

it('fan-out/fan-in completes with results from both branches', function () {
    $run = Laragraph::run(FanOutFanInWorkflow::class);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state)->toHaveKey('branch_a_result')
        ->toHaveKey('branch_b_result');
});

// ─── 4. Tool Use Cycle ───────────────────────────────────────────────────────

it('tool-use-cycle completes and produces a summary', function () {
    $run = Laragraph::run(ToolUseCycleWorkflow::class);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state)->toHaveKey('summary');
    expect($fresh->state['summary'])->not->toBe('No tool result found.');
});

// ─── 5. Error Recovery ───────────────────────────────────────────────────────

it('error-recovery fails on first attempt then succeeds after resume with attempt state', function () {
    try {
        $run = Laragraph::run(ErrorRecoveryWorkflow::class, ['attempt' => 0]);
    } catch (Throwable) {
        // Sync queue propagates the exception
    }

    $run = WorkflowRun::latest()->first();
    expect($run->fresh()->status)->toBe(RunStatus::Failed);
    expect($run->fresh()->routing)->toHaveKey('error');

    $run2 = Laragraph::run(ErrorRecoveryWorkflow::class, ['attempt' => 2]);

    $fresh = $run2->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state)->toHaveKey('recovered');
    expect($fresh->state['recovered'])->toBeTrue();
});

// ─── 6. Deep Researcher ──────────────────────────────────────────────────────

it('deep-researcher fans out workers and compiles a report', function () {
    $run = Laragraph::run(DeepResearcherWorkflow::class, ['topic' => 'quantum computing']);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state)->toHaveKey('report');
    expect($fresh->state['report'])->toContain('# Research Report');
    expect($fresh->state['findings'])->toHaveCount(3);
});

// ─── 7. Safe Publisher ───────────────────────────────────────────────────────

it('safe-publisher pauses after drafter then publishes on approval', function () {
    $run = Laragraph::run(SafePublisherWorkflow::class);

    expect($run->fresh()->status)->toBe(RunStatus::Paused);
    expect($run->fresh()->state)->toHaveKey('draft');

    Laragraph::resume($run->id, ['meta' => ['approved' => true]]);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state)->toHaveKey('published');
    expect($fresh->state['published'])->toBeTrue();
});

it('safe-publisher loops back to drafter on rejection', function () {
    $run = Laragraph::run(SafePublisherWorkflow::class);
    expect($run->fresh()->status)->toBe(RunStatus::Paused);

    Laragraph::resume($run->id, ['meta' => ['approved' => false, 'feedback' => 'Make it funnier']]);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Paused);
    expect($fresh->state['draft_attempt'])->toBe(2);

    Laragraph::resume($run->id, ['meta' => ['approved' => true]]);
    expect($run->fresh()->status)->toBe(RunStatus::Completed);
});

// ─── 8. Software Factory ─────────────────────────────────────────────────────

it('software-factory loops supervisor → coder → reviewer → supervisor → END', function () {
    $run = Laragraph::run(SoftwareFactoryWorkflow::class);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state)->toHaveKey('code');
    expect($fresh->state)->toHaveKey('review');
    expect($fresh->state['decision'])->toBe('FINISH');
});
