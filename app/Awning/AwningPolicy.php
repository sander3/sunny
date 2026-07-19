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
        $unsafe = $this->unsafeCondition($weather);

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
            && $weather->facadeIrradianceWm2 >= $this->thresholds->openFacadeIrradianceWm2
            && $weather->estimatedHeatGainW >= $this->thresholds->openHeatGainW;
    }

    private function shouldRetractForComfort(WeatherSnapshot $weather): bool
    {
        $temperature = max($weather->temperatureC, $weather->apparentTemperatureC);

        return $temperature < $this->thresholds->closeTemperatureC
            || $weather->facadeIrradianceWm2 <= $this->thresholds->closeFacadeIrradianceWm2
            || $weather->estimatedHeatGainW <= $this->thresholds->closeHeatGainW;
    }

    private function unsafeCondition(WeatherSnapshot $weather): ?string
    {
        $maxSpeed = $this->thresholds->maxWindSpeedKmh;
        $maxGust = $this->thresholds->maxWindGustKmh;
        $maxPrecip = $this->thresholds->maxPrecipitationMm;

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
            if ($point['wind_gust_kmh'] >= $maxGust) {
                return sprintf('forecast gusts %.0f km/h at %s', $point['wind_gust_kmh'], $point['at']->format('H:i'));
            }

            if ($point['wind_speed_kmh'] >= $maxSpeed) {
                return sprintf('forecast wind %.0f km/h at %s', $point['wind_speed_kmh'], $point['at']->format('H:i'));
            }

            if ($point['precipitation_mm'] >= $maxPrecip) {
                return sprintf('forecast precipitation %.1f mm at %s', $point['precipitation_mm'], $point['at']->format('H:i'));
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

        if ($desired === AwningAutoAction::Extend && $state->windClosedAt !== null) {
            $minutes = $this->thresholds->windRecoveryMinutes;
        }

        $unlocksAt = $state->changedAt->addMinutes($minutes);

        if (! $unlocksAt->isAfter($now)) {
            return 0;
        }

        return max(1, (int) ceil(($unlocksAt->getTimestamp() - $now->getTimestamp()) / 60));
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
