<?php

declare(strict_types=1);

namespace LaravelSaga\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LaravelSaga\Facades\Saga;
use PhpSaga\Execution\SagaResult;

class ExecuteSagaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param string $saga Saga class name or registered alias
     * @param array<string, mixed> $context Initial context variables
     * @param string|null $sagaId Custom saga identifier
     * @param string|null $driver Repository driver
     */
    public function __construct(
        public readonly string $saga,
        public readonly array $context = [],
        public readonly ?string $sagaId = null,
        public readonly ?string $driver = null,
    ) {
        $connection = config('saga.queue.connection');
        if ($connection !== null) {
            $this->onConnection($connection);
        }

        $queue = config('saga.queue.queue');
        if ($queue !== null) {
            $this->onQueue($queue);
        }
    }

    /**
     * Execute the queued saga workflow.
     */
    public function handle(): SagaResult
    {
        return Saga::run(
            saga: $this->saga,
            context: $this->context,
            sagaId: $this->sagaId,
            driver: $this->driver,
        );
    }
}

