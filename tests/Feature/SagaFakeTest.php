<?php

declare(strict_types=1);

namespace LaravelSaga\Tests\Feature;

use LaravelSaga\Facades\Saga;
use LaravelSaga\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use PhpSaga\AbstractSaga;
use PhpSaga\Contracts\StepInterface;
use PhpSaga\Execution\SagaBuilder;
use PhpSaga\SagaContext;

class SagaFakeTest extends TestCase
{
    public function test_can_fake_and_assert_saga_runs(): void
    {
        Saga::fake();

        Saga::assertNothingRun();

        Saga::run(FakeOrderSaga::class, ['order_id' => 'ORD-123'], 'custom-id');

        Saga::assertRun(FakeOrderSaga::class);

        Saga::assertRun(FakeOrderSaga::class, function (array $context, string $id) {
            return $context['order_id'] === 'ORD-123' && $id === 'custom-id';
        });

        Saga::assertRunCount(1);
        Saga::assertNotRun(AnotherFakeSaga::class);
    }

    public function test_assert_run_fails_when_saga_was_not_executed(): void
    {
        Saga::fake();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The expected saga [LaravelSaga\Tests\Feature\FakeOrderSaga] was not run.');

        Saga::assertRun(FakeOrderSaga::class);
    }

    public function test_can_fake_and_assert_resumptions(): void
    {
        Saga::fake();

        Saga::assertNotResumed('suspend-123');

        Saga::resume('suspend-123', FakeOrderSaga::class, ['webhook_received' => true]);

        Saga::assertResumed('suspend-123');

        Saga::assertResumed('suspend-123', function (array $additionalContext, string $saga) {
            return $additionalContext['webhook_received'] === true
                && $saga === FakeOrderSaga::class;
        });

        Saga::assertNotResumed('different-id');
    }

    public function test_can_fake_and_assert_dispatches(): void
    {
        Saga::fake();

        Saga::assertNotDispatched(FakeOrderSaga::class);

        Saga::dispatch(FakeOrderSaga::class, ['async' => true], 'async-saga-1');

        Saga::assertDispatched(FakeOrderSaga::class);

        Saga::assertDispatched(FakeOrderSaga::class, function (array $context, ?string $id) {
            return $context['async'] === true && $id === 'async-saga-1';
        });

        Saga::assertNotDispatched(AnotherFakeSaga::class);
    }

    public function test_can_fake_and_assert_dispatched_resumes(): void
    {
        Saga::fake();

        Saga::dispatchResume('resume-queue-1', FakeOrderSaga::class, ['verified' => true]);

        Saga::assertDispatchedResume('resume-queue-1');

        Saga::assertDispatchedResume('resume-queue-1', function (array $context, string $saga) {
            return $context['verified'] === true && $saga === FakeOrderSaga::class;
        });
    }

    public function test_selective_faking(): void
    {
        // Only fake FakeOrderSaga; RealExecutionSaga should actually run
        Saga::fake([FakeOrderSaga::class]);

        // Faked saga does not hit database or execute steps
        $fakedResult = Saga::run(FakeOrderSaga::class, ['val' => 1]);
        $this->assertTrue($fakedResult->isSuccessful());
        Saga::assertRun(FakeOrderSaga::class);

        // Real saga runs through actual coordinator
        $realResult = Saga::run(RealExecutionSaga::class, ['x' => 10]);
        $this->assertTrue($realResult->isSuccessful());
        $this->assertSame(20, $realResult->getContext()->get('y'));

        // Real saga was persisted in DB
        $persisted = Saga::find($realResult->getState()->sagaId);
        $this->assertNotNull($persisted);
    }
}

// ----------------- Fixtures -----------------

class FakeOrderSaga extends AbstractSaga
{
    public function configure(SagaBuilder $builder): void
    {
        $builder->addCallableStep('DummyStep', static function (SagaContext $c) {});
    }
}

class AnotherFakeSaga extends AbstractSaga
{
    public function configure(SagaBuilder $builder): void
    {
        $builder->addCallableStep('DummyStep', static function (SagaContext $c) {});
    }
}

class RealExecutionStep implements StepInterface
{
    public function getName(): string
    {
        return 'RealExecutionStep';
    }

    public function execute(SagaContext $context): void
    {
        $x = (int) $context->get('x', 0);
        $context->set('y', $x * 2);
    }

    public function compensate(SagaContext $context): void {}
}

class RealExecutionSaga extends AbstractSaga
{
    public function configure(SagaBuilder $builder): void
    {
        $builder->addStep(RealExecutionStep::class);
    }
}
