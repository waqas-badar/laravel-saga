<?php

declare(strict_types=1);

namespace LaravelSaga\Commands;

use Illuminate\Console\Command;
use JsonException;
use LaravelSaga\Facades\Saga;
use PhpSaga\Contracts\SagaInterface;

class ResumeSagaCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'saga:resume
                            {id : The unique saga ID to resume}
                            {--saga= : Class name of the Saga workflow declaration}
                            {--context= : Additional context payload encoded as JSON}
                            {--driver= : Specific repository driver}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Resume execution of an interrupted or suspended Saga workflow';

    public function handle(): int
    {
        $id = (string) $this->argument('id');
        $driver = $this->option('driver') ? (string) $this->option('driver') : null;

        $state = Saga::find($id, $driver);

        if ($state === null) {
            $this->error("Saga '{$id}' was not found in storage.");
            return self::FAILURE;
        }

        if ($state->status->isTerminal()) {
            $this->warn("Saga '{$id}' is already in a terminal state ('{$state->status->value}').");
            return self::SUCCESS;
        }

        $sagaClass = $this->option('saga');

        if (!$sagaClass) {
            /** @var \LaravelSaga\SagaManager $manager */
            $manager = app(\LaravelSaga\SagaManager::class);
            $sagaClass = $manager->getSagaClass($state->sagaType) ?? $state->sagaType;
        }

        if (!class_exists($sagaClass) || !is_subclass_of($sagaClass, SagaInterface::class)) {
            $this->error("Cannot resume: Saga class '{$sagaClass}' is not a valid implementation of " . SagaInterface::class . ".");
            $this->line("Please specify the saga class explicitly using --saga=YourSagaClass");
            return self::FAILURE;
        }

        $additionalContext = [];
        $contextOption = $this->option('context');
        if ($contextOption) {
            try {
                $decoded = json_decode((string) $contextOption, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $additionalContext = $decoded;
                }
            } catch (JsonException $e) {
                $this->error("Invalid JSON provided in --context: {$e->getMessage()}");
                return self::FAILURE;
            }
        }

        $this->info("Resuming saga '{$id}' ({$sagaClass})...");

        $result = Saga::resume($id, $sagaClass, $additionalContext, $driver);

        if ($result->isSuccessful()) {
            $this->info("✓ Saga completed successfully!");
            return self::SUCCESS;
        }

        if ($result->isSuspended()) {
            $reason = $result->getState()->exitReason ?? 'No reason provided';
            $this->warn("⏸ Saga suspended again: {$reason}");
            return self::SUCCESS;
        }

        if ($result->isCompensated()) {
            $error = $result->getExecutionException()?->getMessage() ?? 'Unknown error';
            $this->error("✕ Saga execution failed and was compensated: {$error}");
            return self::FAILURE;
        }

        $this->error("✕ Saga execution failed completely.");
        return self::FAILURE;
    }
}
