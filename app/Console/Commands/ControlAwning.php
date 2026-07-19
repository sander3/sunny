<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TaHomaService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('awning:control {action : The action to perform (open or close)}')]
#[Description('Open or close the sunscreen via the Somfy TaHoma local API')]
class ControlAwning extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(TaHomaService $tahoma): int
    {
        $action = $this->argument('action');

        if (! is_string($action) || ! in_array($action, ['open', 'close'], true)) {
            $this->error('Action must be either "open" or "close".');

            return self::FAILURE;
        }

        try {
            $executionId = $tahoma->controlAwning($action);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Awning %s command sent (execution: %s).',
            $action,
            $executionId,
        ));

        return self::SUCCESS;
    }
}
