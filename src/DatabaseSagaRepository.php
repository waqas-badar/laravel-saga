<?php

declare(strict_types=1);

namespace LaravelSaga;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use PhpSaga\Contracts\SagaRepositoryInterface;
use PhpSaga\SagaContext;
use PhpSaga\SagaState;
use PhpSaga\SagaStatus;

/**
 * Persists Saga execution state and snapshots into a relational database via Laravel Query Builder.
 */
class DatabaseSagaRepository implements SagaRepositoryInterface
{
    public function __construct(
        private readonly DatabaseManager $databaseManager,
        private readonly ?string $connectionName = null,
        private readonly string $tableName = 'sagas',
    ) {}

    public function getConnection(): ConnectionInterface
    {
        return $this->databaseManager->connection($this->connectionName);
    }

    public function getTable(): Builder
    {
        return $this->getConnection()->table($this->tableName);
    }

    public function save(SagaState $state): void
    {
        $data = [
            'saga_type' => $state->sagaType,
            'status' => $state->status->value,
            'context' => json_encode($state->context->jsonSerialize(), JSON_THROW_ON_ERROR),
            'current_step' => $state->currentStep,
            'completed_steps' => json_encode($state->completedSteps, JSON_THROW_ON_ERROR),
            'exit_reason' => $state->exitReason,
            'updated_at' => $state->updatedAt->format('Y-m-d H:i:s'),
        ];

        if ($this->exists($state->sagaId)) {
            $this->getTable()->where('saga_id', $state->sagaId)->update($data);
        } else {
            $data['saga_id'] = $state->sagaId;
            $data['created_at'] = $state->createdAt->format('Y-m-d H:i:s');
            $this->getTable()->insert($data);
        }
    }

    public function find(string $sagaId): ?SagaState
    {
        $row = $this->getTable()->where('saga_id', $sagaId)->first();

        return $row ? $this->mapRowToState((array) $row) : null;
    }

    /**
     * Retrieves saga state with row-level locking (SELECT ... FOR UPDATE)
     * to protect against concurrent execution or resumption race conditions.
     */
    public function findForUpdate(string $sagaId): ?SagaState
    {
        $row = $this->getTable()->where('saga_id', $sagaId)->lockForUpdate()->first();

        return $row ? $this->mapRowToState((array) $row) : null;
    }

    public function exists(string $sagaId): bool
    {
        return $this->getTable()->where('saga_id', $sagaId)->exists();
    }

    public function updateStatus(
        string $sagaId,
        SagaStatus $status,
        ?string $currentStep = null,
        ?string $exitReason = null
    ): void {
        $now = new DateTimeImmutable();
        $update = [
            'status' => $status->value,
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ];

        if ($currentStep !== null) {
            $update['current_step'] = $currentStep;
        }

        if ($exitReason !== null) {
            $update['exit_reason'] = $exitReason;
        }

        $this->getTable()->where('saga_id', $sagaId)->update($update);
    }

    public function updateContext(string $sagaId, SagaContext $context): void
    {
        $now = new DateTimeImmutable();
        $this->getTable()->where('saga_id', $sagaId)->update([
            'context' => json_encode($context->jsonSerialize(), JSON_THROW_ON_ERROR),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    public function delete(string $sagaId): void
    {
        $this->getTable()->where('saga_id', $sagaId)->delete();
    }

    /**
     * Prune sagas older than the given timestamp, optionally filtered by status.
     *
     * @param DateTimeImmutable $before
     * @param array<int, SagaStatus|string>|null $statuses
     * @return int Number of deleted records
     */
    public function prune(DateTimeImmutable $before, ?array $statuses = null): int
    {
        $query = $this->getTable()->where('created_at', '<', $before->format('Y-m-d H:i:s'));

        if ($statuses !== null && count($statuses) > 0) {
            $statusValues = array_map(
                static fn ($s) => $s instanceof SagaStatus ? $s->value : (string) $s,
                $statuses
            );
            $query->whereIn('status', $statusValues);
        }

        return $query->delete();
    }

    /**
     * Reconstruct a SagaState instance from a database row.
     *
     * @param array<string, mixed> $row
     */
    private function mapRowToState(array $row): SagaState
    {
        $contextData = [];
        if (!empty($row['context'])) {
            $decoded = is_string($row['context'])
                ? json_decode($row['context'], true)
                : (array) $row['context'];
            if (is_array($decoded)) {
                $contextData = $decoded;
            }
        }

        $completedSteps = [];
        if (!empty($row['completed_steps'])) {
            $decoded = is_string($row['completed_steps'])
                ? json_decode($row['completed_steps'], true)
                : (array) $row['completed_steps'];
            if (is_array($decoded)) {
                $completedSteps = array_values(array_map('strval', $decoded));
            }
        }

        $createdAt = isset($row['created_at']) && is_string($row['created_at'])
            ? new DateTimeImmutable($row['created_at'])
            : new DateTimeImmutable();

        $updatedAt = isset($row['updated_at']) && is_string($row['updated_at'])
            ? new DateTimeImmutable($row['updated_at'])
            : new DateTimeImmutable();

        return new SagaState(
            sagaId: (string) $row['saga_id'],
            sagaType: (string) $row['saga_type'],
            status: SagaStatus::from((string) $row['status']),
            context: SagaContext::fromArray($contextData),
            currentStep: !empty($row['current_step']) ? (string) $row['current_step'] : null,
            completedSteps: $completedSteps,
            exitReason: !empty($row['exit_reason']) ? (string) $row['exit_reason'] : null,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }
}

