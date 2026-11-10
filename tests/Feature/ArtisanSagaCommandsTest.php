<?php

declare(strict_types=1);

namespace LaravelSaga\Tests\Feature;

use DateTimeImmutable;
use LaravelSaga\Facades\Saga;
use LaravelSaga\Tests\TestCase;
use PhpSaga\AbstractSaga;
use PhpSaga\Contracts\StepInterface;
use PhpSaga\Execution\SagaBuilder;
use PhpSaga\SagaContext;
use PhpSaga\SagaState;
use PhpSaga\SagaStatus;

class ArtisanSagaCommandsTest extends TestCase
{
    public function test_saga_show_displays_details_and_handles_missing_saga(): void
    {
        // 1. Missing saga
        $this->artisan('saga:show', ['id' => 'non-existent-saga'])
            ->expectsOutput("Saga 'non-existent-saga' was not found in storage.")
            ->assertExitCode(1);

        // 2. Existing saga
        $state = new SagaState(
            sagaId: 'cmd-test-1',
            sagaType: 'OrderFulfillmentSaga',
            status: SagaStatus::IN_PROGRESS,
            context: SagaContext::fromArray(['order_id' => 'ORD-999', 'amount' => 150.0]),
            currentStep: 'StepTwo',
            completedSteps: ['StepOne'],
        );
        Saga::repository()->save($state);

        $this->artisan('saga:show', ['id' => 'cmd-test-1'])
            ->expectsOutput('Saga Details: cmd-test-1')
            ->assertExitCode(0);
    }

    public function test_saga_resume_resumes_suspended_saga(): void
    {
        $sagaId = 'cmd-resume-saga-1';

        // Initial run halts at step 1
        $initialResult = Saga::run(CliSuspendableSaga::class, ['num' => 10], $sagaId);
        $this->assertTrue($initialResult->isSuspended());

        // Resume via artisan command with additional context
        $this->artisan('saga:resume', [
            'id' => $sagaId,
            '--saga' => CliSuspendableSaga::class,
            '--context' => json_encode(['approved' => true]),
        ])
            ->expectsOutput("Resuming saga '{$sagaId}' (" . CliSuspendableSaga::class . ')...')
            ->expectsOutput('✓ Saga completed successfully!')
            ->assertExitCode(0);

        // Verify state in repository
        $resumed = Saga::find($sagaId);
        $this->assertNotNull($resumed);
        $this->assertSame(SagaStatus::COMPLETED, $resumed->status);
        $this->assertTrue($resumed->context->get('approved'));
        $this->assertSame(20, $resumed->context->get('doubled'));
    }

    public function test_saga_resume_handles_missing_or_terminal_saga(): void
    {
        $this->artisan('saga:resume', ['id' => 'unknown-saga'])
            ->expectsOutput("Saga 'unknown-saga' was not found in storage.")
            ->assertExitCode(1);

        $completedState = new SagaState(
            sagaId: 'cmd-already-done',
            sagaType: CliSuspendableSaga::class,
            status: SagaStatus::COMPLETED,
            context: SagaContext::fromArray([]),
        );
        Saga::repository()->save($completedState);

        $this->artisan('saga:resume', ['id' => 'cmd-already-done'])
            ->expectsOutput("Saga 'cmd-already-done' is already in a terminal state ('completed').")
            ->assertExitCode(0);
    }

    public function test_saga_prune_deletes_old_records(): void
    {
        $oldDate = new DateTimeImmutable('-40 days');
        $recentDate = new DateTimeImmutable('-5 days');

        $oldSaga = new SagaState(
            sagaId: 'prune-old-1',
            sagaType: 'TestSaga',
            status: SagaStatus::COMPLETED,
            context: SagaContext::fromArray([]),
            createdAt: $oldDate,
            updatedAt: $oldDate,
        );

        $recentSaga = new SagaState(
            sagaId: 'prune-recent-1',
            sagaType: 'TestSaga',
            status: SagaStatus::COMPLETED,
            context: SagaContext::fromArray([]),
            createdAt: $recentDate,
            updatedAt: $recentDate,
        );

        Saga::repository()->save($oldSaga);
        Saga::repository()->save($recentSaga);

        $this->artisan('saga:prune', ['--days' => '30'])
            ->expectsOutput('✓ Successfully pruned 1 saga record(s).')
            ->assertExitCode(0);

        $this->assertFalse(Saga::exists('prune-old-1'));
        $this->assertTrue(Saga::exists('prune-recent-1'));
    }
}

// ----------------- Fixtures -----------------

class CliStepOne implements StepInterface
{
    public function getName(): string
    {
        return 'CliStepOne';
    }

    public function execute(SagaContext $context): void
    {
        $context->suspend('Awaiting CLI resume test');
    }

    public function compensate(SagaContext $context): void {}
}

class CliStepTwo implements StepInterface
{
    public function getName(): string
    {
        return 'CliStepTwo';
    }

    public function execute(SagaContext $context): void
    {
        $num = (int) $context->get('num', 0);
        $context->set('doubled', $num * 2);
    }

    public function compensate(SagaContext $context): void {}
}

class CliSuspendableSaga extends AbstractSaga
{
    public function configure(SagaBuilder $builder): void
    {
        $builder
            ->addStep(CliStepOne::class)
            ->addStep(CliStepTwo::class);
    }
}
