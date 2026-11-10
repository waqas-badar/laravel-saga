<?php

declare(strict_types=1);

namespace LaravelSaga;

use Illuminate\Contracts\Events\Dispatcher;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Bridges PSR-14 event dispatching from php-saga to Laravel's Event Dispatcher.
 */
class LaravelEventDispatcherBridge implements EventDispatcherInterface
{
    public function __construct(
        private readonly Dispatcher $dispatcher
    ) {}

    /**
     * Dispatch an event to Laravel's event dispatcher.
     */
    public function dispatch(object $event): object
    {
        $this->dispatcher->dispatch($event);

        return $event;
    }
}

