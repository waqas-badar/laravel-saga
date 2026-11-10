<?php

declare(strict_types=1);

namespace LaravelSaga\Tests\Feature;

use Exception;
use LaravelSaga\Facades\Saga;
use LaravelSaga\Tests\TestCase;
use PhpSaga\AbstractSaga;
use PhpSaga\Contracts\StepInterface;
use PhpSaga\Execution\SagaBuilder;
use PhpSaga\SagaContext;
use PhpSaga\SagaStatus;

class SagaExecutionTest extends TestCase
{
    public function test_can_execute_successful_saga_using_facade(): void
    {
        $result = Saga::run(OrderCheckoutTestSaga::class, [
            'order_id' => 'ORD-100',
            'amount' => 59.99,
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertFalse($result->isCompensated());
        $this->assertFalse($result->isSuspended());
        $this->assertSame(SagaStatus::COMPLETED, $result->getState()->status);
        $this->assertTrue($result->getContext()->get('inventory_reserved'));
        $this->assertTrue($result->getContext()->get('payment_charged'));
        $this->assertSame(['ReserveInventoryStep', 'ChargePaymentStep'], $result->getState()->completedSteps);

        // Verify persisted state in database
        $persisted = Saga::find($result->getState()->sagaId);
        $this->assertNotNull($persisted);
        $this->assertSame(SagaStatus::COMPLETED, $persisted->status);
        $this->assertSame(SagaStatus::COMPLETED, Saga::status($result->getState()->sagaId));
    }

    public function test_compensates_in_reverse_order_when_step_fails(): void
    {
        $result = Saga::run(FailingOrderCheckoutSaga::class, [
            'order_id' => 'ORD-200',
        ]);

        $this->assertFalse($result->isSuccessful());
        $this->assertTrue($result->isCompensated());
        $this->assertSame(SagaStatus::COMPENSATED, $result->getState()->status);
        $this->assertSame('Payment gateway rejected transaction', $result->getExecutionException()?->getMessage());

        // Inventory was reserved then released during compensation
        $this->assertFalse($result->getContext()->get('inventory_reserved'));

        // Verify database persistence
        $persisted = Saga::find($result->getState()->sagaId);
        $this->assertNotNull($persisted);
        $this->assertSame(SagaStatus::COMPENSATED, $persisted->status);
    }

    public function test_suspends_and_resumes_workflow(): void
    {
        $sagaId = 'saga-suspend-resume-1';

        // Phase 1: Run until suspension
        $result = Saga::run(
            saga: SuspendableOrderSaga::class,
            context: ['order_id' => 'ORD-300'],
            sagaId: $sagaId
        );

        $this->assertFalse($result->isSuccessful());
        $this->assertTrue($result->isSuspended());
        $this->assertSame(SagaStatus::SUSPENDED, $result->getState()->status);
        $this->assertSame('Awaiting customer verification', $result->getState()->exitReason);
        $this->assertTrue($result->getContext()->get('step1_done'));
        $this->assertNull($result->getContext()->get('step2_done'));

        // Phase 2: Resume with webhook/additional context
        $resumedResult = Saga::resume(
            sagaId: $sagaId,
            saga: SuspendableOrderSaga::class,
            additionalContext: ['customer_verified' => true]
        );

        $this->assertTrue($resumedResult->isSuccessful());
        $this->assertFalse($resumedResult->isSuspended());
        $this->assertSame(SagaStatus::COMPLETED, $resumedResult->getState()->status);
        $this->assertTrue($resumedResult->getContext()->get('step2_done'));
        $this->assertTrue($resumedResult->getContext()->get('customer_verified'));
    }

    public function test_can_run_inline_callable_steps(): void
    {
        $saga = Saga::builder('InlineCheckout')
            ->addCallableStep(
                name: 'Step1',
                execute: function (SagaContext $c) {
                    $c->set('s1', 'done');
                },
                compensate: function (SagaContext $c) {
                    $c->remove('s1');
                }
            )
            ->addCallableStep(
                name: 'Step2',
                execute: function (SagaContext $c) {
                    $c->set('s2', 'done');
                }
            )
            ->build();

        $result = Saga::run($saga, ['initial' => 'val']);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('done', $result->getContext()->get('s1'));
        $this->assertSame('done', $result->getContext()->get('s2'));
    }
}

// ----------------- Test Fixtures -----------------

class ReserveInventoryStep implements StepInterface
{
    public function getName(): string
    {
        return 'ReserveInventory';
    }

    public function execute(SagaContext $context): void
    {
        $context->set('inventory_reserved', true);
    }

    public function compensate(SagaContext $context): void
    {
        $context->set('inventory_reserved', false);
    }
}

class ChargePaymentStep implements StepInterface
{
    public function getName(): string
    {
        return 'ChargePayment';
    }

    public function execute(SagaContext $context): void
    {
        $context->set('payment_charged', true);
    }

    public function compensate(SagaContext $context): void
    {
        $context->set('payment_charged', false);
    }
}

class FailingPaymentStep implements StepInterface
{
    public function getName(): string
    {
        return 'FailingPayment';
    }

    public function execute(SagaContext $context): void
    {
        throw new Exception('Payment gateway rejected transaction');
    }

    public function compensate(SagaContext $context): void {}
}

class SuspendingStep implements StepInterface
{
    public function getName(): string
    {
        return 'SuspendingStep';
    }

    public function execute(SagaContext $context): void
    {
        $context->set('step1_done', true);
        $context->suspend('Awaiting customer verification');
    }

    public function compensate(SagaContext $context): void {}
}

class FinalizingStep implements StepInterface
{
    public function getName(): string
    {
        return 'FinalizingStep';
    }

    public function execute(SagaContext $context): void
    {
        $context->set('step2_done', true);
    }

    public function compensate(SagaContext $context): void {}
}

class OrderCheckoutTestSaga extends AbstractSaga
{
    public function configure(SagaBuilder $builder): void
    {
        $builder
            ->addStep(ReserveInventoryStep::class)
            ->addStep(ChargePaymentStep::class);
    }
}

class FailingOrderCheckoutSaga extends AbstractSaga
{
    public function configure(SagaBuilder $builder): void
    {
        $builder
            ->addStep(ReserveInventoryStep::class)
            ->addStep(FailingPaymentStep::class);
    }
}

class SuspendableOrderSaga extends AbstractSaga
{
    public function configure(SagaBuilder $builder): void
    {
        $builder
            ->addStep(SuspendingStep::class)
            ->addStep(FinalizingStep::class);
    }
}
