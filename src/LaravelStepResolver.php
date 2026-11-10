<?php

declare(strict_types=1);

namespace LaravelSaga;

use Illuminate\Contracts\Container\Container;
use PhpSaga\Contracts\StepInterface;
use PhpSaga\Contracts\StepResolverInterface;
use PhpSaga\Exceptions\InvalidSagaDefinitionException;

/**
 * Resolves Saga steps via Laravel's Service Container, enabling full
 * dependency injection (repositories, HTTP clients, services, etc.) into step classes.
 */
class LaravelStepResolver implements StepResolverInterface
{
    public function __construct(
        private readonly Container $container
    ) {}

    /**
     * Resolves an instance of StepInterface.
     *
     * @param StepInterface|class-string<StepInterface> $step
     * @return StepInterface
     * @throws InvalidSagaDefinitionException
     */
    public function resolve(StepInterface|string $step): StepInterface
    {
        if ($step instanceof StepInterface) {
            return $step;
        }

        if (!class_exists($step)) {
            throw new InvalidSagaDefinitionException("Step class '{$step}' does not exist.");
        }

        try {
            $resolved = $this->container->make($step);
        } catch (\Throwable $e) {
            throw new InvalidSagaDefinitionException(
                "Failed to resolve step '{$step}' from Laravel container: {$e->getMessage()}",
                0,
                $e
            );
        }

        if (!$resolved instanceof StepInterface) {
            $type = get_debug_type($resolved);
            throw new InvalidSagaDefinitionException(
                "Resolved step '{$step}' must implement " . StepInterface::class . ", got {$type}."
            );
        }

        return $resolved;
    }
}

