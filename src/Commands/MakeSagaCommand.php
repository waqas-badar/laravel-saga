<?php

declare(strict_types=1);

namespace LaravelSaga\Commands;

use Illuminate\Console\GeneratorCommand;

class MakeSagaCommand extends GeneratorCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'make:saga';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new Saga workflow class';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Saga';

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return __DIR__ . '/../../stubs/saga.stub';
    }

    /**
     * Get the default namespace for the class.
     *
     * @param string $rootNamespace
     */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace . '\Sagas';
    }
}

