<?php

declare(strict_types=1);

namespace LaravelSaga\Commands;

use Illuminate\Console\Command;
use LaravelSaga\Facades\Saga;

class ShowSagaCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'saga:show
                            {id : The unique saga ID}
                            {--driver= : Specific repository driver to inspect}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Inspect and display execution details of a Saga instance';

    public function handle(): int
    {
        $id = (string) $this->argument('id');
        $driver = $this->option('driver') ? (string) $this->option('driver') : null;

        $state = Saga::find($id, $driver);

        if ($state === null) {
            $this->error("Saga '{$id}' was not found in storage.");
            return self::FAILURE;
        }

        $this->info("Saga Details: {$state->sagaId}");
        $this->newLine();

        $this->table(
            ['Property', 'Value'],
            [
                ['ID', $state->sagaId],
                ['Type', $state->sagaType],
                ['Status', $state->status->value],
                ['Current Step', $state->currentStep ?? '-'],
                ['Exit Reason', $state->exitReason ?? '-'],
                ['Created At', $state->createdAt->format('Y-m-d H:i:s T')],
                ['Updated At', $state->updatedAt->format('Y-m-d H:i:s T')],
            ]
        );

        $this->newLine();
        $this->line('<comment>Completed Steps:</comment>');
        if (empty($state->completedSteps)) {
            $this->line('  (None)');
        } else {
            foreach ($state->completedSteps as $index => $stepName) {
                $num = $index + 1;
                $this->line("  {$num}. {$stepName}");
            }
        }

        $this->newLine();
        $this->line('<comment>Context Data:</comment>');
        $json = json_encode($state->context->jsonSerialize(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->line($json ?: '{}');

        return self::SUCCESS;
    }
}

