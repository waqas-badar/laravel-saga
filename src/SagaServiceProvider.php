<?php

declare(strict_types=1);

namespace LaravelSaga;

use Illuminate\Support\ServiceProvider;
use PhpSaga\Contracts\SagaRepositoryInterface;
use PhpSaga\Contracts\StepResolverInterface;
use PhpSaga\Execution\SagaCoordinator;

class SagaServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/saga.php', 'saga');

        $this->app->singleton(LaravelStepResolver::class, static function ($app) {
            return new LaravelStepResolver($app);
        });

        $this->app->bind(StepResolverInterface::class, LaravelStepResolver::class);

        $this->app->singleton(SagaManager::class, static function ($app) {
            return new SagaManager($app);
        });

        $this->app->alias(SagaManager::class, 'saga');

        $this->app->bind(SagaCoordinator::class, static function ($app) {
            return $app->make(SagaManager::class)->coordinator();
        });

        $this->app->bind(SagaRepositoryInterface::class, static function ($app) {
            return $app->make(SagaManager::class)->repository();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/saga.php' => config_path('saga.php'),
            ], 'saga-config');

            $this->publishes([
                __DIR__ . '/../database/migrations/' => database_path('migrations'),
            ], 'saga-migrations');

            $this->publishes([
                __DIR__ . '/../stubs/' => base_path('stubs'),
            ], 'saga-stubs');

            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

            $this->commands([
                Commands\MakeSagaCommand::class,
                Commands\MakeStepCommand::class,
                Commands\ShowSagaCommand::class,
                Commands\ResumeSagaCommand::class,
                Commands\PruneSagasCommand::class,
            ]);
        }
    }
}

