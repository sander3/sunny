<?php

declare(strict_types=1);

namespace App\Services;

use App\Awning\AwningAutoAction;
use App\Awning\AwningPolicy;
use App\Awning\AwningRunResult;
use App\Awning\AwningState;
use App\Awning\AwningStateRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

class AwningAutoService
{
    public function __construct(
        protected OpenMeteoService $openMeteo,
        protected TaHomaService $tahoma,
        protected AwningPolicy $policy,
        protected AwningStateRepository $stateRepository,
    ) {}

    public function run(): AwningRunResult
    {
        $weather = $this->openMeteo->currentForAwning();
        $state = $this->reconciledState();
        $decision = $this->policy->decide($weather, $state, CarbonImmutable::now());

        Log::info('Awning decision', [
            'action' => $decision->action->value,
            'reason' => $decision->reason,
            'position' => $state->position?->value,
            'temperature_c' => $weather->temperatureC,
            'heat_gain_w' => $weather->estimatedHeatGainW,
            'wind_kmh' => $weather->windSpeedKmh,
            'gust_kmh' => $weather->windGustKmh,
            'precip_mm' => $weather->precipitationMm,
        ]);

        if (! $decision->shouldCommand()) {
            return new AwningRunResult($decision, $weather, position: $state->position);
        }

        $executionId = $this->tahoma->controlAwning($decision->action->tahomaCommand());
        $windClose = $decision->bypassesBackoff && $decision->action === AwningAutoAction::Retract;

        $this->stateRepository->record($decision->action, $decision->reason, windClosed: $windClose);

        $context = ['action' => $decision->action->value, 'reason' => $decision->reason, 'execution_id' => $executionId];

        if ($windClose) {
            Log::warning('Awning safety retract executed', $context);
        } else {
            Log::info('Awning command executed', $context);
        }

        return new AwningRunResult($decision, $weather, executionId: $executionId, position: $decision->action);
    }

    public function recordManualAction(string $tahomaCommand, string $reason = 'Manual command'): void
    {
        $this->stateRepository->record(
            AwningAutoAction::fromTahomaCommand($tahomaCommand),
            $reason,
            windClosed: false,
        );
    }

    /**
     * Trust the box over our cache: if the awning was moved with the physical
     * remote or Somfy app, adopt its real position. An override we can date
     * against a position we already knew earns the normal backoff, so the next
     * minute's tick cannot immediately undo what somebody just did by hand. A
     * cold cache has nothing to compare against, so it adopts without a lock.
     */
    protected function reconciledState(): AwningState
    {
        $state = $this->stateRepository->get();

        try {
            $actual = $this->tahoma->awningPosition();
        } catch (Throwable $exception) {
            Log::warning('Awning position reconciliation failed; using cached state.', [
                'error' => $exception->getMessage(),
            ]);

            return $state;
        }

        if ($actual === null || $actual === $state->position) {
            return $state;
        }

        $wasOverridden = $state->position !== null;

        Log::info('Awning position reconciled from TaHoma', [
            'cached' => $state->position?->value,
            'actual' => $actual->value,
            'overridden' => $wasOverridden,
        ]);

        $reconciled = new AwningState(
            position: $actual,
            changedAt: $wasOverridden ? CarbonImmutable::now() : null,
            reason: $wasOverridden ? 'Moved outside automation' : 'Reconciled from TaHoma',
        );

        $this->stateRepository->save($reconciled);

        return $reconciled;
    }
}
