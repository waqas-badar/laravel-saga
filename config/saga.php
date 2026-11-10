<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Saga Repository Driver
    |--------------------------------------------------------------------------
    |
    | Supported drivers: "database", "file", "in_memory"
    |
    */

    'default' => env('SAGA_DRIVER', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Repository Driver Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may define the settings for each repository driver.
    |
    */

    'repositories' => [
        'database' => [
            'driver' => 'database',
            'connection' => env('SAGA_DB_CONNECTION', null),
            'table' => env('SAGA_TABLE', 'sagas'),
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/sagas'),
        ],

        'in_memory' => [
            'driver' => 'in_memory',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Step Resolver Class
    |--------------------------------------------------------------------------
    |
    | Responsible for resolving StepInterface instances from class-strings.
    | The default resolver uses Laravel's Service Container for dependency injection.
    |
    */

    'step_resolver' => \LaravelSaga\LaravelStepResolver::class,

    /*
    |--------------------------------------------------------------------------
    | Event Dispatching
    |--------------------------------------------------------------------------
    |
    | When enabled, Saga lifecycle events will be forwarded to Laravel's
    | application event dispatcher.
    |
    */

    'events' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | When dispatching sagas or resumptions to background queues via
    | Saga::dispatch() or Saga::dispatchResume(), these settings determine
    | the default connection and queue name.
    |
    */

    'queue' => [
        'connection' => env('SAGA_QUEUE_CONNECTION', null),
        'queue' => env('SAGA_QUEUE', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pruning Configuration
    |--------------------------------------------------------------------------
    |
    | Defines the default number of days to retain terminal saga executions
    | when running the `saga:prune` artisan command.
    |
    */

    'prune' => [
        'days' => (int) env('SAGA_PRUNE_DAYS', 30),
        'statuses' => ['completed', 'compensated', 'failed'],
    ],

];

