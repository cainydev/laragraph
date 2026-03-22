<?php

namespace Cainy\Laragraph;

use Cainy\Laragraph\Contracts\StateReducerInterface;
use Cainy\Laragraph\Engine\WorkflowRegistry;
use Cainy\Laragraph\Reducers\SmartReducer;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaragraphServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laragraph')
            ->hasConfigFile()
            ->hasMigration('2026_03_21_000354_create_workflow_runs_table');
    }

    public function packageRegistered(): void
    {
        $this->app->bind(StateReducerInterface::class, SmartReducer::class);

        $this->app->singleton(WorkflowRegistry::class, function ($app): WorkflowRegistry {
            return new WorkflowRegistry(config('laragraph.workflows', []));
        });

        $this->app->singleton(Laragraph::class, function ($app): Laragraph {
            return new Laragraph(
                $app->make(WorkflowRegistry::class),
            );
        });
    }
}
