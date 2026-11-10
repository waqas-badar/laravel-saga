<?php

declare(strict_types=1);

namespace LaravelSaga\Testing;

use Closure;
use LaravelSaga\SagaManager;
use PHPUnit\Framework\Assert as PHPUnit;
use PhpSaga\Contracts\SagaInterface;
use PhpSaga\Contracts\SagaRepositoryInterface;
use PhpSaga\Execution\SagaBuilder;
use PhpSaga\Execution\SagaCoordinator;
use PhpSaga\Execution\SagaDefinition;
use PhpSaga\Execution\SagaResult;
use PhpSaga\SagaContext;
use PhpSaga\SagaState;
use PhpSaga\SagaStatus;

class SagaFake
{
    /**
     * @var array<int, array{saga: string, context: array<string, mixed>, sagaId: string, driver: ?string}>
     */
    private array $runs = [];

    /**
     * @var array<int, array{sagaId: string, saga: string, additionalContext: array<string, mixed>, driver: ?string}>
     */
    private array $resumes = [];

    /**
     * @var array<int, array{saga: string, context: array<string, mixed>, sagaId: ?string, driver: ?string}>
     */
    private array $dispatches = [];

    /**
     * @var array<int, array{sagaId: string, saga: string, additionalContext: array<string, mixed>, driver: ?string}>
     */
    private array $dispatchedResumes = [];

    /**
     * @param SagaManager $manager Underlying manager instance
     * @param array<int, string>|null $sagasToFake Optional whitelist of saga classes to fake
     */
    public function __construct(
        private readonly SagaManager $manager,
        private readonly ?array $sagasToFake = null,
    ) {}

    /**
     * Determine if a particular saga should be faked.
     */
    public function shouldFake(string $saga): bool
    {
        if ($this->sagasToFake === null) {
            return true;
        }

        foreach ($this->sagasToFake as $faked) {
            if ($faked === $saga || is_a($saga, $faked, true)) {
                return true;
            }
        }

        return false;
    }

    public function run(
        SagaDefinition|SagaInterface|string $saga,
        SagaContext|array $context = [],
        ?string $sagaId = null,
        ?string $driver = null,
    ): SagaResult {
        $sagaName = $this->extractSagaName($saga);

        if (!$this->shouldFake($sagaName)) {
            return $this->manager->run($saga, $context, $sagaId, $driver);
        }

        $id = $sagaId ?? bin2hex(random_bytes(16));
        $contextArray = $context instanceof SagaContext ? $context->all() : $context;
        $sagaContext = $context instanceof SagaContext ? $context : SagaContext::fromArray($contextArray);

        $this->runs[] = [
            'saga' => $sagaName,
            'context' => $contextArray,
            'sagaId' => $id,
            'driver' => $driver,
        ];

        $state = new SagaState(
            sagaId: $id,
            sagaType: $sagaName,
            status: SagaStatus::COMPLETED,
            context: $sagaContext,
        );

        return new SagaResult(
            state: $state,
            context: $sagaContext,
            isSuccessful: true,
            isCompensated: false,
            isSuspended: false,
        );
    }

    public function resume(
        string $sagaId,
        SagaDefinition|SagaInterface|string $saga,
        SagaContext|array $additionalContext = [],
        ?string $driver = null,
    ): SagaResult {
        $sagaName = $this->extractSagaName($saga);

        if (!$this->shouldFake($sagaName)) {
            return $this->manager->resume($sagaId, $saga, $additionalContext, $driver);
        }

        $contextArray = $additionalContext instanceof SagaContext ? $additionalContext->all() : $additionalContext;
        $sagaContext = $additionalContext instanceof SagaContext ? $additionalContext : SagaContext::fromArray($contextArray);

        $this->resumes[] = [
            'sagaId' => $sagaId,
            'saga' => $sagaName,
            'additionalContext' => $contextArray,
            'driver' => $driver,
        ];

        $state = new SagaState(
            sagaId: $sagaId,
            sagaType: $sagaName,
            status: SagaStatus::COMPLETED,
            context: $sagaContext,
        );

        return new SagaResult(
            state: $state,
            context: $sagaContext,
            isSuccessful: true,
            isCompensated: false,
            isSuspended: false,
        );
    }

    public function dispatch(
        string $saga,
        array $context = [],
        ?string $sagaId = null,
        ?string $driver = null
    ): FakePendingDispatch {
        $this->dispatches[] = [
            'saga' => $saga,
            'context' => $context,
            'sagaId' => $sagaId,
            'driver' => $driver,
        ];

        return new FakePendingDispatch();
    }

    public function dispatchResume(
        string $sagaId,
        string $saga,
        array $additionalContext = [],
        ?string $driver = null
    ): FakePendingDispatch {
        $this->dispatchedResumes[] = [
            'sagaId' => $sagaId,
            'saga' => $saga,
            'additionalContext' => $additionalContext,
            'driver' => $driver,
        ];

        return new FakePendingDispatch();
    }

    // ----------------- Assertions -----------------

    /**
     * Assert that a saga was run.
     *
     * @param string $saga Class name or identifier
     * @param (callable(array<string, mixed>, string): bool)|null $callback
     */
    public function assertRun(string $saga, ?callable $callback = null): void
    {
        $matching = array_filter($this->runs, function ($record) use ($saga, $callback) {
            if ($record['saga'] !== $saga && !is_a($record['saga'], $saga, true)) {
                return false;
            }

            if ($callback === null) {
                return true;
            }

            return $callback($record['context'], $record['sagaId']);
        });

        PHPUnit::assertTrue(
            count($matching) > 0,
            "The expected saga [{$saga}] was not run."
        );
    }

    /**
     * Assert that a saga was not run.
     */
    public function assertNotRun(string $saga, ?callable $callback = null): void
    {
        $matching = array_filter($this->runs, function ($record) use ($saga, $callback) {
            if ($record['saga'] !== $saga && !is_a($record['saga'], $saga, true)) {
                return false;
            }

            if ($callback === null) {
                return true;
            }

            return $callback($record['context'], $record['sagaId']);
        });

        PHPUnit::assertCount(
            0,
            $matching,
            "The unexpected saga [{$saga}] was run."
        );
    }

    /**
     * Assert total count of sagas run.
     */
    public function assertRunCount(int $expectedCount): void
    {
        $actual = count($this->runs);
        PHPUnit::assertSame(
            $expectedCount,
            $actual,
            "Expected {$expectedCount} sagas to be run, but {$actual} were run."
        );
    }

    /**
     * Assert that no sagas were run.
     */
    public function assertNothingRun(): void
    {
        PHPUnit::assertEmpty($this->runs, 'Expected no sagas to be run, but sagas were executed.');
    }

    /**
     * Assert that a saga was resumed.
     *
     * @param string $sagaId
     * @param (callable(array<string, mixed>, string): bool)|null $callback
     */
    public function assertResumed(string $sagaId, ?callable $callback = null): void
    {
        $matching = array_filter($this->resumes, function ($record) use ($sagaId, $callback) {
            if ($record['sagaId'] !== $sagaId) {
                return false;
            }

            if ($callback === null) {
                return true;
            }

            return $callback($record['additionalContext'], $record['saga']);
        });

        PHPUnit::assertTrue(
            count($matching) > 0,
            "The expected saga resumption for ID [{$sagaId}] was not performed."
        );
    }

    /**
     * Assert that a saga was not resumed.
     */
    public function assertNotResumed(string $sagaId, ?callable $callback = null): void
    {
        $matching = array_filter($this->resumes, function ($record) use ($sagaId, $callback) {
            if ($record['sagaId'] !== $sagaId) {
                return false;
            }

            if ($callback === null) {
                return true;
            }

            return $callback($record['additionalContext'], $record['saga']);
        });

        PHPUnit::assertCount(
            0,
            $matching,
            "The unexpected saga resumption for ID [{$sagaId}] was performed."
        );
    }

    /**
     * Assert that a saga was dispatched to the background queue.
     *
     * @param string $saga
     * @param (callable(array<string, mixed>, ?string): bool)|null $callback
     */
    public function assertDispatched(string $saga, ?callable $callback = null): void
    {
        $matching = array_filter($this->dispatches, function ($record) use ($saga, $callback) {
            if ($record['saga'] !== $saga && !is_a($record['saga'], $saga, true)) {
                return false;
            }

            if ($callback === null) {
                return true;
            }

            return $callback($record['context'], $record['sagaId']);
        });

        PHPUnit::assertTrue(
            count($matching) > 0,
            "The expected saga [{$saga}] was not dispatched to the queue."
        );
    }

    /**
     * Assert that a saga was not dispatched to the queue.
     */
    public function assertNotDispatched(string $saga, ?callable $callback = null): void
    {
        $matching = array_filter($this->dispatches, function ($record) use ($saga, $callback) {
            if ($record['saga'] !== $saga && !is_a($record['saga'], $saga, true)) {
                return false;
            }

            if ($callback === null) {
                return true;
            }

            return $callback($record['context'], $record['sagaId']);
        });

        PHPUnit::assertCount(
            0,
            $matching,
            "The unexpected saga [{$saga}] was dispatched to the queue."
        );
    }

    /**
     * Assert that a saga resumption was dispatched to the queue.
     */
    public function assertDispatchedResume(string $sagaId, ?callable $callback = null): void
    {
        $matching = array_filter($this->dispatchedResumes, function ($record) use ($sagaId, $callback) {
            if ($record['sagaId'] !== $sagaId) {
                return false;
            }

            if ($callback === null) {
                return true;
            }

            return $callback($record['additionalContext'], $record['saga']);
        });

        PHPUnit::assertTrue(
            count($matching) > 0,
            "The expected saga resumption for [{$sagaId}] was not dispatched to the queue."
        );
    }

    // ----------------- Fallthrough to Manager -----------------

    public function coordinator(?string $driver = null): SagaCoordinator
    {
        return $this->manager->coordinator($driver);
    }

    public function repository(?string $driver = null): SagaRepositoryInterface
    {
        return $this->manager->repository($driver);
    }

    public function find(string $sagaId, ?string $driver = null): ?SagaState
    {
        return $this->manager->find($sagaId, $driver);
    }

    public function exists(string $sagaId, ?string $driver = null): bool
    {
        return $this->manager->exists($sagaId, $driver);
    }

    public function status(string $sagaId, ?string $driver = null): ?SagaStatus
    {
        return $this->manager->status($sagaId, $driver);
    }

    public function builder(string $sagaType): SagaBuilder
    {
        return $this->manager->builder($sagaType);
    }

    public function extend(string $driver, Closure $callback): self
    {
        $this->manager->extend($driver, $callback);

        return $this;
    }

    public function registerSaga(string $name, string $sagaClass): self
    {
        $this->manager->registerSaga($name, $sagaClass);

        return $this;
    }

    public function getSagaClass(string $name): ?string
    {
        return $this->manager->getSagaClass($name);
    }

    private function extractSagaName(SagaDefinition|SagaInterface|string $saga): string
    {
        if (is_string($saga)) {
            return $saga;
        }

        if ($saga instanceof SagaDefinition) {
            return $saga->getSagaType();
        }

        return $saga->getName();
    }
}

/**
 * Fluent placeholder for chaining queue parameters when faked.
 */
class FakePendingDispatch
{
    public function onConnection(?string $connection): self
    {
        return $this;
    }

    public function onQueue(?string $queue): self
    {
        return $this;
    }

    public function delay(mixed $delay): self
    {
        return $this;
    }
}
