<?php

namespace Workbench\App\Nodes;

use Cainy\Laragraph\Nodes\ToolNode;

class DemoToolNode extends ToolNode
{
    protected function toolMap(): array
    {
        return [
            'get_weather' => fn (array $args): string => "Sunny, 22°C in " . ($args['city'] ?? 'unknown'),
        ];
    }
}
