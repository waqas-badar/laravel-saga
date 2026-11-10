<?php

declare(strict_types=1);

namespace LaravelSaga\Tests\Unit;

use LaravelSaga\LaravelStepResolver;
use LaravelSaga\Tests\TestCase;
use PhpSaga\Contracts\StepInterface;
use PhpSaga\Exceptions\InvalidSagaDefinitionException;
use PhpSaga\SagaContext;

class LaravelStepResolverTest extends TestCase
{
    public function test_resolves_step_instance_directly(): void
    {
        $resolver = new LaravelStepResolver($this->app);
        $step = new DummyStep();

        $resolved = $resolver->resolve($step);

        $this->assertSame($step, $resolved);
    }

    public function test_resolves_step_class_with_container_dependency_injection(): void
    {
        $this->app->instance(DummyDependency::class, new DummyDependency('injected-value'));

        $resolver = new LaravelStepResolver($this->app);
        $resolved = $resolver->resolve(StepWithDependency::class);

        $this->assertInstanceOf(StepWithDependency::class, $resolved);
        $this->assertSame('injected-value', $resolved->dependency->value);
    }

    public function test_throws_exception_if_class_does_not_exist(): void
    {
        $resolver = new LaravelStepResolver($this->app);

        $this->expectException(InvalidSagaDefinitionException::class);
        $this->expectExceptionMessage("Step class 'NonExistentStepClass' does not exist.");

        $resolver->resolve('NonExistentStepClass');
    }

    public function test_throws_exception_if_class_does_not_implement_step_interface(): void
    {
        $resolver = new LaravelStepResolver($this->app);

        $this->expectException(InvalidSagaDefinitionException::class);
        $this->expectExceptionMessage('must implement PhpSaga\Contracts\StepInterface');

        $resolver->resolve(InvalidStepClass::class);
    }
}

class DummyStep implements StepInterface
{
    public function getName(): string
    {
        return 'DummyStep';
    }

    public function execute(SagaContext $context): void
    {
        $context->set('dummy', true);
    }

    public function compensate(SagaContext $context): void
    {
        $context->remove('dummy');
    }
}

class DummyDependency
{
    public function __construct(public string $value) {}
}

class StepWithDependency implements StepInterface
{
    public function __construct(public DummyDependency $dependency) {}

    public function getName(): string
    {
        return 'StepWithDependency';
    }

    public function execute(SagaContext $context): void {}

    public function compensate(SagaContext $context): void {}
}

class InvalidStepClass {}

