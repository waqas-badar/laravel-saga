<?php

declare(strict_types=1);

namespace LaravelSaga\Facades;

use Closure;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Facade;
use LaravelSaga\SagaManager;
use LaravelSaga\Testing\SagaFake;
use PhpSaga\Contracts\SagaInterface;
use PhpSaga\Contracts\SagaRepositoryInterface;
use PhpSaga\Execution\SagaBuilder;
use PhpSaga\Execution\SagaCoordinator;
use PhpSaga\Execution\SagaDefinition;
use PhpSaga\Execution\SagaResult;
use PhpSaga\SagaContext;
use PhpSaga\SagaState;
use PhpSaga\SagaStatus;

/**
 * @method static SagaResult run(SagaDefinition|SagaInterface|string $saga, SagaContext|array $context = [], ?string $sagaId = null, ?string $driver = null)
 * @method static SagaResult resume(string $sagaId, SagaDefinition|SagaInterface|string $saga, SagaContext|array $additionalContext = [], ?string $driver = null)
 * @method static PendingDispatch dispatch(string $saga, array $context = [], ?string $sagaId = null, ?string $driver = null)
 * @method static PendingDispatch dispatchResume(string $sagaId, string $saga, array $additionalContext = [], ?string $driver = null)
 * @method static SagaFake fake(?array $sagasToFake = null)
 * @method static void assertRun(string $saga, ?callable $callback = null)
 * @method static void assertNotRun(string $saga, ?callable $callback = null)
 * @method static void assertRunCount(int $expectedCount)
 * @method static void assertNothingRun()
 * @method static void assertResumed(string $sagaId, ?callable $callback = null)
 * @method static void assertNotResumed(string $sagaId, ?callable $callback = null)
 * @method static void assertDispatched(string $saga, ?callable $callback = null)
 * @method static void assertNotDispatched(string $saga, ?callable $callback = null)
 * @method static void assertDispatchedResume(string $sagaId, ?callable $callback = null)
 * @method static ?SagaState find(string $sagaId, ?string $driver = null)
 * @method static bool exists(string $sagaId, ?string $driver = null)
 * @method static ?SagaStatus status(string $sagaId, ?string $driver = null)
 * @method static SagaBuilder builder(string $sagaType)
 * @method static SagaCoordinator coordinator(?string $driver = null)
 * @method static SagaRepositoryInterface repository(?string $driver = null)
 * @method static SagaManager extend(string $driver, Closure $callback)
 * @method static SagaManager registerSaga(string $name, string $sagaClass)
 * @method static ?string getSagaClass(string $name)
 *
 * @see \LaravelSaga\SagaManager
 */
class Saga extends Facade
{
    /**
     * Replace the bound saga instance with a fake.
     *
     * @param array<int, string>|null $sagasToFake
     */
    public static function fake(?array $sagasToFake = null): SagaFake
    {
        $manager = static::getFacadeRoot();
        $fake = new SagaFake($manager, $sagasToFake);

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return 'saga';
    }
}

