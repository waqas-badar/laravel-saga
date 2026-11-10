<?php

declare(strict_types=1);

namespace LaravelSaga\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use LaravelSaga\DatabaseSagaRepository;
use LaravelSaga\Facades\Saga;

class PruneSagasCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'saga:prune
                            {--days= : The number of days of history to retain}
                            {--status=* : The terminal statuses to prune (default: completed, compensated, failed)}
                            {--driver= : Specific repository driver}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune old completed and terminal Saga records from storage';

    public function handle(): int
    {
        $driver = $this->option('driver') ? (string) $this->option('driver') : null;
        $repo = Saga::repository($driver);

        if (!$repo instanceof DatabaseSagaRepository) {
            $this->error("Pruning is currently only supported for DatabaseSagaRepository.");
            return self::FAILURE;
        }

        /** @var int $days */
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('saga.prune.days', 30);

        if ($days < 0) {
            $this->error("The --days option must be 0 or greater.");
            return self::FAILURE;
        }

        $statuses = $this->option('status');
        if (empty($statuses)) {
            $statuses = config('saga.prune.statuses', ['completed', 'compensated', 'failed']);
        }

        $cutoff = new DateTimeImmutable("-{$days} days");

        $this->info("Pruning terminal sagas older than {$cutoff->format('Y-m-d H:i:s')} ({$days} days)...");

        $deleted = $repo->prune($cutoff, is_array($statuses) ? $statuses : [$statuses]);

        $this->info("✓ Successfully pruned {$deleted} saga record(s).");

        return self::SUCCESS;
    }
}

