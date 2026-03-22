<?php

use Cainy\Laragraph\Engine\Concerns\TracksPointers;
use Cainy\Laragraph\Models\WorkflowRun;

// Minimal test double that exposes the trait's protected methods publicly
$tracker = new class {
    use TracksPointers;

    public function push(WorkflowRun $run, string ...$names): void
    {
        $this->pushPointers($run, ...$names);
    }

    public function remove(WorkflowRun $run, string $name): void
    {
        $this->removePointer($run, $name);
    }

    public function isEmpty(WorkflowRun $run): bool
    {
        return ! $this->hasActivePointers($run);
    }
};

beforeEach(function () use ($tracker) {
    $this->tracker = $tracker;
    $this->run = WorkflowRun::create([
        'key'    => 'test',
        'state'  => [],
        'status' => 'running',
    ]);
});

it('pushes pointers', function () {
    $this->tracker->push($this->run, 'a', 'b');

    expect($this->run->active_pointers)->toBe(['a', 'b']);
});

it('removes a specific pointer', function () {
    $this->tracker->push($this->run, 'a', 'b', 'c');
    $this->tracker->remove($this->run, 'b');

    expect($this->run->active_pointers)->toBe(['a', 'c']);
});

it('reports empty correctly', function () {
    expect($this->tracker->isEmpty($this->run))->toBeTrue();

    $this->tracker->push($this->run, 'a');
    expect($this->tracker->isEmpty($this->run))->toBeFalse();

    $this->tracker->remove($this->run, 'a');
    expect($this->tracker->isEmpty($this->run))->toBeTrue();
});

it('handles removing non-existent pointer gracefully', function () {
    $this->tracker->push($this->run, 'a');
    $this->tracker->remove($this->run, 'z');

    expect($this->run->active_pointers)->toBe(['a']);
});

it('handles duplicate pointer names by removing first occurrence', function () {
    $this->tracker->push($this->run, 'a', 'a', 'b');
    $this->tracker->remove($this->run, 'a');

    expect($this->run->active_pointers)->toBe(['a', 'b']);
});

it('preserves array values after removal', function () {
    $this->tracker->push($this->run, 'x', 'y', 'z');
    $this->tracker->remove($this->run, 'x');

    // Should be re-indexed (no gaps)
    expect($this->run->active_pointers)->toBe(['y', 'z']);
    expect(array_keys($this->run->active_pointers))->toBe([0, 1]);
});
