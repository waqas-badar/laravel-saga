<?php

declare(strict_types=1);

namespace LaravelSaga\Tests\Feature;

use InvalidArgumentException;
use LaravelSaga\DatabaseSagaRepository;
use LaravelSaga\Facades\Saga;
use LaravelSaga\LaravelStepResolver;
use LaravelSaga\SagaManager;
use LaravelSaga\Tests\TestCase;
use PhpSaga\Contracts\SagaRepositoryInterface;
use PhpSaga\Execution\SagaCoordinator;
use PhpSaga\Storage\FileSagaRepository;
use PhpSaga\Storage\InMemorySagaRepository;

class SagaManagerTest extends TestCase
{
    public function test_can_resolve_default_database_repository(): void
    {
        $manager = $this->app->make(SagaManager::class);

        $repo = $manager->repository();
        $this->assertInstanceOf(DatabaseSagaRepository::class, $repo);

        $coordinator = $manager->coordinator();
        $this->assertInstanceOf(SagaCoordinator::class, $coordinator);
    }

    public function test_can_resolve_in_memory_repository(): void
    {
        $manager = $this->app->make(SagaManager::class);

        $repo = $manager->repository('in_memory');
        $this->assertInstanceOf(InMemorySagaRepository::class, $repo);
    }

    public function test_can_resolve_file_repository(): void
    {
        $manager = $this->app->make(SagaManager::class);

        $repo = $manager->repository('file');
        $this->assertInstanceOf(FileSagaRepository::class, $repo);
    }

    public function test_throws_exception_for_unsupported_driver(): void
    {
        $manager = $this->app->make(SagaManager::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported saga repository driver: 'unsupported_driver'");

        $manager->repository('unsupported_driver');
    }

    public function test_can_extend_with_custom_repository_driver(): void
    {
        $customRepo = new InMemorySagaRepository();

        Saga::extend('custom_driver', static function () use ($customRepo) {
            return $customRepo;
        });

        $resolved = Saga::repository('custom_driver');
        $this->assertSame($customRepo, $resolved);
    }

    public function test_can_register_and_resolve_saga_by_alias(): void
    {
        Saga::registerSaga('custom_alias', \LaravelSaga\Tests\Feature\OrderCheckoutTestSaga::class);

        $result = Saga::run('custom_alias', [
            'order_id' => 'ORD-ALIAS',
            'amount' => 10.0,
        ]);

        $this->assertTrue($result->isSuccessful());
    }

    public function test_service_provider_binds_aliases_and_contracts(): void
    {
        $this->assertTrue($this->app->bound('saga'));
        $this->assertInstanceOf(SagaManager::class, $this->app->make('saga'));

        $this->assertInstanceOf(SagaCoordinator::class, $this->app->make(SagaCoordinator::class));
        $this->assertInstanceOf(SagaRepositoryInterface::class, $this->app->make(SagaRepositoryInterface::class));
        $this->assertInstanceOf(LaravelStepResolver::class, $this->app->make(LaravelStepResolver::class));
    }
}

