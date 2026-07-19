<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AwningAutoService;
use App\Services\TaHomaService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('awning:control {action : The action to perform (open, close, or auto)}')]
#[Description('Open, close, or automatically control the sunscreen via Somfy TaHoma and Open-Meteo')]
class ControlAwning extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(TaHomaService $tahoma, AwningAutoService $awningAuto): int
    {
        $action = $this->argument('action');

        if (! in_array($action, ['open', 'close', 'auto'], true)) {
            $this->error('Action must be either "open", "close", or "auto".');

            return self::FAILURE;
        }

        if ($action === 'auto') {
            return $this->handleAuto($awningAuto);
        }

        try {
            $executionId = $tahoma->controlAwning($action);
            $awningAuto->recordManualAction($action);
        } catch (Throwable $exception) {
            report($exception);
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

    protected function handleAuto(AwningAutoService $awningAuto): int
    {
        try {
            $result = $awningAuto->run();
        } catch (Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $decision = $result->decision;
        $weather = $result->weather;

        $this->line(sprintf(
            'Weather: %.1f°C, SW facade %.0f W/m² (~%.0f W gain), wind %.0f km/h (gusts %.0f), precip %.1f mm.',
            $weather->temperatureC,
            $weather->facadeIrradianceWm2,
            $weather->estimatedHeatGainW,
            $weather->windSpeedKmh,
            $weather->windGustKmh,
            $weather->precipitationMm,
        ));

        if (! $result->commandSent()) {
            $position = $result->position !== null ? $result->position->value : 'unknown';
            $this->info(sprintf('Holding (%s). %s', $position, $decision->reason));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Awning %s command sent (execution: %s). %s',
            $decision->action->value,
            $result->executionId,
            $decision->reason,
        ));

        return self::SUCCESS;
    }
}
