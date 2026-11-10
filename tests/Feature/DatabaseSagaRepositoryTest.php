<?php

declare(strict_types=1);

namespace LaravelSaga\Tests\Feature;

use DateTimeImmutable;
use LaravelSaga\DatabaseSagaRepository;
use LaravelSaga\Tests\TestCase;
use PhpSaga\SagaContext;
use PhpSaga\SagaState;
use PhpSaga\SagaStatus;

class DatabaseSagaRepositoryTest extends TestCase
{
    private DatabaseSagaRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new DatabaseSagaRepository(
            databaseManager: $this->app->make('db'),
            connectionName: 'testing',
            tableName: 'sagas'
        );
    }

    public function test_can_save_and_find_saga_state(): void
    {
        $state = new SagaState(
            sagaId: 'test-saga-1',
            sagaType: 'OrderWorkflow',
            status: SagaStatus::IN_PROGRESS,
            context: SagaContext::fromArray(['order_id' => 123, 'total' => 99.95]),
            currentStep: 'ChargePayment',
            completedSteps: ['ReserveInventory'],
            exitReason: null,
        );

        $this->repository->save($state);

        $this->assertTrue($this->repository->exists('test-saga-1'));

        $found = $this->repository->find('test-saga-1');
        $this->assertNotNull($found);
        $this->assertSame('test-saga-1', $found->sagaId);
        $this->assertSame('OrderWorkflow', $found->sagaType);
        $this->assertSame(SagaStatus::IN_PROGRESS, $found->status);
        $this->assertSame(123, $found->context->get('order_id'));
        $this->assertSame(99.95, $found->context->get('total'));
        $this->assertSame('ChargePayment', $found->currentStep);
        $this->assertSame(['ReserveInventory'], $found->completedSteps);
    }

    public function test_returns_null_when_saga_not_found(): void
    {
        $this->assertNull($this->repository->find('non-existent-id'));
        $this->assertFalse($this->repository->exists('non-existent-id'));
    }

    public function test_can_update_status(): void
    {
        $state = new SagaState(
            sagaId: 'test-saga-2',
            sagaType: 'OrderWorkflow',
            status: SagaStatus::IN_PROGRESS,
            context: SagaContext::fromArray(['customer_id' => 456]),
        );

        $this->repository->save($state);

        $this->repository->updateStatus(
            'test-saga-2',
            SagaStatus::COMPLETED,
            currentStep: null,
            exitReason: 'All steps completed successfully'
        );

        $found = $this->repository->find('test-saga-2');
        $this->assertNotNull($found);
        $this->assertSame(SagaStatus::COMPLETED, $found->status);
        $this->assertNull($found->currentStep);
        $this->assertSame('All steps completed successfully', $found->exitReason);
    }

    public function test_can_update_context(): void
    {
        $state = new SagaState(
            sagaId: 'test-saga-3',
            sagaType: 'OrderWorkflow',
            status: SagaStatus::IN_PROGRESS,
            context: SagaContext::fromArray(['initial' => 'value']),
        );

        $this->repository->save($state);

        $newContext = SagaContext::fromArray(['initial' => 'value', 'updated' => 'new_value']);
        $this->repository->updateContext('test-saga-3', $newContext);

        $found = $this->repository->find('test-saga-3');
        $this->assertNotNull($found);
        $this->assertSame('new_value', $found->context->get('updated'));
    }

    public function test_can_delete_saga_state(): void
    {
        $state = new SagaState(
            sagaId: 'test-saga-4',
            sagaType: 'OrderWorkflow',
            status: SagaStatus::COMPLETED,
            context: SagaContext::fromArray([]),
        );

        $this->repository->save($state);
        $this->assertTrue($this->repository->exists('test-saga-4'));

        $this->repository->delete('test-saga-4');
        $this->assertFalse($this->repository->exists('test-saga-4'));
        $this->assertNull($this->repository->find('test-saga-4'));
    }

    public function test_can_find_for_update(): void
    {
        $state = new SagaState(
            sagaId: 'test-saga-5',
            sagaType: 'OrderWorkflow',
            status: SagaStatus::IN_PROGRESS,
            context: SagaContext::fromArray(['amount' => 50]),
        );

        $this->repository->save($state);

        $found = $this->repository->findForUpdate('test-saga-5');
        $this->assertNotNull($found);
        $this->assertSame('test-saga-5', $found->sagaId);
    }

    public function test_can_prune_old_records(): void
    {
        $oldDate = new DateTimeImmutable('-10 days');
        $recentDate = new DateTimeImmutable('-1 day');

        $oldCompleted = new SagaState(
            sagaId: 'old-completed',
            sagaType: 'Test',
            status: SagaStatus::COMPLETED,
            context: SagaContext::fromArray([]),
            createdAt: $oldDate,
            updatedAt: $oldDate,
        );

        $oldFailed = new SagaState(
            sagaId: 'old-failed',
            sagaType: 'Test',
            status: SagaStatus::FAILED,
            context: SagaContext::fromArray([]),
            createdAt: $oldDate,
            updatedAt: $oldDate,
        );

        $recentCompleted = new SagaState(
            sagaId: 'recent-completed',
            sagaType: 'Test',
            status: SagaStatus::COMPLETED,
            context: SagaContext::fromArray([]),
            createdAt: $recentDate,
            updatedAt: $recentDate,
        );

        $this->repository->save($oldCompleted);
        $this->repository->save($oldFailed);
        $this->repository->save($recentCompleted);

        // Prune only completed records older than 5 days ago
        $cutoff = new DateTimeImmutable('-5 days');
        $deleted = $this->repository->prune($cutoff, [SagaStatus::COMPLETED]);

        $this->assertSame(1, $deleted);
        $this->assertFalse($this->repository->exists('old-completed'));
        $this->assertTrue($this->repository->exists('old-failed'));
        $this->assertTrue($this->repository->exists('recent-completed'));
    }
}

