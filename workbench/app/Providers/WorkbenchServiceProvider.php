<?php

namespace Workbench\App\Providers;

use Cainy\Laragraph\Engine\WorkflowRegistry;
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
    public function register(): void
    {
        $this->app->resolving(WorkflowRegistry::class, function (WorkflowRegistry $registry): void {
            $registry->register('linear-chain', fn () => LinearChainWorkflow::build());
            $registry->register('conditional-branch', fn () => ConditionalBranchWorkflow::build());
            $registry->register('fan-out-fan-in', fn () => FanOutFanInWorkflow::build());
            $registry->register('tool-use-cycle', fn () => ToolUseCycleWorkflow::build());
            $registry->register('error-recovery', fn () => ErrorRecoveryWorkflow::build());
            $registry->register('deep-researcher', fn () => DeepResearcherWorkflow::build());
            $registry->register('safe-publisher', fn () => SafePublisherWorkflow::build());
            $registry->register('software-factory', fn () => SoftwareFactoryWorkflow::build());
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
