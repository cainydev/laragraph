<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Nodes\DelayNode;
use Cainy\Laragraph\Nodes\FormatNode;

use function Cainy\Laragraph\Tests\bindTestWorkflow;

it('null mutation values remove keys from state', function () {
    // FormatNode returns [$key => null] which should delete the key, not set it to null.
    $key = bindTestWorkflow('null-tombstone', new class extends Workflow {
        public function definition(): void
        {
            $this->addNode('setter', new FormatNode(fn () => ['temp' => 'value']));
            $this->addNode('cleaner', new FormatNode(fn () => ['temp' => null]));
            $this->transition(Workflow::START, 'setter');
            $this->transition('setter', 'cleaner');
            $this->transition('cleaner', Workflow::END);
        }
    });

    $run = Laragraph::run($key);

    expect($run->fresh()->status)->toBe(RunStatus::Completed);
    expect($run->fresh()->state)->not->toHaveKey('temp');
});

it('DelayNode marker is fully removed from state after delay elapses', function () {
    $key = bindTestWorkflow('delay-loop-marker', new class extends Workflow {
        public function definition(): void
        {
            // Pre-seed the marker as if the delay already fired — run the node again
            // to confirm the marker is gone, not left as null.
            $this->addNode('wait', new DelayNode(seconds: 1));
            $this->addNode('check', new FormatNode(fn (array $s) => ['marker_gone' => ! array_key_exists('__delay_resume_wait', $s)]));
            $this->transition(Workflow::START, 'wait');
            $this->transition('wait', 'check');
            $this->transition('check', Workflow::END);
        }
    });

    // Start with the marker already expired so DelayNode completes immediately.
    $run = Laragraph::run($key, ['__delay_resume_wait' => now()->subSeconds(5)->timestamp]);

    expect($run->fresh()->status)->toBe(RunStatus::Completed);
    expect($run->fresh()->state['marker_gone'])->toBeTrue();
    expect($run->fresh()->state)->not->toHaveKey('__delay_resume_wait');
});
