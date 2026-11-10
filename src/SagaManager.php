<?php

declare(strict_types=1);

namespace LaravelSaga;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Bus\PendingDispatch;
use InvalidArgumentException;
use LaravelSaga\Jobs\ExecuteSagaJob;
use LaravelSaga\Jobs\ResumeSagaJob;
use LaravelSaga\Testing\SagaFake;
use PhpSaga\Contracts\SagaInterface;
use PhpSaga\Contracts\SagaRepositoryInterface;
use PhpSaga\Contracts\StepResolverInterface;
use PhpSaga\Execution\SagaBuilder;
use PhpSaga\Execution\SagaCoordinator;
use PhpSaga\Execution\SagaDefinition;
use PhpSaga\Execution\SagaResult;
use PhpSaga\SagaContext;
use PhpSaga\SagaState;
use PhpSaga\SagaStatus;
use PhpSaga\Storage\FileSagaRepository;
use PhpSaga\Storage\InMemorySagaRepository;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Central manager and coordinator factory for Laravel Saga.
 */
class SagaManager
{
    /**
     * @var array<string, SagaRepositoryInterface>
     */
    private array $repositories = [];

    /**
     * @var array<string, SagaCoordinator>
     */
    private array $coordinators = [];

    /**
     * @var array<string, Closure>
     */
    private array $customDrivers = [];

    /**
     * @var array<string, class-string<SagaInterface>>
     */
    private array $sagaMap = [];

    public function __construct(
        private readonly Container $container
    ) {}

    /**
     * Get a saga coordinator instance for a specific repository driver.
     */
    public function coordinator(?string $driver = null): SagaCoordinator
    {
        $driver = $driver ?? $this->getDefaultDriver();

        if (!isset($this->coordinators[$driver])) {
            $this->coordinators[$driver] = $this->createCoordinator($driver);
        }

        return $this->coordinators[$driver];
    }

    /**
     * Get a saga repository instance for a specific driver.
     */
    public function repository(?string $driver = null): SagaRepositoryInterface
    {
        $driver = $driver ?? $this->getDefaultDriver();

        if (!isset($this->repositories[$driver])) {
            $this->repositories[$driver] = $this->createRepository($driver);
        }

        return $this->repositories[$driver];
    }

    /**
     * Execute a saga workflow.
     *
     * @param SagaDefinition|SagaInterface|class-string<SagaInterface> $saga
     * @param SagaContext|array<string, mixed> $context
     * @param string|null $sagaId
     * @param string|null $driver
     */
    public function run(
        SagaDefinition|SagaInterface|string $saga,
        SagaContext|array $context = [],
        ?string $sagaId = null,
        ?string $driver = null,
    ): SagaResult {
        $resolvedSaga = $this->resolveSaga($saga);

        return $this->coordinator($driver)->run($resolvedSaga, $context, $sagaId);
    }

    /**
     * Resume a suspended or interrupted saga workflow.
     *
     * @param string $sagaId
     * @param SagaDefinition|SagaInterface|class-string<SagaInterface> $saga
     * @param SagaContext|array<string, mixed> $additionalContext
     * @param string|null $driver
     */
    public function resume(
        string $sagaId,
        SagaDefinition|SagaInterface|string $saga,
        SagaContext|array $additionalContext = [],
        ?string $driver = null,
    ): SagaResult {
        $resolvedSaga = $this->resolveSaga($saga);

        return $this->coordinator($driver)->resume($sagaId, $resolvedSaga, $additionalContext);
    }

    /**
     * Dispatch a saga to the background queue for asynchronous execution.
     *
     * @param string $saga Class name or alias
     * @param array<string, mixed> $context Initial context variables
     * @param string|null $sagaId Custom saga identifier
     * @param string|null $driver Repository driver
     */
    public function dispatch(
        string $saga,
        array $context = [],
        ?string $sagaId = null,
        ?string $driver = null
    ): PendingDispatch {
        return ExecuteSagaJob::dispatch($saga, $context, $sagaId, $driver);
    }

    /**
     * Dispatch a saga resumption to the background queue.
     *
     * @param string $sagaId Saga identifier to resume
     * @param string $saga Class name or alias
     * @param array<string, mixed> $additionalContext Incoming additional context
     * @param string|null $driver Repository driver
     */
    public function dispatchResume(
        string $sagaId,
        string $saga,
        array $additionalContext = [],
        ?string $driver = null
    ): PendingDispatch {
        return ResumeSagaJob::dispatch($sagaId, $saga, $additionalContext, $driver);
    }

    /**
     * Replace the Saga manager with a test fake.
     *
     * @param array<int, string>|null $sagasToFake
     */
    public function fake(?array $sagasToFake = null): SagaFake
    {
        $fake = new SagaFake($this, $sagasToFake);
        $this->container->instance('saga', $fake);
        $this->container->instance(SagaManager::class, $fake);

        return $fake;
    }

    /**
     * Retrieve a persisted Saga state snapshot.
     */
    public function find(string $sagaId, ?string $driver = null): ?SagaState
    {
        return $this->repository($driver)->find($sagaId);
    }

    /**
     * Check if a saga instance exists.
     */
    public function exists(string $sagaId, ?string $driver = null): bool
    {
        return $this->repository($driver)->exists($sagaId);
    }

    /**
     * Get the current status of a saga instance.
     */
    public function status(string $sagaId, ?string $driver = null): ?SagaStatus
    {
        return $this->find($sagaId, $driver)?->status;
    }

    /**
     * Create a new fluent SagaBuilder.
     */
    public function builder(string $sagaType): SagaBuilder
    {
        return SagaBuilder::new($sagaType);
    }

    /**
     * Register a custom repository driver creator.
     */
    public function extend(string $driver, Closure $callback): self
    {
        $this->customDrivers[$driver] = $callback;
        unset($this->repositories[$driver], $this->coordinators[$driver]);

        return $this;
    }

    /**
     * Register a saga class by name or alias.
     *
     * @param string $name
     * @param class-string<SagaInterface> $sagaClass
     */
    public function registerSaga(string $name, string $sagaClass): self
    {
        $this->sagaMap[$name] = $sagaClass;

        return $this;
    }

    /**
     * Retrieve the saga class associated with a name or alias.
     */
    public function getSagaClass(string $name): ?string
    {
        if (isset($this->sagaMap[$name])) {
            return $this->sagaMap[$name];
        }

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $this->container->make('config');
        $configured = $config->get('saga.sagas', []);

        return $configured[$name] ?? null;
    }

    public function getDefaultDriver(): string
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $this->container->make('config');

        return (string) $config->get('saga.default', 'database');
    }

    /**
     * Resolve a saga definition or class-string.
     */
    private function resolveSaga(SagaDefinition|SagaInterface|string $saga): SagaDefinition|SagaInterface
    {
        if (is_string($saga)) {
            $class = class_exists($saga) ? $saga : $this->getSagaClass($saga);

            if ($class === null || !class_exists($class)) {
                throw new InvalidArgumentException("Saga class '{$saga}' does not exist.");
            }

            $instance = $this->container->make($class);
            if (!$instance instanceof SagaInterface) {
                throw new InvalidArgumentException(
                    "Saga class '{$class}' must implement " . SagaInterface::class
                );
            }

            return $instance;
        }

        return $saga;
    }

    private function createCoordinator(string $driver): SagaCoordinator
    {
        $repository = $this->repository($driver);
        $stepResolver = $this->resolveStepResolver();
        $dispatcher = $this->resolveDispatcher();

        return new SagaCoordinator(
            repository: $repository,
            dispatcher: $dispatcher,
            stepResolver: $stepResolver,
        );
    }

    private function createRepository(string $driver): SagaRepositoryInterface
    {
        if (isset($this->customDrivers[$driver])) {
            return ($this->customDrivers[$driver])($this->container);
        }

        return match ($driver) {
            'database' => $this->createDatabaseRepository(),
            'file' => $this->createFileRepository(),
            'in_memory' => new InMemorySagaRepository(),
            default => throw new InvalidArgumentException("Unsupported saga repository driver: '{$driver}'"),
        };
    }

    private function createDatabaseRepository(): DatabaseSagaRepository
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $this->container->make('config');
        $repoConfig = $config->get('saga.repositories.database', []);
        $connection = $repoConfig['connection'] ?? null;
        $table = $repoConfig['table'] ?? 'sagas';

        return new DatabaseSagaRepository(
            databaseManager: $this->container->make('db'),
            connectionName: $connection,
            tableName: $table,
        );
    }

    private function createFileRepository(): FileSagaRepository
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $this->container->make('config');
        $repoConfig = $config->get('saga.repositories.file', []);
        $path = $repoConfig['path'] ?? storage_path('framework/sagas');

        return new FileSagaRepository($path);
    }

    private function resolveStepResolver(): StepResolverInterface
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $this->container->make('config');
        $resolverClass = $config->get('saga.step_resolver', LaravelStepResolver::class);

        $resolver = $this->container->make($resolverClass);
        if (!$resolver instanceof StepResolverInterface) {
            throw new InvalidArgumentException(
                "Configured step resolver '{$resolverClass}' must implement " . StepResolverInterface::class
            );
        }

        return $resolver;
    }

    private function resolveDispatcher(): ?EventDispatcherInterface
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $this->container->make('config');
        $eventsEnabled = (bool) $config->get('saga.events.enabled', true);
        if (!$eventsEnabled) {
            return null;
        }

        $dispatcher = $this->container->make('events');

        if ($dispatcher instanceof EventDispatcherInterface) {
            return $dispatcher;
        }

        if ($dispatcher instanceof Dispatcher) {
            return new LaravelEventDispatcherBridge($dispatcher);
        }

        return null;
    }
}

