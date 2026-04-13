<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Workbench\App\Workflows\ConditionalBranchWorkflow;
use Workbench\App\Workflows\DeepResearcherWorkflow;
use Workbench\App\Workflows\ErrorRecoveryWorkflow;
use Workbench\App\Workflows\FanOutFanInWorkflow;
use Workbench\App\Workflows\LinearChainWorkflow;
use Workbench\App\Workflows\SafePublisherWorkflow;
use Workbench\App\Workflows\SoftwareFactoryWorkflow;
use Workbench\App\Workflows\ToolUseCycleWorkflow;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
