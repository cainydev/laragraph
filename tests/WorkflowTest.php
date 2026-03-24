<?php

use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Models\WorkflowRun;

// ─── 1. Linear Chain ─────────────────────────────────────────────────────────

it('linear-chain completes and appends log entries', function () {
    $run = Laragraph::start('linear-chain');

    expect($run->fresh())
        ->status->toBe(RunStatus::Completed)
        ->state->toHaveKeys(['log']);
});

// ─── 2. Conditional Branch ───────────────────────────────────────────────────

it('conditional-branch routes to approve or reject based on score', function () {
    // Run multiple times to hit both branches (score is random 0-100)
    $approved = $rejected = false;

    for ($i = 0; $i < 20; $i++) {
        $run = Laragraph::start('conditional-branch');
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
    $run = Laragraph::start('fan-out-fan-in');

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state)->toHaveKey('branch_a_result')
        ->toHaveKey('branch_b_result');
});

// ─── 4. Tool Use Cycle ───────────────────────────────────────────────────────

it('tool-use-cycle completes and produces a summary', function () {
    $run = Laragraph::start('tool-use-cycle');

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state)->toHaveKey('summary');
    expect($fresh->state['summary'])->not->toBe('No tool result found.');
});

// ─── 5. Error Recovery ───────────────────────────────────────────────────────

it('error-recovery fails on first attempt then succeeds after resume with attempt state', function () {
    // With sync queue, jobs don't retry — they fail immediately.
    // The FlakyNode throws on attempt < 2, so first run fails.
    try {
        $run = Laragraph::start('error-recovery', ['attempt' => 0]);
    } catch (Throwable) {
        // Sync queue propagates the exception
    }

    // Find the run (it was created before the node failed)
    $run = WorkflowRun::latest()->first();
    expect($run->fresh()->status)->toBe(RunStatus::Failed);
    expect($run->fresh()->state)->toHaveKey('error');

    // Simulate retry by starting fresh with attempt=2 so FlakyNode succeeds
    $run2 = Laragraph::start('error-recovery', ['attempt' => 2]);

    $fresh = $run2->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state)->toHaveKey('recovered');
    expect($fresh->state['recovered'])->toBeTrue();
});

// ─── 6. Deep Researcher ──────────────────────────────────────────────────────

it('deep-researcher fans out workers and compiles a report', function () {
    $run = Laragraph::start('deep-researcher', ['topic' => 'quantum computing']);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state)->toHaveKey('report');
    expect($fresh->state['report'])->toContain('# Research Report');
    expect($fresh->state['findings'])->toHaveCount(3);
});

// ─── 7. Safe Publisher ───────────────────────────────────────────────────────

it('safe-publisher pauses after drafter then publishes on approval', function () {
    $run = Laragraph::start('safe-publisher');

    // Should be paused after drafter (interrupt_after)
    expect($run->fresh()->status)->toBe(RunStatus::Paused);
    expect($run->fresh()->state)->toHaveKey('draft');

    // Resume with approval — review-router routes to publish
    Laragraph::resume($run->id, ['meta' => ['approved' => true]]);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state)->toHaveKey('published');
    expect($fresh->state['published'])->toBeTrue();
});

it('safe-publisher loops back to drafter on rejection', function () {
    $run = Laragraph::start('safe-publisher');
    expect($run->fresh()->status)->toBe(RunStatus::Paused);

    // Reject — review-router routes back to drafter, which pauses again (interrupt_after)
    Laragraph::resume($run->id, ['meta' => ['approved' => false, 'feedback' => 'Make it funnier']]);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Paused);
    expect($fresh->state['draft_attempt'])->toBe(2);

    // Now approve
    Laragraph::resume($run->id, ['meta' => ['approved' => true]]);
    expect($run->fresh()->status)->toBe(RunStatus::Completed);
});

// ─── 8. Software Factory ─────────────────────────────────────────────────────

it('software-factory loops supervisor → coder → reviewer → supervisor → END', function () {
    $run = Laragraph::start('software-factory');

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state)->toHaveKey('code');
    expect($fresh->state)->toHaveKey('review');
    expect($fresh->state['decision'])->toBe('FINISH');
});
