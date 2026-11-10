<?php

declare(strict_types=1);

namespace LaravelSaga\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use LaravelSaga\Facades\Saga;
use LaravelSaga\Jobs\ExecuteSagaJob;
use LaravelSaga\Jobs\ResumeSagaJob;
use LaravelSaga\Tests\TestCase;
use PhpSaga\AbstractSaga;
use PhpSaga\Contracts\StepInterface;
use PhpSaga\Execution\SagaBuilder;
use PhpSaga\SagaContext;
use PhpSaga\SagaStatus;

class SagaQueueTest extends TestCase
{
    public function test_can_dispatch_saga_to_queue(): void
    {
        Queue::fake();

        Saga::dispatch(QueueTestSaga::class, ['amount' => 100], 'queued-saga-1');

        Queue::assertPushed(ExecuteSagaJob::class, function (ExecuteSagaJob $job) {
            return $job->saga === QueueTestSaga::class
                && $job->context === ['amount' => 100]
                && $job->sagaId === 'queued-saga-1';
        });
    }

    public function test_can_dispatch_resume_to_queue(): void
    {
        Queue::fake();

        Saga::dispatchResume('resume-id-1', QueueTestSaga::class, ['token' => 'abc']);

        Queue::assertPushed(ResumeSagaJob::class, function (ResumeSagaJob $job) {
            return $job->sagaId === 'resume-id-1'
                && $job->saga === QueueTestSaga::class
                && $job->additionalContext === ['token' => 'abc'];
        });
    }

    public function test_execute_saga_job_executes_workflow_in_worker(): void
    {
        $job = new ExecuteSagaJob(QueueTestSaga::class, ['num' => 5], 'job-saga-1');

        $result = $job->handle();

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(10, $result->getContext()->get('result'));

        $persisted = Saga::find('job-saga-1');
        $this->assertNotNull($persisted);
        $this->assertSame(SagaStatus::COMPLETED, $persisted->status);
    }

    public function test_resume_saga_job_resumes_workflow_in_worker(): void
    {
        // 1. Initial run to suspension
        $sagaId = 'job-resume-saga-1';
        $initial = Saga::run(QueueSuspendSaga::class, [], $sagaId);
        $this->assertTrue($initial->isSuspended());

        // 2. Worker executes ResumeSagaJob
        $job = new ResumeSagaJob($sagaId, QueueSuspendSaga::class, ['unblocked' => true]);
        $result = $job->handle();

        $this->assertTrue($result->isSuccessful());
        $this->assertTrue($result->getContext()->get('step2_ran'));
    }

    public function test_respects_custom_queue_and_connection_from_config(): void
    {
        config()->set('saga.queue.connection', 'redis');
        config()->set('saga.queue.queue', 'sagas-high');

        $job = new ExecuteSagaJob('SomeSaga', []);

        $this->assertSame('redis', $job->connection);
        $this->assertSame('sagas-high', $job->queue);
    }
}

// ----------------- Fixtures -----------------

class QueueMultiplyStep implements StepInterface
{
    public function getName(): string
    {
        return 'QueueMultiplyStep';
    }

    public function execute(SagaContext $context): void
    {
        $val = (int) $context->get('num', 0);
        $context->set('result', $val * 2);
    }

    public function compensate(SagaContext $context): void {}
}

class QueueHaltStep implements StepInterface
{
    public function getName(): string
    {
        return 'QueueHaltStep';
    }

    public function execute(SagaContext $context): void
    {
        $context->suspend('Awaiting worker trigger');
    }

    public function compensate(SagaContext $context): void {}
}

class QueueFinishStep implements StepInterface
{
    public function getName(): string
    {
        return 'QueueFinishStep';
    }

    public function execute(SagaContext $context): void
    {
        $context->set('step2_ran', true);
    }

    public function compensate(SagaContext $context): void {}
}

class QueueTestSaga extends AbstractSaga
{
    public function configure(SagaBuilder $builder): void
    {
        $builder->addStep(QueueMultiplyStep::class);
    }
}

class QueueSuspendSaga extends AbstractSaga
{
    public function configure(SagaBuilder $builder): void
    {
        $builder
            ->addStep(QueueHaltStep::class)
            ->addStep(QueueFinishStep::class);
    }
}

