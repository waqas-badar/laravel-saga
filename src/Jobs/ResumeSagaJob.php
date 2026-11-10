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

class ResumeSagaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param string $sagaId Saga identifier to resume
     * @param string $saga Saga class name or registered alias
     * @param array<string, mixed> $additionalContext Incoming additional context data
     * @param string|null $driver Repository driver
     */
    public function __construct(
        public readonly string $sagaId,
        public readonly string $saga,
        public readonly array $additionalContext = [],
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
     * Resume the queued saga workflow.
     */
    public function handle(): SagaResult
    {
        return Saga::resume(
            sagaId: $this->sagaId,
            saga: $this->saga,
            additionalContext: $this->additionalContext,
            driver: $this->driver,
        );
    }
}

