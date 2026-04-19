<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Facades\Laragraph;

use function Cainy\Laragraph\Tests\bindTestWorkflow;

class ParentCtxCaptureNode implements Node
{
    public static ?int $parentRunId = null;

    public static ?string $parentNode = null;

    public static ?array $parentMetadata = null;

    public static bool $metadataWasNull = false;

    public static bool $called = false;

    public static function reset(): void
    {
        self::$parentRunId = null;
        self::$parentNode = null;
        self::$parentMetadata = null;
        self::$metadataWasNull = false;
        self::$called = false;
    }

    public function handle(NodeExecutionContext $context, array $state): array
    {
        self::$called = true;
        self::$parentRunId = $context->parentRunId;
        self::$parentNode = $context->parentNodeName;
        $meta = $context->parentMetadata();
        self::$metadataWasNull = $meta === null;
        self::$parentMetadata = $meta;

        return [];
    }
}

class ParentCtxChildWorkflow extends Workflow
{
    public function definition(): void
    {
        $this->addNode('inner', new ParentCtxCaptureNode);
        $this->transition(Workflow::START, 'inner');
        $this->transition('inner', Workflow::END);
    }
}

it('exposes parent run id and parent metadata to child node context', function () {
    ParentCtxCaptureNode::reset();

    $parentKey = bindTestWorkflow('parent-ctx-test', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('sub', app(ParentCtxChildWorkflow::class));
            $this->transition(Workflow::START, 'sub');
            $this->transition('sub', Workflow::END);
        }
    });

    $parent = Laragraph::run($parentKey, [], ['source' => 'unit-test', 'tag' => 'xyz']);

    expect($parent->fresh()->status)->toBe(RunStatus::Completed);
    expect(ParentCtxCaptureNode::$parentRunId)->toBe($parent->id);
    expect(ParentCtxCaptureNode::$parentNode)->toBe('sub');
    expect(ParentCtxCaptureNode::$parentMetadata)->toBe(['source' => 'unit-test', 'tag' => 'xyz']);
});

it('returns null parent metadata when the run has no parent', function () {
    ParentCtxCaptureNode::reset();

    $key = bindTestWorkflow('top-level-ctx-test', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('only', new ParentCtxCaptureNode);
            $this->transition(Workflow::START, 'only');
            $this->transition('only', Workflow::END);
        }
    });

    Laragraph::run($key);

    expect(ParentCtxCaptureNode::$parentRunId)->toBeNull();
    expect(ParentCtxCaptureNode::$metadataWasNull)->toBeTrue();
});
