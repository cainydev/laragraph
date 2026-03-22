<?php

namespace Cainy\Laragraph\Tests;

use Cainy\Laragraph\LaragraphServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;
use Workbench\App\Providers\WorkbenchServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Cainy\\Laragraph\\Database\\Factories\\' . class_basename($modelName) . 'Factory'
        );
    }

    protected function getPackageProviders($app): array
    {
        return [
            LaragraphServiceProvider::class,
            WorkbenchServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        config()->set('queue.default', 'sync');

        foreach ([
            __DIR__ . '/../database/migrations',
            __DIR__ . '/../workbench/database/migrations',
        ] as $dir) {
            if (is_dir($dir)) {
                foreach (glob($dir . '/*.php') as $file) {
                    (include $file)->up();
                }
            }
        }
    }
}
