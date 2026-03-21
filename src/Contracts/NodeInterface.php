<?php

namespace Cainy\Laragraph\Contracts;

interface NodeInterface
{
    public function getName(): string;

    public function __invoke(int $runId, array $state): array;
}
