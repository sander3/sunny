<?php

declare(strict_types=1);

namespace App\Awning;

use Carbon\CarbonImmutable;

/**
 * Pure decision policy for the awning. Given a weather snapshot, the last known
 * state and the current time it returns the action to take. It has no framework
 * dependencies and performs no I/O, which keeps it trivial to unit test.
 */
class AwningPolicy
{
    public function __construct(private readonly AwningThresholds $thresholds) {}

    public function decide(WeatherSnapshot $weather, AwningState $state, CarbonImmutable $now): AwningDecision
    {
        $unsafe = $this->unsafeCondition($weather, $now);

        if ($unsafe !== null) {
            if ($state->isAt(AwningAutoAction::Retract)) {
                return new AwningDecision(
                    AwningAutoAction::Hold,
                    "Already retracted ({$unsafe}).",
                    bypassesBackoff: true,
                );
            }

            return new AwningDecision(
                AwningAutoAction::Retract,
                "Safety retract: {$unsafe}.",
                bypassesBackoff: true,
            );
        }

        $desired = $this->desiredComfortAction($weather);

        if ($desired === AwningAutoAction::Hold) {
            return new AwningDecision(AwningAutoAction::Hold, $this->holdReason($weather));
        }

        if ($state->isAt($desired)) {
            return new AwningDecision(
                AwningAutoAction::Hold,
                sprintf('Already %s (%s).', $desired->pastParticiple(), $this->comfortReason($desired, $weather)),
            );
        }

        if ($desired === AwningAutoAction::Extend && ! $this->windIsClear($weather)) {
            return new AwningDecision(
                AwningAutoAction::Hold,
                sprintf(
                    'Shade wanted but wind still elevated (%.0f km/h, gusts %.0f km/h).',
                    $weather->windSpeedKmh,
                    $weather->windGustKmh,
                ),
            );
        }

        $remaining = $this->backoffMinutesRemaining($desired, $state, $now);

        if ($remaining > 0) {
            return new AwningDecision(
                AwningAutoAction::Hold,
                sprintf(
                    'Backoff active (%d min left); would %s (%s).',
                    $remaining,
                    $desired->value,
                    $this->comfortReason($desired, $weather),
                ),
            );
        }

        return new AwningDecision($desired, $this->comfortReason($desired, $weather));
    }

    private function desiredComfortAction(WeatherSnapshot $weather): AwningAutoAction
    {
        if (! $weather->isDay) {
            return AwningAutoAction::Retract;
        }

        if ($this->shouldExtendForHeat($weather)) {
            return AwningAutoAction::Extend;
        }

        if ($this->shouldRetractForComfort($weather)) {
            return AwningAutoAction::Retract;
        }

        return AwningAutoAction::Hold;
    }

    private function shouldExtendForHeat(WeatherSnapshot $weather): bool
    {
        $temperature = max($weather->temperatureC, $weather->apparentTemperatureC);

        return $temperature >= $this->thresholds->openTemperatureC
            && $weather->estimatedHeatGainW >= $this->thresholds->openHeatGainW;
    }

    private function shouldRetractForComfort(WeatherSnapshot $weather): bool
    {
        $temperature = max($weather->temperatureC, $weather->apparentTemperatureC);

        return $temperature < $this->thresholds->closeTemperatureC
            || $weather->estimatedHeatGainW <= $this->thresholds->closeHeatGainW;
    }

    /**
     * Live readings retract on sight. Forecast points are graded by lead time:
     * a squall counts anywhere in the horizon, while a gust merely at the
     * awning's own limit has to be close enough that the model is likely right
     * about it, since retracting costs the rest of the recovery cycle too.
     */
    private function unsafeCondition(WeatherSnapshot $weather, CarbonImmutable $now): ?string
    {
        $maxSpeed = $this->thresholds->maxWindSpeedKmh;
        $maxGust = $this->thresholds->maxWindGustKmh;
        $maxPrecip = $this->thresholds->maxPrecipitationMm;
        $windHorizon = $now->addMinutes($this->thresholds->windLookaheadMinutes);
        $precipHorizon = $now->addMinutes($this->thresholds->precipitationLookaheadMinutes);

        if ($weather->windGustKmh >= $maxGust) {
            return sprintf('gusts %.0f km/h exceed %.0f km/h', $weather->windGustKmh, $maxGust);
        }

        if ($weather->windSpeedKmh >= $maxSpeed) {
            return sprintf('wind %.0f km/h exceeds %.0f km/h', $weather->windSpeedKmh, $maxSpeed);
        }

        if ($weather->precipitationMm >= $maxPrecip) {
            return sprintf('precipitation %.1f mm', $weather->precipitationMm);
        }

        if ($this->thresholds->isSevereWeatherCode($weather->weatherCode)) {
            return sprintf('severe weather code %d', $weather->weatherCode);
        }

        foreach ($weather->forecast as $point) {
            $at = $point['at'];

            if ($point['wind_gust_kmh'] >= $this->thresholds->squallGustKmh) {
                return sprintf('squall forecast, gusts %.0f km/h at %s', $point['wind_gust_kmh'], $at->format('H:i'));
            }

            if (! $at->isAfter($windHorizon)) {
                if ($point['wind_gust_kmh'] >= $maxGust) {
                    return sprintf('forecast gusts %.0f km/h at %s', $point['wind_gust_kmh'], $at->format('H:i'));
                }

                if ($point['wind_speed_kmh'] >= $maxSpeed) {
                    return sprintf('forecast wind %.0f km/h at %s', $point['wind_speed_kmh'], $at->format('H:i'));
                }
            }

            if ($point['precipitation_mm'] >= $maxPrecip && ! $at->isAfter($precipHorizon)) {
                return sprintf('forecast precipitation %.1f mm at %s', $point['precipitation_mm'], $at->format('H:i'));
            }
        }

        return null;
    }

    private function windIsClear(WeatherSnapshot $weather): bool
    {
        return $weather->windSpeedKmh < $this->thresholds->clearWindSpeedKmh
            && $weather->windGustKmh < $this->thresholds->clearWindGustKmh;
    }

    /**
     * Comfort moves wait for the full backoff. Re-extending after a wind safety
     * retract uses a shorter recovery window so the room does not cook, while
     * still avoiding rapid flap cycles.
     */
    private function backoffMinutesRemaining(AwningAutoAction $desired, AwningState $state, CarbonImmutable $now): int
    {
        if ($state->changedAt === null) {
            return 0;
        }

        $minutes = $this->thresholds->minStateChangeMinutes;

        if ($desired === AwningAutoAction::Extend && $this->windCloseIsRecent($state, $now)) {
            $minutes = $this->thresholds->windRecoveryMinutes;
        }

        $unlocksAt = $state->changedAt->addMinutes($minutes);

        if (! $unlocksAt->isAfter($now)) {
            return 0;
        }

        return max(1, (int) ceil(($unlocksAt->getTimestamp() - $now->getTimestamp()) / 60));
    }

    /**
     * The shortcut only ever shortens the comfort backoff, so a marker older
     * than that window is spent. Without this a wind retract on a blustery
     * evening would still be granting fast reopens the next morning.
     */
    private function windCloseIsRecent(AwningState $state, CarbonImmutable $now): bool
    {
        if ($state->windClosedAt === null) {
            return false;
        }

        return $state->windClosedAt
            ->addMinutes($this->thresholds->minStateChangeMinutes)
            ->isAfter($now);
    }

    private function comfortReason(AwningAutoAction $action, WeatherSnapshot $weather): string
    {
        return sprintf(
            '%s for %.0f W facade gain (%.0f W/m² on SW glass), %.1f°C',
            $action === AwningAutoAction::Extend ? 'extend' : 'retract',
            $weather->estimatedHeatGainW,
            $weather->facadeIrradianceWm2,
            $weather->temperatureC,
        );
    }

    private function holdReason(WeatherSnapshot $weather): string
    {
        return sprintf(
            'Conditions steady (%.0f W gain, %.1f°C, wind %.0f km/h).',
            $weather->estimatedHeatGainW,
            $weather->temperatureC,
            $weather->windSpeedKmh,
        );
    }
}
