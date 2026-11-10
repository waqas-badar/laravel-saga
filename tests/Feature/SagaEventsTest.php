<?php

declare(strict_types=1);

namespace LaravelSaga\Tests\Feature;

use Exception;
use Illuminate\Support\Facades\Event;
use LaravelSaga\Facades\Saga;
use LaravelSaga\Tests\TestCase;
use PhpSaga\AbstractSaga;
use PhpSaga\Contracts\StepInterface;
use PhpSaga\Events\CompensationExecuted;
use PhpSaga\Events\CompensationExecuting;
use PhpSaga\Events\SagaCompensated;
use PhpSaga\Events\SagaCompleted;
use PhpSaga\Events\SagaStarted;
use PhpSaga\Events\SagaSuspended;
use PhpSaga\Events\StepExecuted;
use PhpSaga\Events\StepExecuting;
use PhpSaga\Events\StepFailed;
use PhpSaga\Execution\SagaBuilder;
use PhpSaga\SagaContext;

class SagaEventsTest extends TestCase
{
    public function test_dispatches_lifecycle_events_on_success(): void
    {
        $startedEvents = [];
        $stepExecutingEvents = [];
        $stepExecutedEvents = [];
        $completedEvents = [];

        Event::listen(SagaStarted::class, function (SagaStarted $e) use (&$startedEvents) {
            $startedEvents[] = $e;
        });

        Event::listen(StepExecuting::class, function (StepExecuting $e) use (&$stepExecutingEvents) {
            $stepExecutingEvents[] = $e;
        });

        Event::listen(StepExecuted::class, function (StepExecuted $e) use (&$stepExecutedEvents) {
            $stepExecutedEvents[] = $e;
        });

        Event::listen(SagaCompleted::class, function (SagaCompleted $e) use (&$completedEvents) {
            $completedEvents[] = $e;
        });

        $result = Saga::run(EventTestSuccessfulSaga::class, ['initial' => 'ok']);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(1, $startedEvents);
        $this->assertCount(2, $stepExecutingEvents);
        $this->assertCount(2, $stepExecutedEvents);
        $this->assertCount(1, $completedEvents);

        $this->assertSame('EventTestSuccessfulSaga', $startedEvents[0]->state->sagaType);
        $this->assertSame('FirstStep', $stepExecutedEvents[0]->stepName);
        $this->assertSame('SecondStep', $stepExecutedEvents[1]->stepName);
    }

    public function test_dispatches_failure_and_compensation_events(): void
    {
        $stepFailedEvents = [];
        $compExecutingEvents = [];
        $compExecutedEvents = [];
        $compensatedEvents = [];

        Event::listen(StepFailed::class, function (StepFailed $e) use (&$stepFailedEvents) {
            $stepFailedEvents[] = $e;
        });

        Event::listen(CompensationExecuting::class, function (CompensationExecuting $e) use (&$compExecutingEvents) {
            $compExecutingEvents[] = $e;
        });

        Event::listen(CompensationExecuted::class, function (CompensationExecuted $e) use (&$compExecutedEvents) {
            $compExecutedEvents[] = $e;
        });

        Event::listen(SagaCompensated::class, function (SagaCompensated $e) use (&$compensatedEvents) {
            $compensatedEvents[] = $e;
        });

        $result = Saga::run(EventTestFailingSaga::class, []);

        $this->assertTrue($result->isCompensated());
        $this->assertCount(1, $stepFailedEvents);
        $this->assertSame('BoomStep', $stepFailedEvents[0]->stepName);

        $this->assertCount(1, $compExecutingEvents);
        $this->assertSame('FirstStep', $compExecutingEvents[0]->stepName);

        $this->assertCount(1, $compExecutedEvents);
        $this->assertSame('FirstStep', $compExecutedEvents[0]->stepName);

        $this->assertCount(1, $compensatedEvents);
    }

    public function test_dispatches_suspension_event(): void
    {
        $suspendedEvents = [];

        Event::listen(SagaSuspended::class, function (SagaSuspended $e) use (&$suspendedEvents) {
            $suspendedEvents[] = $e;
        });

        $result = Saga::run(EventTestSuspendingSaga::class, []);

        $this->assertTrue($result->isSuspended());
        $this->assertCount(1, $suspendedEvents);
        $this->assertSame('Waiting for approval', $suspendedEvents[0]->reason);
    }
}

// ----------------- Fixtures -----------------

class EventFirstStep implements StepInterface
{
    public function getName(): string
    {
        return 'FirstStep';
    }

    public function execute(SagaContext $context): void
    {
        $context->set('first', true);
    }

    public function compensate(SagaContext $context): void
    {
        $context->set('first', false);
    }
}

class EventSecondStep implements StepInterface
{
    public function getName(): string
    {
        return 'SecondStep';
    }

    public function execute(SagaContext $context): void
    {
        $context->set('second', true);
    }

    public function compensate(SagaContext $context): void {}
}

class EventBoomStep implements StepInterface
{
    public function getName(): string
    {
        return 'BoomStep';
    }

    public function execute(SagaContext $context): void
    {
        throw new Exception('Boom step crashed!');
    }

    public function compensate(SagaContext $context): void {}
}

class EventPauseStep implements StepInterface
{
    public function getName(): string
    {
        return 'PauseStep';
    }

    public function execute(SagaContext $context): void
    {
        $context->suspend('Waiting for approval');
    }

    public function compensate(SagaContext $context): void {}
}

class EventTestSuccessfulSaga extends AbstractSaga
{
    public function configure(SagaBuilder $builder): void
    {
        $builder
            ->addStep(new EventFirstStep())
            ->addStep(new EventSecondStep());
    }
}

class EventTestFailingSaga extends AbstractSaga
{
    public function configure(SagaBuilder $builder): void
    {
        $builder
            ->addStep(new EventFirstStep())
            ->addStep(new EventBoomStep());
    }
}

class EventTestSuspendingSaga extends AbstractSaga
{
    public function configure(SagaBuilder $builder): void
    {
        $builder->addStep(new EventPauseStep());
    }
}

