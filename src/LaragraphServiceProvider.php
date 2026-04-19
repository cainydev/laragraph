<?php

namespace Cainy\Laragraph;

use Cainy\Laragraph\Contracts\StateReducerInterface;
use Cainy\Laragraph\Events\WorkflowFailed;
use Cainy\Laragraph\Listeners\CascadeChildFailure;
use Cainy\Laragraph\Reducers\SmartReducer;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaragraphServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laragraph')
            ->hasConfigFile()
            ->hasMigration('2026_03_21_000354_create_workflow_runs_table')
            ->hasMigration('2026_03_21_000355_create_workflow_node_executions_table')
            ->hasMigration('2026_04_13_000000_add_metadata_to_workflow_runs_table')
            ->hasMigration('2026_04_19_000000_add_routing_to_workflow_runs_table')
            ->hasMigration('2026_04_19_000001_add_errors_to_workflow_node_executions_table');
    }

    public function packageRegistered(): void
    {
        $this->app->bind(StateReducerInterface::class, SmartReducer::class);

        $this->app->singleton(Laragraph::class, fn () => new Laragraph);
    }

    public function packageBooted(): void
    {
        Event::listen(WorkflowFailed::class, CascadeChildFailure::class);
    }
}
